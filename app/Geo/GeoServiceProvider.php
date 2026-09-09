<?php

namespace App\Geo;

use App\Geo\Console\BackfillGeoCommand;
use App\Geo\Observers\MediaGeoObserver;
use App\Geo\Observers\StatusGeoObserver;
use App\Models\Media;
use App\Models\Status;
use Illuminate\Support\ServiceProvider;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * The single entry point for the geo feature. Registering this provider in
 * bootstrap/providers.php is the only change the feature needs in upstream's PHP —
 * everything else lives under app/Geo, routes/geo.php and config/geo.php.
 *
 * Model observers rather than controller edits are what buys that: they hook
 * the composer, the Mastodon API and any future upload path at once, and
 * they cannot conflict on a rebase because they are not in upstream's files.
 */
class GeoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Always available, so an admin can enable the feature and backfill
        // in either order.
        if ($this->app->runningInConsole()) {
            $this->commands([BackfillGeoCommand::class]);
        }

        if (! config('geo.enabled')) {
            return;
        }

        $this->loadRoutesFrom(base_path('routes/geo.php'));

        Media::observe(MediaGeoObserver::class);
        Status::observe(StatusGeoObserver::class);
    }
}
