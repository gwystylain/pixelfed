<?php

namespace App\Geo\Jobs;

use App\Geo\Services\MediaGeoService;
use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Out-of-band EXIF extraction, used when `geo.exif.inline` is off.
 *
 * Only safe on remote storage. On local storage the resize pipeline writes
 * back over the original path, so a queued read can lose the race and find
 * an already stripped file.
 */
class ExtractMediaGeoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 60;

    public $uniqueFor = 300;

    public function __construct(public int $mediaId) {}

    public function uniqueId(): string
    {
        return 'geo:media:'.$this->mediaId;
    }

    public function handle(MediaGeoService $mediaGeo): void
    {
        $media = Media::find($this->mediaId);

        if (! $media) {
            return;
        }

        $mediaGeo->extract($media);
    }
}
