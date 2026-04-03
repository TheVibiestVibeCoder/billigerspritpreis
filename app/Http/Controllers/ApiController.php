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
        $ttl = (int) config('services.econtrol.cache_ttl', 1200);

        $payload = $compareScope === 'austria'
            ? $this->buildAustriaPayload($eControlService, $priceColorService, $fuel, $bounds, $includeClosed, $ttl)
            : $this->buildViewportPayload($eControlService, $priceColorService, $fuel, $bounds, $includeClosed, $ttl);

        // 60-second browser/CDN cache for austria scope (same data for all viewports);
        // viewport scope varies per bounds so we keep it short but still cacheable.
        $maxAge = $compareScope === 'austria' ? 60 : 30;
        $noindex = (string) config('seo.robots.noindex', 'noindex, nofollow, noarchive');

        return response()->json($payload)
            ->header('Cache-Control', "public, max-age={$maxAge}")
            ->header('Vary', 'Accept')
            ->header('X-Robots-Tag', $noindex);
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

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=21600, stale-while-revalidate=86400')
            ->header('Vary', 'Accept')
            ->header('X-Robots-Tag', (string) config('seo.robots.noindex', 'noindex, nofollow, noarchive'));
    }

    /**
     * Austria scope: cache the full colored station set under a single key (no bounds).
     * All viewports share this one entry; bounds filtering happens in-memory (~470 items).
     * This avoids recomputing price tiers for every unique viewport combination.
     */
    private function buildAustriaPayload(
        EControlService $eControlService,
        PriceColorService $priceColorService,
        string $fuel,
        array $bounds,
        bool $includeClosed,
        int $ttl,
    ): array {
        $cacheKey = sprintf('stations:austria_colored:%s:%s:v1', $fuel, $includeClosed ? '1' : '0');

        $coloredAll = Cache::get($cacheKey);

        if (! is_array($coloredAll) || count($coloredAll) === 0) {
            Cache::forget($cacheKey);

            $allStations = $eControlService
                ->getStationsForBounds($this->defaultBounds(), $fuel, $includeClosed)
                ->map(fn (array $s) => $this->mapStationPayload($s, $fuel))
                ->values();

            if ($allStations->isNotEmpty()) {
                $tieredById = $priceColorService->applyTiers($allStations, 'price')->keyBy('id');

                $coloredAll = $allStations->map(function (array $station) use ($tieredById): array {
                    $tiered = $tieredById->get($station['id']);
                    $station['price_tier'] = $tiered['price_tier'] ?? 3;
                    $station['price_color'] = $tiered['price_color'] ?? '#EAB308';
                    $station['cheaper_than_percent'] = $tiered['cheaper_than_percent'] ?? null;

                    return $station;
                })->values()->all();

                Cache::put($cacheKey, $coloredAll, now()->addSeconds($ttl));
            } else {
                $coloredAll = [];
            }
        }

        // Filter to the visible viewport entirely in-memory — no extra DB or cache hit.
        $visible = array_values(array_filter(
            $coloredAll,
            fn (array $s) => $s['latitude'] >= $bounds['south']
                && $s['latitude'] <= $bounds['north']
                && $s['longitude'] >= $bounds['west']
                && $s['longitude'] <= $bounds['east'],
        ));

        return [
            'fuel' => $fuel,
            'comparison_scope' => 'austria',
            'count' => count($visible),
            'stations' => $visible,
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'bounds' => $bounds,
                'comparison_scope' => 'austria',
                'comparison_count' => count($coloredAll),
            ],
        ];
    }

    /**
     * Viewport scope: cache per bounds, but never store an empty result so a
     * failed API fetch does not lock out retries for the full TTL.
     */
    private function buildViewportPayload(
        EControlService $eControlService,
        PriceColorService $priceColorService,
        string $fuel,
        array $bounds,
        bool $includeClosed,
        int $ttl,
    ): array {
        $cacheKey = sprintf(
            'stations:processed:%s:%s:%s:%0.3f:%0.3f:%0.3f:%0.3f:v2',
            $fuel,
            'viewport',
            $includeClosed ? '1' : '0',
            $bounds['south'],
            $bounds['west'],
            $bounds['north'],
            $bounds['east'],
        );

        $payload = Cache::get($cacheKey);
        $isValid = is_array($payload)
            && is_array($payload['stations'] ?? null)
            && count($payload['stations']) > 0;

        if (! $isValid) {
            Cache::forget($cacheKey);

            $visibleStations = $eControlService
                ->getStationsForBounds($bounds, $fuel, $includeClosed)
                ->map(fn (array $s) => $this->mapStationPayload($s, $fuel))
                ->values();

            $tieredById = $priceColorService->applyTiers($visibleStations, 'price')->keyBy('id');

            $stations = $visibleStations->map(function (array $station) use ($tieredById): array {
                $tiered = $tieredById->get($station['id']);

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
            })->values()->all();

            $payload = [
                'fuel' => $fuel,
                'comparison_scope' => 'viewport',
                'count' => count($stations),
                'stations' => $stations,
                'meta' => [
                    'generated_at' => now()->toIso8601String(),
                    'bounds' => $bounds,
                    'comparison_scope' => 'viewport',
                    'comparison_count' => count($stations),
                ],
            ];

            if (count($stations) > 0) {
                Cache::put($cacheKey, $payload, now()->addSeconds($ttl));
            }
        }

        return $payload;
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
