<?php

namespace App\Services;

use Illuminate\Support\Collection;

class PriceColorService
{
    public const COLOR_MAP = [
        1 => '#22C55E',
        2 => '#84CC16',
        3 => '#EAB308',
        4 => '#F97316',
        5 => '#DC2626',
    ];

    public const TIER_LABELS = [
        1 => 'Sehr guenstig',
        2 => 'Unterdurchschnittlich guenstig',
        3 => 'Durchschnittspreis',
        4 => 'Unterdurchschnittlich teuer',
        5 => 'Sehr teuer',
    ];

    public function applyTiers(Collection $stations, string $priceKey = 'price'): Collection
    {
        $prices = $stations
            ->pluck($priceKey)
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (float) $value)
            ->values();

        if ($prices->isEmpty()) {
            return $stations->map(function (array $station): array {
                $station['price_tier'] = null;
                $station['price_color'] = self::COLOR_MAP[3];
                $station['price_tier_label'] = self::TIER_LABELS[3];
                $station['cheaper_than_percent'] = null;

                return $station;
            });
        }

        $min = $prices->min();
        $max = $prices->max();
        $range = $max - $min;
        $denominator = max(1, $prices->count() - 1);

        return $stations->map(function (array $station) use ($priceKey, $min, $range, $prices, $denominator): array {
            $price = $station[$priceKey] ?? null;

            if (! is_numeric($price)) {
                $station['price_tier'] = null;
                $station['price_color'] = self::COLOR_MAP[3];
                $station['price_tier_label'] = self::TIER_LABELS[3];
                $station['cheaper_than_percent'] = null;

                return $station;
            }

            $price = (float) $price;

            if ($range <= 0.0) {
                $tier = 3;
            } else {
                $normalized = ($price - $min) / $range;
                $tier = (int) ceil($normalized * 5);
                $tier = max(1, min(5, $tier));
            }

            $higherCount = $prices->filter(fn (float $candidate) => $candidate > $price)->count();
            $cheaperThanPercent = (int) round(($higherCount / $denominator) * 100);

            $station['price_tier'] = $tier;
            $station['price_color'] = self::COLOR_MAP[$tier];
            $station['price_tier_label'] = self::TIER_LABELS[$tier];
            $station['cheaper_than_percent'] = max(0, min(100, $cheaperThanPercent));

            return $station;
        });
    }
}
