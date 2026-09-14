<?php

namespace App\Geo\Support;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Coordinate helpers. Deliberately dependency free — no PostGIS, no spatial
 * columns, so the feature works on the MySQL and Postgres schemas upstream
 * already supports.
 */
class Coordinates
{
    /** Mean earth radius, km. */
    const EARTH_RADIUS_KM = 6371.0088;

    /** One degree of latitude, km. Constant enough for our purposes. */
    const KM_PER_DEGREE_LAT = 110.574;

    public static function isValidLat($lat): bool
    {
        return is_numeric($lat) && $lat >= -90 && $lat <= 90;
    }

    public static function isValidLng($lng): bool
    {
        return is_numeric($lng) && $lng >= -180 && $lng <= 180;
    }

    public static function isValid($lat, $lng): bool
    {
        if (! self::isValidLat($lat) || ! self::isValidLng($lng)) {
            return false;
        }

        // Null Island. Cameras that fail to get a fix write 0/0 rather than
        // omitting the tags, and it is not a location anybody photographs.
        return ! (abs((float) $lat) < 0.0001 && abs((float) $lng) < 0.0001);
    }

    /**
     * Great circle distance in kilometres.
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * A latitude/longitude box that fully contains the given radius.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} [minLat, minLng, maxLat, maxLng]
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / self::KM_PER_DEGREE_LAT;

        // Longitude degrees shrink towards the poles. Clamp the cosine so we
        // do not divide by ~zero above 89 degrees.
        $cos = max(0.01, cos(deg2rad($lat)));
        $lngDelta = $radiusKm / (self::KM_PER_DEGREE_LAT * $cos);

        return [
            max(-90.0, $lat - $latDelta),
            max(-180.0, $lng - $lngDelta),
            min(90.0, $lat + $latDelta),
            min(180.0, $lng + $lngDelta),
        ];
    }

    /**
     * Grid cell size in degrees for a slippy-map zoom level.
     *
     * Each zoom level halves the cell, so a cluster covers roughly the same
     * number of screen pixels at every zoom.
     */
    public static function clusterCellSize(int $zoom): float
    {
        $zoom = max(0, min(20, $zoom));

        return 360.0 / (2 ** ($zoom + 2));
    }

    /**
     * Round coordinates to a fixed precision, e.g. for cache keys or for
     * coarsening a position before it is stored.
     */
    public static function round(float $value, int $decimals = 4): float
    {
        return round($value, $decimals);
    }

    /**
     * A coordinate pair somebody pasted: "51.5074, -0.1278".
     *
     * Somebody fixing a bad GPS read often has the real position to hand
     * already — a right click in another map gives them exactly this string —
     * and pasting it is both faster and more accurate than dragging.
     *
     * A comma or whitespace separates the two. Anything else is not a
     * coordinate pair: a place name, half a pair, an out of range value or
     * Null Island all return null, so the caller can fall through to
     * searching for the text instead. Degrees and minutes are not accepted;
     * decimal degrees is what every map copies out.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function parsePair(string $value): ?array
    {
        $pattern = '/^(-?\d{1,3}(?:\.\d+)?)\s*(?:,\s*|\s+)(-?\d{1,3}(?:\.\d+)?)$/';

        if (! preg_match($pattern, trim($value), $parts)) {
            return null;
        }

        $lat = (float) $parts[1];
        $lng = (float) $parts[2];

        return self::isValid($lat, $lng) ? [$lat, $lng] : null;
    }
}
