<?php

/*
|--------------------------------------------------------------------------
| Geo Feed (fork feature)
|--------------------------------------------------------------------------
|
| Configuration for the geo feed, EXIF GPS capture and location suggestion.
| This file is not part of upstream Pixelfed — see docs/fork/GEO_FEED.md.
|
*/

return [
    /*
    | Master switch. When false, no geo routes are registered, no EXIF is read
    | and the observers become no-ops.
    */
    'enabled' => env('GEO_ENABLED', true),

    'exif' => [
        /*
        | Read GPS coordinates from uploaded photos.
        |
        | Coordinates are read from the *original* upload, before the resize
        | pipeline re-encodes (and therefore strips) the file. The published
        | image keeps its metadata stripped; only the coordinates are kept,
        | in the database, where they can be scoped and redacted.
        */
        'enabled' => env('GEO_EXIF_ENABLED', true),

        /*
        | Extract inline during the upload request (true) or on the queue
        | (false). Inline is race-free: it runs before the resize job is
        | dispatched. Queued extraction can lose the race against the
        | resize on local storage, so only use it with remote storage.
        */
        'inline' => env('GEO_EXIF_INLINE', true),

        /*
        | Maximum number of bytes read from an upload when searching for the
        | EXIF block. HEIC/HEIF stores its EXIF item inside `mdat`, which can
        | sit well past the first few KB, so this needs some headroom.
        */
        'max_read_bytes' => env('GEO_EXIF_MAX_READ_BYTES', 8388608),
    ],

    'autotag' => [
        /*
        | When a post is published with photo GPS but no location, assign the
        | nearest known city automatically. This is what gives the mobile apps
        | and third party clients location data without any client changes.
        */
        'enabled' => env('GEO_AUTOTAG_ENABLED', true),

        /*
        | Never auto-assign a city further away than this (km). Photos taken
        | mid-ocean or in remote wilderness are left without a place.
        */
        'max_distance_km' => env('GEO_AUTOTAG_MAX_DISTANCE_KM', 50),
    ],

    /*
    | Coordinate precision written to the status, i.e. where the pin lands.
    |
    |   exact — the coordinates recorded by the camera (default)
    |   city  — snap to the centre of the matched city
    |
    | `exact` is the default because this is a personal instance and a map of
    | city centres is not worth having: every photo from one town lands on the
    | same pixel. Understand what it means before running it on an instance
    | with strangers on it — an exact pin on a photo taken at home is a home
    | address, published. `GEO_PRECISION_DEFAULT=city` restores the cautious
    | behaviour, and authors can still drop a single post to city precision,
    | or off the map entirely, from the composer.
    |
    | A post with no photo GPS is pinned at its city whatever this says: the
    | setting picks the most precise source available, it cannot invent one.
    */
    'precision' => [
        'default' => env('GEO_PRECISION_DEFAULT', 'exact'),

        // Allow authors to choose `exact` for an individual post.
        'allow_exact' => env('GEO_PRECISION_ALLOW_EXACT', true),
    ],

    /*
    | Turning typed text into coordinates, for "edit location" on the map.
    |
    |   nominatim — OpenStreetMap's geocoder. Resolves street addresses, so a
    |               bad GPS read can be corrected to somewhere smaller than a
    |               city. Each search is an outbound request from this server
    |               carrying only what the author typed.
    |   places    — the local `places` table. Towns and cities only, and
    |               nothing leaves the instance.
    |
    | Dragging the pin and pasting a coordinate pair work under both, and
    | neither calls out: they are the accurate paths regardless.
    |
    | Using the shared nominatim.openstreetmap.org means accepting its usage
    | policy: identify yourself in `user_agent`, and no more than one request
    | a second. Both are handled, results are cached for a day, but a busy
    | instance should point `nominatim_url` at its own.
    */
    'geocoder' => [
        'driver' => env('GEO_GEOCODER', 'nominatim'),

        'nominatim_url' => env('GEO_GEOCODER_URL', 'https://nominatim.openstreetmap.org'),

        // Sent as User-Agent. Nominatim blocks clients it cannot identify;
        // the fallback is built from app.url, which is usually enough.
        'user_agent' => env('GEO_GEOCODER_USER_AGENT'),

        'timeout' => env('GEO_GEOCODER_TIMEOUT', 6),

        'cache_ttl' => env('GEO_GEOCODER_CACHE_TTL', 86400),
    ],

    'feed' => [
        // Max individual posts returned for one viewport.
        'max_results' => env('GEO_FEED_MAX_RESULTS', 250),

        // Below this zoom level results are returned as grid clusters.
        'cluster_max_zoom' => env('GEO_FEED_CLUSTER_MAX_ZOOM', 12),

        // Seconds to cache a viewport response.
        'cache_ttl' => env('GEO_FEED_CACHE_TTL', 120),

        // Only surface posts newer than this many days (0 = no limit).
        'max_age_days' => env('GEO_FEED_MAX_AGE_DAYS', 0),
    ],

    'map' => [
        'tile_url' => env('GEO_MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),

        'tile_attribution' => env('GEO_MAP_TILE_ATTRIBUTION', '&copy; OpenStreetMap contributors'),

        'min_zoom' => env('GEO_MAP_MIN_ZOOM', 2),

        'max_zoom' => env('GEO_MAP_MAX_ZOOM', 18),

        // Initial view when the viewer has no saved position.
        'default_lat' => env('GEO_MAP_DEFAULT_LAT', 20.0),

        'default_lng' => env('GEO_MAP_DEFAULT_LNG', 0.0),

        'default_zoom' => env('GEO_MAP_DEFAULT_ZOOM', 3),
    ],
];
