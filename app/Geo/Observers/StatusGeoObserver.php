<?php

namespace App\Geo\Observers;

use App\Geo\Jobs\ResolveStatusGeoJob;
use App\Geo\Services\GeoFeedService;
use App\Models\Status;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Keeps a post's map position in step with edits to it.
 */
class StatusGeoObserver
{
    public function updated(Status $status): void
    {
        if (! config('geo.enabled')) {
            return;
        }

        // The author moved the post to a different location: re-derive.
        if ($status->wasChanged('place_id')) {
            ResolveStatusGeoJob::dispatch((int) $status->id, true)
                ->onQueue('feed');

            return;
        }

        // A draft becoming a real post, or a type being written after the
        // attachments were saved. Only worth a pass if it is not on the map.
        if ($status->geo_lat === null && $status->wasChanged(['type', 'scope'])) {
            ResolveStatusGeoJob::dispatch((int) $status->id)
                ->onQueue('feed');

            return;
        }

        // Visibility changed on a post that is on the map — cached viewports
        // are now wrong in one direction or the other.
        if ($status->geo_lat !== null && $status->wasChanged('scope')) {
            GeoFeedService::flush();
        }
    }

    public function deleted(Status $status): void
    {
        if (config('geo.enabled') && $status->geo_lat !== null) {
            GeoFeedService::flush();
        }
    }
}
