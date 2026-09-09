<?php

namespace App\Geo\Services;

use App\Geo\Support\Coordinates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Turns coordinates into the nearest known city.
 *
 * No network calls and no third party geocoder: the `places` table upstream
 * already ships (~128k cities, via `php artisan import:cities`) is the whole
 * dataset. That keeps the feature self hosted, keeps photo coordinates from
 * leaving the instance, and means the suggestion is a real `place_id` that
 * the existing location pages and `place` API field already understand.
 */
class ReverseGeocoder
{
    const CACHE_PREFIX = 'pf-geo:rgc:v1:';

    /** City data is static, so cache aggressively. */
    const CACHE_TTL = 2592000;

    /**
     * Search radii in km, tried in order until something is found. Starting
     * small keeps the common case — a photo taken in a town — cheap.
     *
     * @var list<int>
     */
    const SEARCH_RADII = [15, 50, 150, 500];

    /** Hard cap on rows pulled out of a bounding box. */
    const MAX_CANDIDATES = 2000;

    /**
     * The nearest place to a coordinate pair.
     *
     * @return array{id: int, name: string, slug: string, state: string|null, country: string, lat: float, lng: float, distance_km: float}|null
     */
    public function nearest(float $lat, float $lng, ?float $maxDistanceKm = null): ?array
    {
        $results = $this->nearby($lat, $lng, 1, $maxDistanceKm);

        return $results[0] ?? null;
    }

    /**
     * The closest places to a coordinate pair, nearest first.
     *
     * @return list<array{id: int, name: string, slug: string, state: string|null, country: string, lat: float, lng: float, distance_km: float}>
     */
    public function nearby(float $lat, float $lng, int $limit = 5, ?float $maxDistanceKm = null): array
    {
        if (! Coordinates::isValid($lat, $lng)) {
            return [];
        }

        $limit = max(1, min(25, $limit));

        // Round to ~11m so nearby photos share a cache entry.
        $key = self::CACHE_PREFIX.implode(':', [
            Coordinates::round($lat, 4),
            Coordinates::round($lng, 4),
            $limit,
        ]);

        $results = Cache::remember($key, self::CACHE_TTL, function () use ($lat, $lng, $limit) {
            return $this->query($lat, $lng, $limit);
        });

        if ($maxDistanceKm !== null) {
            $results = array_values(array_filter(
                $results,
                fn ($place) => $place['distance_km'] <= $maxDistanceKm
            ));
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function query(float $lat, float $lng, int $limit): array
    {
        // Not end(): that takes its argument by reference, which a class
        // constant cannot satisfy.
        $lastRadius = self::SEARCH_RADII[count(self::SEARCH_RADII) - 1];

        foreach (self::SEARCH_RADII as $radiusKm) {
            $candidates = $this->candidatesWithin($lat, $lng, $radiusKm);

            if ($candidates->isEmpty()) {
                continue;
            }

            $ranked = $candidates
                ->each(function ($place) use ($lat, $lng) {
                    $place->distance_km = round(Coordinates::distance(
                        $lat,
                        $lng,
                        (float) $place->lat,
                        (float) $place->long
                    ), 3);
                })
                ->sort(function ($a, $b) {
                    // Distance decides, but where two cities are within a
                    // kilometre of each other prefer the better known one.
                    if (abs($a->distance_km - $b->distance_km) < 1.0) {
                        $byScore = (int) $b->score <=> (int) $a->score;

                        return $byScore !== 0 ? $byScore : $a->distance_km <=> $b->distance_km;
                    }

                    return $a->distance_km <=> $b->distance_km;
                })
                ->take($limit)
                ->map(fn ($place) => [
                    'id' => (int) $place->id,
                    'name' => $place->name,
                    'slug' => $place->slug,
                    'state' => $place->state,
                    'country' => $place->country,
                    'lat' => (float) $place->lat,
                    'lng' => (float) $place->long,
                    'distance_km' => $place->distance_km,
                ])
                ->values()
                ->all();

            if (empty($ranked)) {
                continue;
            }

            // A bounding box is a superset of its radius, so at the corners it
            // reaches ~1.4x further than the sides. A hit beyond the radius
            // may therefore not be the true nearest — widen and look again,
            // unless this was already the widest search.
            if ($ranked[0]['distance_km'] > $radiusKm && $radiusKm !== $lastRadius) {
                continue;
            }

            return $ranked;
        }

        return [];
    }

    protected function candidatesWithin(float $lat, float $lng, float $radiusKm)
    {
        [$minLat, $minLng, $maxLat, $maxLng] = Coordinates::boundingBox($lat, $lng, $radiusKm);

        $query = DB::table('places')
            ->select('id', 'name', 'slug', 'state', 'country', 'lat', 'long', 'score')
            ->whereBetween('lat', [$minLat, $maxLat]);

        // A box straddling the antimeridian becomes two boxes.
        if ($minLng > $maxLng) {
            $query->where(function ($q) use ($minLng, $maxLng) {
                $q->where('long', '>=', $minLng)->orWhere('long', '<=', $maxLng);
            });
        } else {
            $query->whereBetween('long', [$minLng, $maxLng]);
        }

        return $query->limit(self::MAX_CANDIDATES)->get();
    }
}
