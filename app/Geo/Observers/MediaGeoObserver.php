<?php

namespace App\Geo\Observers;

use App\Geo\Jobs\ExtractMediaGeoJob;
use App\Geo\Jobs\ResolveStatusGeoJob;
use App\Geo\Services\MediaGeoService;
use App\Media;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Two hooks, both chosen so the feature needs no changes to any upstream
 * controller:
 *
 *  created  — the upload has been stored but `ImageOptimize` has not been
 *             dispatched yet, so the original still has its EXIF. This is the
 *             last race-free moment to read the coordinates.
 *
 *  updated  — `status_id` going from null to a value is the composer (or the
 *             Mastodon API, which does the same thing) attaching media to a
 *             post. That is the first moment both the author's chosen place
 *             and the photo's coordinates exist.
 */
class MediaGeoObserver
{
    public function __construct(protected MediaGeoService $mediaGeo) {}

    public function created(Media $media): void
    {
        if (! $this->mediaGeo->shouldExtract($media)) {
            return;
        }

        if (config('geo.exif.inline')) {
            $this->mediaGeo->extract($media);

            return;
        }

        ExtractMediaGeoJob::dispatch($media->id)->onQueue('mmo');
    }

    public function updated(Media $media): void
    {
        if (! config('geo.enabled')) {
            return;
        }

        if (! $media->status_id || ! $media->wasChanged('status_id')) {
            return;
        }

        // Deliberately delayed. The composer sets `status_id` on each
        // attachment before it writes the post's final type and visibility,
        // and an album fires this hook once per photo; a short delay lets all
        // of that settle so the job sees a finished post.
        ResolveStatusGeoJob::dispatch((int) $media->status_id)
            ->onQueue('feed')
            ->delay(now()->addSeconds(5));
    }
}
