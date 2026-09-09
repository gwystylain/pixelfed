<?php

namespace App\Geo\Console;

use App\Geo\Services\GeoFeedService;
use App\Geo\Services\MediaGeoService;
use App\Geo\Services\StatusGeoService;
use App\Geo\Support\Coordinates;
use App\Models\Media;
use App\Models\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 */
class BackfillGeoCommand extends Command
{
    protected $signature = 'geo:backfill
        {--places : Pin posts that already have a location at that city}
        {--media : Re-read EXIF from uploads that have not been checked}
        {--limit=0 : Stop after this many rows (0 = no limit)}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Populate map coordinates for existing posts';

    public function handle(MediaGeoService $mediaGeo, StatusGeoService $statusGeo): int
    {
        if (! config('geo.enabled')) {
            $this->error('The geo feature is disabled. Set GEO_ENABLED=true first.');

            return self::FAILURE;
        }

        $places = (bool) $this->option('places');
        $media = (bool) $this->option('media');

        if (! $places && ! $media) {
            $this->error('Nothing to do. Pass --places, --media, or both.');
            $this->line('');
            $this->line('  --places  pins historical posts that already carry a place_id.');
            $this->line('            Safe, fast, and the quickest way to get a populated map.');
            $this->line('');
            $this->line('  --media   re-reads GPS from stored uploads. Most older files have');
            $this->line('            already been stripped by the resize pipeline, so expect');
            $this->line('            a low hit rate outside recent uploads.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing will be written.');
        }

        if ($places) {
            $this->backfillFromPlaces();
        }

        if ($media) {
            $this->backfillFromMedia($mediaGeo, $statusGeo);
        }

        if (! $this->option('dry-run')) {
            GeoFeedService::flush();
        }

        return self::SUCCESS;
    }

    /**
     * Posts that already have a location get pinned at that city.
     *
     * Written with the query builder rather than Eloquent so historical posts
     * do not have their `updated_at` bumped — that column drives federation
     * and cache invalidation elsewhere.
     */
    protected function backfillFromPlaces(): void
    {
        $query = $this->pendingStatuses();
        $total = $query->count();

        $this->line('');
        $this->info("Pinning posts from their existing location: {$total} candidate(s)");

        if ($total === 0) {
            return;
        }

        $limit = (int) $this->option('limit');
        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $stop = false;

        $this->pendingStatuses()->chunkById(500, function ($statuses) use (&$updated, &$skipped, &$stop, $bar, $limit) {
            $placeIds = $statuses->pluck('place_id')->unique()->filter()->all();

            $coordinates = DB::table('places')
                ->select('id', 'lat', 'long')
                ->whereIn('id', $placeIds)
                ->get()
                ->keyBy('id');

            foreach ($statuses as $status) {
                $place = $coordinates->get($status->place_id);

                if (! $place || ! Coordinates::isValid($place->lat, $place->long)) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                if (! $this->option('dry-run')) {
                    DB::table('statuses')->where('id', $status->id)->update([
                        'geo_lat' => $place->lat,
                        'geo_lng' => $place->long,
                        'geo_precision' => StatusGeoService::PRECISION_CITY,
                        'geo_source' => 'place',
                    ]);
                }

                $updated++;
                $bar->advance();

                if ($limit > 0 && $updated >= $limit) {
                    $stop = true;

                    return false;
                }
            }

            return ! $stop;
        });

        $bar->finish();
        $this->line('');
        $this->info("Pinned {$updated} post(s), skipped {$skipped} with unusable city coordinates.");
    }

    /**
     * Re-read GPS from stored uploads.
     */
    protected function backfillFromMedia(MediaGeoService $mediaGeo, StatusGeoService $statusGeo): void
    {
        $total = $this->pendingMedia()->count();

        $this->line('');
        $this->info("Reading EXIF from stored uploads: {$total} candidate(s)");

        if ($total === 0) {
            return;
        }

        $limit = (int) $this->option('limit');
        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->start();

        $found = 0;
        $checked = 0;
        $stop = false;

        $this->pendingMedia()->chunkById(200, function ($rows) use (
            &$found, &$checked, &$stop, $bar, $limit, $mediaGeo, $statusGeo
        ) {
            foreach ($rows as $media) {
                $checked++;
                $bar->advance();

                if (! $this->option('dry-run') && $mediaGeo->extract($media)) {
                    $found++;

                    if ($media->status_id) {
                        $status = Status::find($media->status_id);

                        if ($status) {
                            $statusGeo->resolve($status);
                        }
                    }
                }

                if ($limit > 0 && $checked >= $limit) {
                    $stop = true;

                    return false;
                }
            }

            return ! $stop;
        });

        $bar->finish();
        $this->line('');
        $this->info("Checked {$checked} upload(s), found coordinates in {$found}.");
    }

    protected function pendingStatuses()
    {
        return Status::query()
            ->select('id', 'place_id')
            ->whereNotNull('place_id')
            ->whereNull('geo_lat')
            ->whereIn('type', StatusGeoService::MAPPABLE_TYPES)
            ->whereNull('in_reply_to_id')
            ->whereNull('reblog_of_id');
    }

    protected function pendingMedia()
    {
        return Media::query()
            ->whereNull('geo_extracted_at')
            ->whereNotNull('media_path')
            ->whereIn('mime', MediaGeoService::SUPPORTED_MIMES)
            ->where(function ($q) {
                $q->where('remote_media', false)->orWhereNull('remote_media');
            });
    }
}
