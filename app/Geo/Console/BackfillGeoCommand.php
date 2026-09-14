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
        {--repin : Re-derive pinned posts at the current precision setting}
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
        $repin = (bool) $this->option('repin');

        if (! $places && ! $media && ! $repin) {
            $this->error('Nothing to do. Pass --places, --media, --repin, or a combination.');
            $this->line('');
            $this->line('  --places  pins historical posts that already carry a place_id.');
            $this->line('            Safe, fast, and the quickest way to get a populated map.');
            $this->line('');
            $this->line('  --media   re-reads GPS from stored uploads. Most older files have');
            $this->line('            already been stripped by the resize pipeline, so expect');
            $this->line('            a low hit rate outside recent uploads.');
            $this->line('');
            $this->line('  --repin   moves posts already on the map onto the coordinates');
            $this->line('            in their own photos, for when geo.precision.default');
            $this->line('            has changed.');

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

        if ($repin) {
            $this->repin($statusGeo);
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

    /**
     * Re-derive posts that are already on the map.
     *
     * For when `geo.precision.default` has changed: a post pinned at its city
     * under the old setting has a more precise position available and no way
     * to reach it, because `resolve()` leaves an existing pin alone unless it
     * is forced.
     *
     * Only posts whose photos actually carry GPS are candidates. One pinned
     * from its `place_id` has nothing more precise to offer, and re-deriving
     * it would cost a query to arrive back where it started.
     */
    protected function repin(StatusGeoService $statusGeo): void
    {
        $query = $this->repinnableStatuses();
        $total = $query->count();

        $this->line('');
        $this->info("Re-deriving pinned posts at precision '".config('geo.precision.default')."': {$total} candidate(s)");

        if ($total === 0) {
            return;
        }

        $limit = (int) $this->option('limit');
        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->start();

        $considered = 0;
        $moved = 0;
        $unchanged = 0;
        $stop = false;

        $this->repinnableStatuses()->chunkById(200, function ($statuses) use (
            &$considered, &$moved, &$unchanged, &$stop, $bar, $limit, $statusGeo
        ) {
            foreach ($statuses as $status) {
                $considered++;

                if (! $this->option('dry-run')) {
                    // Cast both sides: the column comes back as a decimal
                    // string and goes back in as a float, which would read as
                    // a move on every row.
                    $before = [(float) $status->geo_lat, (float) $status->geo_lng];

                    // Forced, because resolve() leaves an existing pin alone.
                    $statusGeo->resolve($status, true);

                    if ([(float) $status->geo_lat, (float) $status->geo_lng] !== $before) {
                        $moved++;
                    } else {
                        $unchanged++;
                    }
                }

                $bar->advance();

                if ($limit > 0 && $considered >= $limit) {
                    $stop = true;

                    return false;
                }
            }

            return ! $stop;
        });

        $bar->finish();
        $this->line('');

        if ($this->option('dry-run')) {
            $this->info("Would re-derive {$considered} pinned post(s).");

            return;
        }

        $this->info("Moved {$moved} pin(s); {$unchanged} were already as precise as they can be.");
    }

    /**
     * Pinned posts whose photos carry coordinates of their own.
     */
    protected function repinnableStatuses()
    {
        return Status::query()
            ->whereNotNull('geo_lat')
            ->whereIn('type', StatusGeoService::MAPPABLE_TYPES)
            ->whereNull('in_reply_to_id')
            ->whereNull('reblog_of_id')
            ->whereIn('id', function ($q) {
                $q->select('status_id')
                    ->from('media')
                    ->whereNotNull('status_id')
                    ->whereNotNull('geo_lat');
            });
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
