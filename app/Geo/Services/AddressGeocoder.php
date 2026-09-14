<?php

namespace App\Geo\Services;

use App\Geo\Support\Coordinates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Text to coordinates, for the "edit location" box on the map.
 *
 * This is the opposite direction to ReverseGeocoder, and unlike that one it
 * can reach off the instance. Two drivers:
 *
 *   nominatim  OpenStreetMap's geocoder. Resolves street addresses, which
 *              the local table cannot. Default, because correcting a bad GPS
 *              read usually means naming somewhere smaller than a city.
 *   places     The `places` table, the same data the composer's location
 *              search uses. Towns and cities only, and nothing leaves the
 *              instance.
 *
 * A pasted coordinate pair short circuits both: it is already an answer.
 */
class AddressGeocoder
{
    const CACHE_PREFIX = 'pf-geo:geocode:v1:';

    /** Nominatim asks for no more than one request a second, per its usage policy. */
    const THROTTLE_KEY = 'pf-geo:geocode:nominatim:last';

    /**
     * @return list<array{label: string, lat: float, lng: float, source: string}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        // Already coordinates. No lookup, no round trip, no ambiguity.
        $pair = Coordinates::parsePair($query);

        if ($pair !== null) {
            return [[
                'label' => $this->formatPair($pair[0], $pair[1]),
                'lat' => $pair[0],
                'lng' => $pair[1],
                'source' => 'coordinates',
            ]];
        }

        return $this->driver() === 'nominatim'
            ? $this->viaNominatim($query, $limit)
            : $this->viaPlaces($query, $limit);
    }

    public function driver(): string
    {
        return config('geo.geocoder.driver') === 'nominatim' ? 'nominatim' : 'places';
    }

    /**
     * @return list<array{label: string, lat: float, lng: float, source: string}>
     */
    protected function viaNominatim(string $query, int $limit): array
    {
        // Results for a given string do not move. Caching them is worth more
        // here than usual: it is also most of the rate limiting.
        $key = self::CACHE_PREFIX.'n:'.md5(mb_strtolower($query).':'.$limit);

        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        $this->throttle();

        try {
            $response = Http::withHeaders([
                // Nominatim rejects requests without one, and asks that it
                // identify the application and a way to be contacted.
                'User-Agent' => $this->userAgent(),
            ])
                ->timeout((int) config('geo.geocoder.timeout', 6))
                ->get(rtrim((string) config('geo.geocoder.nominatim_url'), '/').'/search', [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'limit' => $limit,
                    'addressdetails' => 0,
                ]);
        } catch (\Throwable $e) {
            Log::warning('geo: address lookup failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('geo: address lookup rejected', ['status' => $response->status()]);

            return [];
        }

        $results = [];

        foreach ((array) $response->json() as $row) {
            if (! isset($row['lat'], $row['lon'], $row['display_name'])) {
                continue;
            }

            $lat = (float) $row['lat'];
            $lng = (float) $row['lon'];

            if (! Coordinates::isValid($lat, $lng)) {
                continue;
            }

            $results[] = [
                'label' => (string) $row['display_name'],
                'lat' => $lat,
                'lng' => $lng,
                'source' => 'nominatim',
            ];
        }

        // Cached whether or not it found anything: a second identical search
        // for a typo should not cost a second request.
        Cache::put($key, $results, (int) config('geo.geocoder.cache_ttl', 86400));

        return $results;
    }

    /**
     * @return list<array{label: string, lat: float, lng: float, source: string}>
     */
    protected function viaPlaces(string $query, int $limit): array
    {
        $country = null;

        // "Lyon, France" — the same shape upstream's location search accepts.
        if (str_contains($query, ',')) {
            [$query, $country] = array_map('trim', explode(',', $query, 2));
        }

        $rows = DB::table('places')
            ->select('name', 'country', 'lat', 'long')
            ->whereNotNull('lat')
            ->whereNotNull('long')
            // Prefix match: `name` is indexed, and a leading wildcard would
            // table scan 128k rows on every keystroke.
            ->where('name', 'like', $this->escapeLike($query).'%')
            ->when($country, fn ($q) => $q->where('country', 'like', $this->escapeLike($country).'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $results = [];

        foreach ($rows as $row) {
            if (! Coordinates::isValid($row->lat, $row->long)) {
                continue;
            }

            $results[] = [
                'label' => trim($row->name.', '.$row->country, ', '),
                'lat' => (float) $row->lat,
                'lng' => (float) $row->long,
                'source' => 'places',
            ];
        }

        return $results;
    }

    /**
     * Nominatim's policy is one request per second for the shared instance.
     * A sleep is crude, but the alternative is dropping a search the viewer
     * is waiting on, and the cache means this is rarely reached.
     */
    protected function throttle(): void
    {
        $last = (float) Cache::get(self::THROTTLE_KEY, 0);
        $wait = 1.0 - (microtime(true) - $last);

        if ($wait > 0 && $wait <= 1.0) {
            usleep((int) ($wait * 1_000_000));
        }

        Cache::put(self::THROTTLE_KEY, microtime(true), 10);
    }

    protected function userAgent(): string
    {
        $configured = config('geo.geocoder.user_agent');

        if ($configured) {
            return (string) $configured;
        }

        // Falls back to something that identifies the instance, because an
        // unidentified client is what gets a shared geocoder blocked.
        return 'Pixelfed/'.config('pixelfed.version', 'fork').' (+'.config('app.url').')';
    }

    protected function formatPair(float $lat, float $lng): string
    {
        return number_format($lat, 6).', '.number_format($lng, 6);
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
