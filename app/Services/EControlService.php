<?php

namespace App\Services;

use App\Models\GasStation;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EControlService
{
    private const WORKING_BASE_CACHE_KEY = 'econtrol:working_base_url:v1';

    public function __construct(
        private readonly PriceColorService $priceColorService,
    ) {}

    public function getStationsForBounds(array $bounds, string $fuel = 'DIE', bool $includeClosed = false): Collection
    {
        $fuel = strtoupper($fuel);

        if (! in_array($fuel, ['DIE', 'SUP'], true)) {
            $fuel = 'DIE';
        }

        $stations = $this->getAllStationsForFuel($fuel, $includeClosed);

        if (! $includeClosed) {
            $stations = $stations->filter(fn (array $station) => (bool) ($station['is_open'] ?? false));
        }

        return $this->filterByBounds($stations, $bounds)->values();
    }

    public function warmUp(bool $includeClosed = false): void
    {
        $this->getAllStationsForFuel('DIE', $includeClosed);
        $this->getAllStationsForFuel('SUP', $includeClosed);
    }

    private function getAllStationsForFuel(string $fuel, bool $includeClosed): Collection
    {
        $ttl = (int) config('services.econtrol.cache_ttl', 900);
        $cacheKey = sprintf('econtrol:fuel:%s:closed:%s:v3', $fuel, $includeClosed ? '1' : '0');

        $payload = Cache::get($cacheKey);

        if (! is_array($payload)) {
            if ($payload !== null) {
                Cache::forget($cacheKey);
            }

            $payload = Cache::remember($cacheKey, now()->addSeconds($ttl), function () use ($fuel, $includeClosed) {
                $regions = $this->getRegions();

                if ($regions->isEmpty()) {
                    $stations = $this->normalizeStations($this->fallbackByAddress($fuel, $includeClosed));
                } else {
                    $stations = $this->fetchByRegion($regions, $fuel, $includeClosed);
                }

                $stations = $this->hydrateSelectedPrice($stations, $fuel)
                    ->filter(fn (array $station) => is_numeric($station['price'] ?? null))
                    ->values();

                $this->persistStations($stations, $fuel);

                return $stations->all();
            });
        }

        return collect($payload);
    }

    private function getRegions(): Collection
    {
        $cacheKey = 'econtrol:regions:bezirke:v1';
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return collect($cached);
        }

        if ($cached !== null) {
            Cache::forget($cacheKey);
        }

        foreach ($this->candidateBaseUrls() as $baseUrl) {
            try {
                $response = $this->client($baseUrl)->get('regions');
                if ($response->successful()) {
                    $bundeslaender = collect($this->extractRegionsPayload($response->json()));
                    $bezirkeFromSubRegions = $bundeslaender
                        ->flatMap(function ($bundesland) {
                            return collect(data_get($bundesland, 'subRegions', []))
                                ->map(fn ($bezirk) => [
                                    'code' => (string) data_get($bezirk, 'code'),
                                    'type' => $this->normalizeRegionType((string) data_get($bezirk, 'type', 'PB')),
                                    'name' => (string) data_get($bezirk, 'name', ''),
                                ]);
                        });

                    $bezirkeDirect = $bundeslaender
                        ->map(fn ($region) => [
                            'code' => (string) data_get($region, 'code'),
                            'type' => $this->normalizeRegionType((string) data_get($region, 'type', '')),
                            'name' => (string) data_get($region, 'name', ''),
                        ])
                        ->filter(fn (array $region) => $region['code'] !== '' && $region['type'] === 'PB');

                    $regions = $bezirkeFromSubRegions
                        ->merge($bezirkeDirect)
                        ->filter(fn (array $region) => $region['code'] !== '' && $region['type'] === 'PB')
                        ->unique(fn (array $region) => sprintf('%s:%s', $region['type'], $region['code']))
                        ->values();

                    if ($regions->isNotEmpty()) {
                        $this->rememberWorkingBaseUrl($baseUrl);
                        Cache::put($cacheKey, $regions->all(), now()->addDay());

                        return $regions;
                    }
                }
            } catch (\Throwable $exception) {
                Log::warning('Could not fetch regions endpoint', [
                    'base_url' => $baseUrl,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        Log::warning('No bezirke returned by regions endpoint; using static bundeslaender fallback');

        return collect(range(1, 9))->map(fn (int $code) => [
            'code' => (string) $code,
            'type' => 'BL',
            'name' => sprintf('Bundesland %d', $code),
        ])->values();
    }

    private function fetchByRegion(Collection $regions, string $fuel, bool $includeClosed): Collection
    {
        $regions = $regions
            ->map(fn (array $region) => [
                'code' => (string) ($region['code'] ?? ''),
                'type' => $this->normalizeRegionType((string) ($region['type'] ?? 'PB')),
                'name' => (string) ($region['name'] ?? ''),
            ])
            ->filter(fn (array $region) => $region['code'] !== '' && $region['type'] !== '')
            ->values();

        if ($regions->isEmpty()) {
            return $this->normalizeStations($this->fallbackByAddress($fuel, $includeClosed));
        }

        $stations = collect();

        foreach ($this->candidateBaseUrls() as $baseUrl) {
            $hadSuccessfulResponse = false;
            $endpoint = rtrim($baseUrl, '/').'/search/gas-stations/by-region';
            $timeout = (int) config('services.econtrol.timeout', 15);

            $responses = Http::pool(function (Pool $pool) use ($regions, $fuel, $includeClosed, $endpoint, $timeout) {
                $requests = [];

                foreach ($regions as $region) {
                    $requests[] = $pool->as((string) $region['code'])
                        ->acceptJson()
                        ->timeout($timeout)
                        ->get($endpoint, [
                            'code' => $region['code'],
                            'type' => strtoupper((string) ($region['type'] ?? 'PB')),
                            'fuelType' => $fuel,
                            'includeClosed' => $includeClosed ? 'true' : 'false',
                        ]);
                }

                return $requests;
            });

            foreach ($responses as $regionCode => $response) {
                if ($response instanceof \Throwable) {
                    Log::warning('Region request failed in pool', [
                        'base_url' => $baseUrl,
                        'region' => (string) $regionCode,
                        'message' => $response->getMessage(),
                    ]);

                    continue;
                }

                if (! $response instanceof HttpResponse) {
                    continue;
                }

                if (! $response->successful()) {
                    continue;
                }

                $hadSuccessfulResponse = true;
                $stations = $stations->merge($this->extractStationsPayload($response->json()));
            }

            if ($hadSuccessfulResponse) {
                $this->rememberWorkingBaseUrl($baseUrl);
            }

            if ($stations->isNotEmpty()) {
                break;
            }
        }

        if ($stations->isEmpty()) {
            $stations = $this->fallbackByAddress($fuel, $includeClosed);
        }

        return $this->normalizeStations($stations);
    }

    private function fallbackByAddress(string $fuel, bool $includeClosed): Collection
    {
        foreach ($this->candidateBaseUrls() as $baseUrl) {
            try {
                $response = $this->client($baseUrl)->get('search/gas-stations/by-address', [
                    'latitude' => 47.8,
                    'longitude' => 13.3,
                    'fuelType' => $fuel,
                    'includeClosed' => $includeClosed ? 'true' : 'false',
                ]);

                if ($response->successful()) {
                    $this->rememberWorkingBaseUrl($baseUrl);

                    return collect($this->extractStationsPayload($response->json()));
                }
            } catch (\Throwable $exception) {
                Log::warning('Fallback by-address failed', [
                    'base_url' => $baseUrl,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return collect();
    }

    private function normalizeStations(Collection $rawStations): Collection
    {
        return $rawStations
            ->map(function ($station) {
                $id = data_get($station, 'id');
                $location = data_get($station, 'location', []);
                $address = data_get($station, 'address', []);

                $latitude = data_get($location, 'latitude', data_get($location, 'lat'));
                $longitude = data_get($location, 'longitude', data_get($location, 'lng', data_get($location, 'lon')));

                if (! is_numeric($id) || ! is_numeric($latitude) || ! is_numeric($longitude)) {
                    return null;
                }

                $diesel = $this->extractFuelPrice($station, 'DIE');
                $super = $this->extractFuelPrice($station, 'SUP');

                return [
                    'id' => (int) $id,
                    'name' => (string) data_get($station, 'name', 'Unbekannte Tankstelle'),
                    'street' => (string) data_get($address, 'street', data_get($location, 'address', '')),
                    'postal_code' => (string) data_get($address, 'postalCode', data_get($location, 'postalCode', '')),
                    'city' => (string) data_get($address, 'city', data_get($location, 'city', '')),
                    'latitude' => (float) $latitude,
                    'longitude' => (float) $longitude,
                    'is_open' => (bool) data_get($station, 'open', data_get($station, 'isOpen', true)),
                    'price_diesel' => $diesel,
                    'price_super' => $super,
                    'last_updated' => now()->toIso8601String(),
                ];
            })
            ->filter()
            ->groupBy('id')
            ->map(function (Collection $duplicates) {
                $base = $duplicates->first();

                $base['price_diesel'] = $duplicates
                    ->pluck('price_diesel')
                    ->first(fn ($value) => is_numeric($value));

                $base['price_super'] = $duplicates
                    ->pluck('price_super')
                    ->first(fn ($value) => is_numeric($value));

                return $base;
            })
            ->values();
    }

    private function extractFuelPrice(mixed $station, string $fuelType): ?float
    {
        $prices = collect(data_get($station, 'prices', []));

        $matched = $prices
            ->first(fn ($price) => strtoupper((string) data_get($price, 'fuelType')) === $fuelType);

        if ($matched !== null && is_numeric(data_get($matched, 'amount'))) {
            return round((float) data_get($matched, 'amount'), 3);
        }

        $fallbackCandidates = [
            data_get($station, strtolower($fuelType)),
            data_get($station, 'price_'.strtolower($fuelType)),
            data_get($station, 'price_'.($fuelType === 'DIE' ? 'diesel' : 'super')),
        ];

        foreach ($fallbackCandidates as $fallback) {
            if (is_numeric($fallback)) {
                return round((float) $fallback, 3);
            }
        }

        return null;
    }

    private function hydrateSelectedPrice(Collection $stations, string $fuel): Collection
    {
        return $stations->map(function (array $station) use ($fuel): array {
            $price = $fuel === 'SUP'
                ? $station['price_super']
                : $station['price_diesel'];

            $station['selected_fuel'] = $fuel;
            $station['price'] = is_numeric($price) ? round((float) $price, 3) : null;

            return $station;
        });
    }

    private function filterByBounds(Collection $stations, array $bounds): Collection
    {
        $south = (float) ($bounds['south'] ?? 46.0);
        $west = (float) ($bounds['west'] ?? 9.0);
        $north = (float) ($bounds['north'] ?? 49.2);
        $east = (float) ($bounds['east'] ?? 17.2);

        return $stations->filter(function (array $station) use ($south, $west, $north, $east): bool {
            $lat = (float) $station['latitude'];
            $lng = (float) $station['longitude'];

            return $lat >= $south
                && $lat <= $north
                && $lng >= $west
                && $lng <= $east;
        });
    }

    private function persistStations(Collection $stations, string $fuel): void
    {
        if ($stations->isEmpty()) {
            return;
        }

        $existingById = GasStation::query()
            ->whereIn('id', $stations->pluck('id')->all())
            ->get([
                'id',
                'price_diesel',
                'price_super',
                'price_tier_diesel',
                'price_tier_super',
            ])
            ->keyBy('id');

        $tiered = $this->priceColorService->applyTiers($stations, 'price')
            ->keyBy('id');

        $rows = $stations->map(function (array $station) use ($fuel, $tiered, $existingById): array {
            $tier = data_get($tiered->get($station['id']), 'price_tier');
            $existing = $existingById->get($station['id']);

            $priceDiesel = $station['price_diesel'];
            $priceSuper = $station['price_super'];

            if (! is_numeric($priceDiesel) && $existing && is_numeric($existing->price_diesel)) {
                $priceDiesel = (float) $existing->price_diesel;
            }

            if (! is_numeric($priceSuper) && $existing && is_numeric($existing->price_super)) {
                $priceSuper = (float) $existing->price_super;
            }

            return [
                'id' => $station['id'],
                'name' => $station['name'],
                'street' => $station['street'] ?: null,
                'postal_code' => $station['postal_code'] ?: null,
                'city' => $station['city'] ?: null,
                'latitude' => $station['latitude'],
                'longitude' => $station['longitude'],
                'is_open' => $station['is_open'],
                'price_diesel' => is_numeric($priceDiesel) ? round((float) $priceDiesel, 3) : null,
                'price_super' => is_numeric($priceSuper) ? round((float) $priceSuper, 3) : null,
                'price_tier_diesel' => $fuel === 'DIE'
                    ? $tier
                    : data_get($existing, 'price_tier_diesel'),
                'price_tier_super' => $fuel === 'SUP'
                    ? $tier
                    : data_get($existing, 'price_tier_super'),
                'last_updated' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ];
        })->values()->all();

        $updateColumns = [
            'name',
            'street',
            'postal_code',
            'city',
            'latitude',
            'longitude',
            'is_open',
            'price_diesel',
            'price_super',
            'price_tier_diesel',
            'price_tier_super',
            'last_updated',
            'updated_at',
        ];

        // SQLite has a 999 variable limit per query; 15 columns per row → max 66 rows per chunk
        foreach (array_chunk($rows, 60) as $chunk) {
            GasStation::upsert($chunk, ['id'], $updateColumns);
        }
    }

    private function client(?string $baseUrl = null)
    {
        $url = $baseUrl ?: (string) config('services.econtrol.base_url', 'https://api.e-control.at/api');

        return Http::baseUrl(rtrim($url, '/').'/')
            ->acceptJson()
            ->timeout((int) config('services.econtrol.timeout', 15))
            ->retry(2, 200);
    }

    /**
     * @return array<int, string>
     */
    private function candidateBaseUrls(): array
    {
        $configured = $this->normalizeBaseUrl((string) config('services.econtrol.base_url', 'https://api.e-control.at/api'));
        $fallback = $this->normalizeBaseUrl((string) config('services.econtrol.fallback_base_url', 'https://api.e-control.at/sprit/1.0'));
        $remembered = Cache::get(self::WORKING_BASE_CACHE_KEY);

        $urls = array_values(array_filter(array_unique([$configured, $fallback])));

        if (is_string($remembered)) {
            $remembered = $this->normalizeBaseUrl($remembered);
            if (in_array($remembered, $urls, true)) {
                usort($urls, fn (string $a, string $b): int => $a === $remembered ? -1 : ($b === $remembered ? 1 : 0));
            }
        }

        return $urls;
    }

    private function rememberWorkingBaseUrl(string $baseUrl): void
    {
        Cache::put(self::WORKING_BASE_CACHE_KEY, $this->normalizeBaseUrl($baseUrl), now()->addDay());
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');

        if ($trimmed === '') {
            return '';
        }

        if (Str::startsWith($trimmed, ['http://', 'https://'])) {
            return $trimmed;
        }

        return 'https://'.$trimmed;
    }

    private function extractStationsPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        $candidates = [
            data_get($payload, 'gasStations'),
            data_get($payload, 'stations'),
            data_get($payload, 'result'),
            data_get($payload, 'items'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function extractRegionsPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        $candidates = [
            data_get($payload, 'regions'),
            data_get($payload, 'bundeslaender'),
            data_get($payload, 'items'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function normalizeRegionType(string $rawType): string
    {
        $type = strtoupper(trim($rawType));

        if ($type === 'BL' || str_contains($type, 'BUNDESLAND')) {
            return 'BL';
        }

        return $type;
    }
}
