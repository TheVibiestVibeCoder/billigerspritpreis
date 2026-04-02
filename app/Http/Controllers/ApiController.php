<?php

namespace App\Http\Controllers;

use App\Services\EControlService;
use App\Services\PriceColorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ApiController extends Controller
{
    public function stations(
        Request $request,
        EControlService $eControlService,
        PriceColorService $priceColorService,
    ): JsonResponse {
        $validated = $request->validate([
            'fuel' => ['nullable', Rule::in(['DIE', 'SUP'])],
            'bounds' => ['nullable', 'string'],
            'includeClosed' => ['nullable', 'string'],
            'compareScope' => ['nullable', 'string'],
            'comparisonScope' => ['nullable', 'string'],
        ]);

        $fuel = strtoupper((string) ($validated['fuel'] ?? 'DIE'));
        $bounds = $this->parseBounds($validated['bounds'] ?? null);
        $compareScope = $this->parseComparisonScope(
            $validated['compareScope'] ?? $validated['comparisonScope'] ?? null,
        );
        $includeClosed = filter_var($request->query('includeClosed', false), FILTER_VALIDATE_BOOL);

        $cacheKey = sprintf(
            'stations:processed:%s:%s:%s:%0.3f:%0.3f:%0.3f:%0.3f:v2',
            $fuel,
            $compareScope,
            $includeClosed ? '1' : '0',
            $bounds['south'],
            $bounds['west'],
            $bounds['north'],
            $bounds['east'],
        );

        $ttl = (int) config('services.econtrol.cache_ttl', 900);

        $payload = Cache::get($cacheKey);

        $isValidPayload = is_array($payload)
            && is_array($payload['stations'] ?? null);

        if (! $isValidPayload) {
            Cache::forget($cacheKey);

            $payload = Cache::remember($cacheKey, now()->addSeconds($ttl), function () use (
                $eControlService,
                $priceColorService,
                $bounds,
                $fuel,
                $compareScope,
                $includeClosed,
            ) {
                $visibleStations = $eControlService->getStationsForBounds($bounds, $fuel, $includeClosed)
                    ->map(fn (array $station) => $this->mapStationPayload($station, $fuel))
                    ->values();

                $comparisonStations = $compareScope === 'austria'
                    ? $eControlService->getStationsForBounds($this->defaultBounds(), $fuel, $includeClosed)
                        ->map(fn (array $station) => $this->mapStationPayload($station, $fuel))
                        ->values()
                    : $visibleStations;

                $tieredComparisonById = $priceColorService
                    ->applyTiers($comparisonStations, 'price')
                    ->keyBy('id');

                $stations = $visibleStations
                    ->map(function (array $station) use ($tieredComparisonById): array {
                        $tiered = $tieredComparisonById->get($station['id']);

                        if (! is_array($tiered)) {
                            $station['price_tier'] = 3;
                            $station['price_color'] = '#EAB308';
                            $station['cheaper_than_percent'] = null;

                            return $station;
                        }

                        $station['price_tier'] = $tiered['price_tier'] ?? 3;
                        $station['price_color'] = $tiered['price_color'] ?? '#EAB308';
                        $station['cheaper_than_percent'] = $tiered['cheaper_than_percent'] ?? null;

                        return $station;
                    })
                    ->values()
                    ->all();

                return [
                    'fuel' => $fuel,
                    'comparison_scope' => $compareScope,
                    'count' => count($stations),
                    'stations' => $stations,
                    'meta' => [
                        'generated_at' => now()->toIso8601String(),
                        'bounds' => $bounds,
                        'comparison_scope' => $compareScope,
                        'comparison_count' => $comparisonStations->count(),
                    ],
                ];
            });
        }

        return response()->json($payload);
    }

    public function austriaBoundary(): JsonResponse
    {
        $cacheKey = 'geo:austria:boundary:v1';
        $payload = Cache::get($cacheKey);

        $isValidPayload = is_array($payload)
            && (($payload['type'] ?? null) === 'FeatureCollection')
            && is_array($payload['features'] ?? null);

        if (! $isValidPayload) {
            Cache::forget($cacheKey);

            $payload = Cache::remember($cacheKey, now()->addDay(), function () {
                $feature = $this->fetchAustriaBoundaryFromNominatim();

                if (! $feature) {
                    return [
                        'type' => 'FeatureCollection',
                        'features' => [$this->fallbackAustriaFeature()],
                    ];
                }

                return [
                    'type' => 'FeatureCollection',
                    'features' => [$feature],
                ];
            });
        }

        return response()->json($payload);
    }

    private function mapStationPayload(array $station, string $fuel): array
    {
        return [
            'id' => $station['id'],
            'name' => $station['name'],
            'street' => $station['street'],
            'postal_code' => $station['postal_code'],
            'city' => $station['city'],
            'latitude' => (float) $station['latitude'],
            'longitude' => (float) $station['longitude'],
            'is_open' => (bool) $station['is_open'],
            'price' => isset($station['price']) ? (float) $station['price'] : null,
            'price_diesel' => isset($station['price_diesel']) ? (float) $station['price_diesel'] : null,
            'price_super' => isset($station['price_super']) ? (float) $station['price_super'] : null,
            'selected_fuel' => $station['selected_fuel'] ?? $fuel,
            'last_updated' => $station['last_updated'] ?? null,
        ];
    }

    private function parseComparisonScope(mixed $rawScope): string
    {
        $scope = strtolower(trim((string) $rawScope));

        if (in_array($scope, ['austria', 'at', 'all_at', 'all-at', 'all'], true)) {
            return 'austria';
        }

        return 'viewport';
    }

    private function parseBounds(?string $bounds): array
    {
        if (! $bounds) {
            return $this->defaultBounds();
        }

        $segments = array_map('trim', explode(',', $bounds));

        if (count($segments) !== 4) {
            return $this->defaultBounds();
        }

        [$south, $west, $north, $east] = $segments;

        if (! is_numeric($south) || ! is_numeric($west) || ! is_numeric($north) || ! is_numeric($east)) {
            return $this->defaultBounds();
        }

        return [
            'south' => (float) $south,
            'west' => (float) $west,
            'north' => (float) $north,
            'east' => (float) $east,
        ];
    }

    private function defaultBounds(): array
    {
        return [
            'south' => 46.2,
            'west' => 9.3,
            'north' => 49.2,
            'east' => 17.3,
        ];
    }

    private function fetchAustriaBoundaryFromNominatim(): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->withHeaders([
                    'User-Agent' => $this->nominatimUserAgent(),
                ])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'country' => 'Austria',
                    'countrycodes' => 'at',
                    'format' => 'jsonv2',
                    'polygon_geojson' => 1,
                    'limit' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $items = $response->json();
            if (! is_array($items) || ! isset($items[0]['geojson']) || ! is_array($items[0]['geojson'])) {
                return null;
            }

            $geometry = $items[0]['geojson'];
            $geometryType = (string) ($geometry['type'] ?? '');

            if (! in_array($geometryType, ['Polygon', 'MultiPolygon'], true)) {
                return null;
            }

            return [
                'type' => 'Feature',
                'properties' => [
                    'name' => 'Austria',
                    'source' => 'nominatim',
                ],
                'geometry' => $geometry,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Could not fetch Austria boundary from Nominatim', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function nominatimUserAgent(): string
    {
        $appName = (string) config('app.name', 'Spritmap');
        $appUrl = (string) config('app.url', 'http://localhost');

        return sprintf('%s Boundary Fetcher (%s)', $appName, $appUrl);
    }

    private function fallbackAustriaFeature(): array
    {
        return [
            'type' => 'Feature',
            'properties' => [
                'name' => 'Austria',
                'source' => 'fallback',
            ],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [9.530, 47.270],
                    [9.560, 47.020],
                    [9.980, 46.830],
                    [10.460, 46.540],
                    [10.820, 46.530],
                    [11.200, 46.450],
                    [11.620, 46.530],
                    [12.200, 46.400],
                    [12.900, 46.500],
                    [13.390, 46.550],
                    [14.100, 46.630],
                    [14.560, 46.420],
                    [15.050, 46.670],
                    [15.780, 46.670],
                    [16.210, 46.890],
                    [16.980, 47.740],
                    [16.420, 48.600],
                    [16.040, 48.820],
                    [15.060, 49.020],
                    [14.480, 49.010],
                    [13.640, 48.780],
                    [13.030, 48.560],
                    [12.420, 48.310],
                    [11.430, 47.780],
                    [10.900, 47.600],
                    [10.390, 47.460],
                    [9.530, 47.270],
                ]],
            ],
        ];
    }
}
