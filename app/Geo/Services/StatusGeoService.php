<?php

namespace App\Geo\Services;

use App\Geo\Support\Coordinates;
use App\Models\Media;
use App\Models\Place;
use App\Models\Status;
use App\Services\PlaceService;
use App\Services\StatusService;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Works out where a post belongs on the map, and assigns the nearest city
 * when the author did not pick one.
 *
 * Runs after media has been attached to a status, which is the first moment
 * both halves — the author's chosen `place_id` and the photo's coordinates —
 * are known. Doing it here rather than in the composer means the mobile apps
 * and any other API client get the same behaviour with no client changes.
 */
class StatusGeoService
{
    /** Author opted this upload out of the map entirely. */
    const PRECISION_NONE = 'none';

    const PRECISION_CITY = 'city';

    const PRECISION_EXACT = 'exact';

    /**
     * Post types that can appear on the map. Text posts and replies have no
     * business being there.
     *
     * @var list<string>
     */
    const MAPPABLE_TYPES = [
        'photo',
        'photo:album',
        'video',
        'video:album',
        'photo:video:album',
    ];

    public function __construct(protected ReverseGeocoder $geocoder) {}

    public function enabled(): bool
    {
        return (bool) config('geo.enabled');
    }

    /**
     * Populate `geo_lat` / `geo_lng` / `place_id` for a status.
     *
     * Idempotent: with $force false a status that already has coordinates is
     * left alone, so re-running the backfill is safe.
     */
    public function resolve(Status $status, bool $force = false): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if (! in_array($status->type, self::MAPPABLE_TYPES, true)) {
            return false;
        }

        if ($status->in_reply_to_id || $status->reblog_of_id) {
            return false;
        }

        if ($status->geo_lat !== null && ! $force) {
            return false;
        }

        // Opting out means "do not use this photo's GPS": the coordinates are
        // discarded and no city is guessed. It does not veto a location the
        // author went on to choose deliberately — that still gets a pin.
        $media = $this->hasOptOut($status->id)
            ? null
            : $this->coordinateSource($status);

        $precision = $this->effectivePrecision($media);
        $previousPlaceId = $status->place_id;
        $place = $status->place_id ? Place::find($status->place_id) : null;

        // No location chosen but the photo knows where it was: suggest, and
        // for API clients that have no way to confirm, accept the suggestion.
        //
        // First pass only. A forced re-derive means the author edited the
        // post, and guessing a city there would undo the edit — most
        // obviously when the edit was removing the location.
        if (! $place && $media && ! $force && $this->autotagEnabled()) {
            $suggestion = $this->geocoder->nearest(
                (float) $media->geo_lat,
                (float) $media->geo_lng,
                (float) config('geo.autotag.max_distance_km', 50)
            );

            if ($suggestion) {
                $place = Place::find($suggestion['id']);
                $status->place_id = $suggestion['id'];
            }
        }

        // Likewise, once an author has edited a post down to no location, a
        // coarse pin derived from the photo is not something they asked for.
        // Only an explicit request for exact coordinates keeps it on the map.
        $pinFrom = ($force && ! $place && $precision !== self::PRECISION_EXACT)
            ? null
            : $media;

        [$lat, $lng, $source] = $this->position($pinFrom, $place, $precision);

        if ($lat === null || $lng === null) {
            // A forced pass that lands here is a post that used to be on the
            // map and should no longer be — an author opting out after
            // publishing, or removing the location. Take the pin down.
            if ($force && $status->geo_lat !== null) {
                $this->clear($status);

                return false;
            }

            // Nothing to pin, but do not lose an auto-assigned place.
            if ($status->place_id !== $previousPlaceId) {
                $status->saveQuietly();
                $this->flush($status, $previousPlaceId);
            }

            return false;
        }

        $status->geo_lat = $lat;
        $status->geo_lng = $lng;
        $status->geo_precision = $precision;
        $status->geo_source = $source;
        $status->saveQuietly();

        $this->flush($status, $previousPlaceId);

        return true;
    }

    /**
     * Remove a status from the map.
     */
    public function clear(Status $status): void
    {
        $status->geo_lat = null;
        $status->geo_lng = null;
        $status->geo_precision = null;
        $status->geo_source = null;
        $status->saveQuietly();

        $this->flush($status, $status->place_id);
    }

    /**
     * The attachment whose coordinates we use: the first one that has any.
     */
    protected function coordinateSource(Status $status): ?Media
    {
        return Media::whereStatusId($status->id)
            ->whereNotNull('geo_lat')
            ->orderBy('order')
            ->orderBy('id')
            ->first();
    }

    /**
     * An author can opt a post up to exact coordinates; they cannot opt below
     * the instance default, and `exact` only applies where it is allowed.
     */
    protected function effectivePrecision(?Media $media): string
    {
        $requested = $media?->geo_precision;

        if ($requested === self::PRECISION_EXACT && config('geo.precision.allow_exact')) {
            return self::PRECISION_EXACT;
        }

        if ($requested === self::PRECISION_CITY) {
            return self::PRECISION_CITY;
        }

        return config('geo.precision.default') === self::PRECISION_EXACT
            ? self::PRECISION_EXACT
            : self::PRECISION_CITY;
    }

    /**
     * `none` on any attachment opts the whole post out.
     *
     * Opting out already nulls that attachment's coordinates, so this mostly
     * matters for albums: one photo declined should not be undone by another
     * photo in the same post still carrying a fix.
     */
    protected function hasOptOut(?int $statusId): bool
    {
        if (! $statusId) {
            return false;
        }

        return Media::whereStatusId($statusId)
            ->where('geo_precision', self::PRECISION_NONE)
            ->exists();
    }

    /**
     * @return array{0: float|null, 1: float|null, 2: string|null}
     */
    protected function position(?Media $media, ?Place $place, string $precision): array
    {
        $hasPhotoGps = $media
            && Coordinates::isValid($media->geo_lat, $media->geo_lng);

        if ($precision === self::PRECISION_EXACT && $hasPhotoGps) {
            return [
                (float) $media->geo_lat,
                (float) $media->geo_lng,
                'exif',
            ];
        }

        if ($place && Coordinates::isValid($place->lat, $place->long)) {
            return [
                (float) $place->lat,
                (float) $place->long,
                $hasPhotoGps ? 'exif' : 'place',
            ];
        }

        if ($hasPhotoGps) {
            // Photo GPS but nowhere near a known city — an island, a trail, a
            // ship. Keep it on the map, coarsened to roughly a kilometre so
            // city precision still means city precision.
            return [
                Coordinates::round((float) $media->geo_lat, 2),
                Coordinates::round((float) $media->geo_lng, 2),
                'exif',
            ];
        }

        return [null, null, null];
    }

    protected function autotagEnabled(): bool
    {
        return (bool) config('geo.autotag.enabled');
    }

    protected function flush(Status $status, ?int $previousPlaceId): void
    {
        StatusService::del($status->id);
        GeoFeedService::flush();

        foreach (array_unique(array_filter([$previousPlaceId, $status->place_id])) as $placeId) {
            PlaceService::clearStatusesByPlaceId($placeId);
        }
    }
}
