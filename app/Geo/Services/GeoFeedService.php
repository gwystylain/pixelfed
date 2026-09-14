<?php

namespace App\Geo\Services;

use App\Geo\Support\Coordinates;
use App\Geo\Support\DateWindow;
use App\Services\StatusService;
use App\Services\UserFilterService;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Serves a map viewport: grid clusters when zoomed out, individual posts
 * when zoomed in.
 *
 * Clustering happens in SQL — a world view can cover millions of rows, and
 * pulling them into PHP to group them would not survive contact with a real
 * instance. The grid is plain arithmetic on the indexed lat/lng columns, so
 * it needs no PostGIS and works on both supported databases.
 */
class GeoFeedService
{
    const CACHE_PREFIX = 'pf-geo:feed:v1:';

    const CACHE_VERSION_KEY = 'pf-geo:feed:v1:version';

    const OLDEST_CACHE_KEY = 'pf-geo:feed:oldest';

    /** Upper bound on clusters returned for one viewport. */
    const MAX_CLUSTERS = 400;

    /**
     * Bump the cache generation, invalidating every cached viewport.
     *
     * Cheaper and more reliable than tracking which viewport keys contain a
     * given post: viewport responses are short lived anyway.
     */
    public static function flush(): void
    {
        $current = Cache::get(self::CACHE_VERSION_KEY);

        if ($current === null) {
            Cache::forever(self::CACHE_VERSION_KEY, 1);

            return;
        }

        Cache::increment(self::CACHE_VERSION_KEY);
    }

    public static function version(): int
    {
        $version = Cache::get(self::CACHE_VERSION_KEY);

        if ($version === null) {
            Cache::forever(self::CACHE_VERSION_KEY, 1);

            return 1;
        }

        return (int) $version;
    }

    /**
     * The oldest post the map can show, as `Y-m-d`, or null if there are none.
     *
     * The date slider needs a left-hand end, and how far back this instance's
     * map actually goes is the only honest one: `geo:backfill --places` pins
     * posts from years before the feature existed, and a fixed "five years"
     * would either hide them or offer empty years.
     *
     * Cached for an hour. It moves when the oldest pinned post is deleted,
     * and not otherwise. The empty string stands in for "none", because
     * `Cache::remember` treats a null as a miss and would rerun the scan on
     * every page load of an instance with nothing on its map.
     */
    public static function oldestPinnedAt(): ?string
    {
        $oldest = Cache::remember(self::OLDEST_CACHE_KEY, 3600, function () {
            $value = self::mappableQuery()->min('created_at');

            return $value ? substr((string) $value, 0, 10) : '';
        });

        return $oldest === '' ? null : $oldest;
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox  [minLat, minLng, maxLat, maxLng]
     * @return array{mode: string, zoom: int, cell_size: float|null, clusters: list<array<string, mixed>>, posts: list<array<string, mixed>>}
     */
    public function viewport(
        array $bbox,
        int $zoom,
        ?int $viewerProfileId = null,
        ?int $limit = null,
        ?DateWindow $window = null
    ): array {
        [$minLat, $minLng, $maxLat, $maxLng] = $bbox;

        $window = $window ?? DateWindow::none();

        $zoom = max(0, min(20, $zoom));
        $clustered = $zoom <= (int) config('geo.feed.cluster_max_zoom', 12);

        $limit = min(
            $limit ?? (int) config('geo.feed.max_results', 250),
            (int) config('geo.feed.max_results', 250)
        );

        // Cluster counts are aggregate and viewer independent, so they cache
        // globally. Post lists are filtered per viewer and cache per viewer.
        $cacheKey = self::CACHE_PREFIX.self::version().':'.md5(implode(':', [
            $clustered ? 'c' : 'p',
            $zoom,
            round($minLat, 4), round($minLng, 4), round($maxLat, 4), round($maxLng, 4),
            $limit,
            $clustered ? 'all' : ($viewerProfileId ?? 0),
            $window->cacheKey(),
        ]));

        $ttl = (int) config('geo.feed.cache_ttl', 120);

        return Cache::remember($cacheKey, $ttl, function () use (
            $clustered, $minLat, $minLng, $maxLat, $maxLng, $zoom, $viewerProfileId, $limit, $window
        ) {
            if ($clustered) {
                return [
                    'mode' => 'clusters',
                    'zoom' => $zoom,
                    'cell_size' => Coordinates::clusterCellSize($zoom),
                    'clusters' => $this->clusters($minLat, $minLng, $maxLat, $maxLng, $zoom, $window),
                    'posts' => [],
                ];
            }

            return [
                'mode' => 'posts',
                'zoom' => $zoom,
                'cell_size' => null,
                'clusters' => [],
                'posts' => $this->posts($minLat, $minLng, $maxLat, $maxLng, $viewerProfileId, $limit, $window),
            ];
        });
    }

    /**
     * @return list<array{lat: float, lng: float, count: int, cover_id: string}>
     */
    protected function clusters(
        float $minLat,
        float $minLng,
        float $maxLat,
        float $maxLng,
        int $zoom,
        DateWindow $window
    ): array {
        $cell = Coordinates::clusterCellSize($zoom);

        // Inlined as a literal rather than bound: MySQL and Postgres disagree
        // about the type of a bound parameter in an arithmetic expression, and
        // this value is derived from an integer zoom level, never user text.
        $cellSql = sprintf('%.12F', $cell);

        $rows = $this->baseQuery($minLat, $minLng, $maxLat, $maxLng, $window)
            ->selectRaw("FLOOR(geo_lat / {$cellSql}) as cell_lat")
            ->selectRaw("FLOOR(geo_lng / {$cellSql}) as cell_lng")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('AVG(geo_lat) as avg_lat')
            ->selectRaw('AVG(geo_lng) as avg_lng')
            ->selectRaw('MAX(id) as cover_id')
            ->groupByRaw("FLOOR(geo_lat / {$cellSql}), FLOOR(geo_lng / {$cellSql})")
            ->orderByDesc('total')
            ->limit(self::MAX_CLUSTERS)
            ->get();

        return $rows->map(fn ($row) => [
            'lat' => round((float) $row->avg_lat, 6),
            'lng' => round((float) $row->avg_lng, 6),
            'count' => (int) $row->total,
            'cover_id' => (string) $row->cover_id,
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function posts(
        float $minLat,
        float $minLng,
        float $maxLat,
        float $maxLng,
        ?int $viewerProfileId,
        int $limit,
        DateWindow $window
    ): array {
        $filtered = $viewerProfileId ? UserFilterService::filters($viewerProfileId) : [];

        $query = $this->baseQuery($minLat, $minLng, $maxLat, $maxLng, $window)
            ->select('id', 'profile_id', 'geo_lat', 'geo_lng', 'geo_precision', 'geo_source');

        // Small block lists filter in SQL. A viewer with hundreds of blocks
        // would turn that into an unreasonable IN list, so past a threshold
        // the filtering falls to the collection below instead — which runs
        // after the limit, and so can return slightly fewer than `limit`
        // pins. Under-filling a viewport is preferable to a query that
        // degrades for everyone sharing the database.
        if (! empty($filtered) && count($filtered) <= 250) {
            $query->whereNotIn('profile_id', $filtered);
        }

        return $query->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reject(fn ($row) => in_array((int) $row->profile_id, array_map('intval', $filtered), true))
            ->map(fn ($row) => $this->preview($row))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A map pin carries just enough to draw a thumbnail and a caption. The
     * full status is one existing API call away when somebody opens a post.
     *
     * @return array<string, mixed>|null
     */
    protected function preview($row): ?array
    {
        $status = StatusService::get((int) $row->id, true);

        if (! $status || ! isset($status['account'], $status['media_attachments'][0])) {
            return null;
        }

        $media = $status['media_attachments'][0];

        return [
            'id' => (string) $row->id,
            'lat' => (float) $row->geo_lat,
            'lng' => (float) $row->geo_lng,
            'precision' => $row->geo_precision,

            // The editor offers "reset to photo" only for a hand placed pin.
            'source' => $row->geo_source,
            'url' => $status['url'] ?? null,
            'created_at' => $status['created_at'] ?? null,
            'sensitive' => (bool) ($status['sensitive'] ?? false),
            'media_count' => count($status['media_attachments']),
            'thumbnail' => $media['preview_url'] ?? $media['url'] ?? null,
            'blurhash' => $media['blurhash'] ?? null,
            'description' => $media['description'] ?? null,
            'place' => $this->presentPlace($status['place'] ?? null),
            'account' => [
                'id' => (string) ($status['account']['id'] ?? ''),
                'username' => $status['account']['username'] ?? null,
                'acct' => $status['account']['acct'] ?? null,
                'display_name' => $status['account']['display_name'] ?? null,
                'avatar' => $status['account']['avatar'] ?? null,
                'url' => $status['account']['url'] ?? null,
            ],
        ];
    }

    /**
     * `place` arrives from StatusService as a Place model, or as a plain
     * array once it has been through the cache. Normalise both, and add the
     * link to upstream's location page — the model exposes a slug but not a
     * url, and the map popup needs somewhere to send "+12 more".
     *
     * @return array{id: string, name: string|null, country: string|null, url: string|null}|null
     */
    protected function presentPlace($place): ?array
    {
        if ($place instanceof Arrayable) {
            $place = $place->toArray();
        }

        if (! is_array($place) || empty($place['id'])) {
            return null;
        }

        return [
            'id' => (string) $place['id'],
            'name' => $place['name'] ?? null,
            'country' => $place['country'] ?? null,
            'url' => isset($place['slug'])
                ? url('/discover/places/'.$place['id'].'/'.$place['slug'])
                : null,
        ];
    }

    /**
     * Everything the map is allowed to show, before grouping or paging.
     */
    protected function baseQuery(
        float $minLat,
        float $minLng,
        float $maxLat,
        float $maxLng,
        ?DateWindow $window = null
    ): Builder {
        $query = self::mappableQuery()
            ->whereBetween('geo_lat', [$minLat, $maxLat]);

        // Panning past the antimeridian splits the box in two.
        if ($minLng > $maxLng) {
            $query->where(function ($q) use ($minLng, $maxLng) {
                $q->where('geo_lng', '>=', $minLng)->orWhere('geo_lng', '<=', $maxLng);
            });
        } else {
            $query->whereBetween('geo_lng', [$minLng, $maxLng]);
        }

        // The viewer's own range, on top of the instance ceiling above —
        // `max_age_days` is a limit, not a default, so a request for a wider
        // window cannot widen it.
        if ($window !== null && $window->from !== null) {
            $query->where('created_at', '>=', $window->from);
        }

        if ($window !== null && $window->to !== null) {
            $query->where('created_at', '<=', $window->to);
        }

        return $query;
    }

    /**
     * The non-spatial half of `baseQuery`: which posts this instance puts on
     * its map at all, independent of viewport or date range.
     *
     * Split out so the slider's left-hand end is derived from exactly the
     * same set of posts the map draws — an oldest date pointing at a post the
     * map would never show is a slider with a dead end on it.
     */
    protected static function mappableQuery(): Builder
    {
        $query = DB::table('statuses')
            ->whereNotNull('geo_lat')
            ->whereNotNull('geo_lng')
            ->whereIn('type', StatusGeoService::MAPPABLE_TYPES)
            ->whereNull('in_reply_to_id')
            ->whereNull('reblog_of_id')
            ->whereNull('deleted_at')
            ->where('scope', 'public');

        if (config('instance.hide_nsfw_on_public_feeds')) {
            $query->where('is_nsfw', false);
        }

        $maxAgeDays = (int) config('geo.feed.max_age_days', 0);
        if ($maxAgeDays > 0) {
            $query->where('created_at', '>', now()->subDays($maxAgeDays));
        }

        return $query;
    }
}
