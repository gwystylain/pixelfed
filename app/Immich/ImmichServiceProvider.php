<?php

namespace App\Immich;

use Illuminate\Support\ServiceProvider;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * The single entry point for the Immich feature, registered in
 * bootstrap/providers.php — the same arrangement as App\Geo, for the same
 * reason: everything else lives in files upstream does not have.
 */
class ImmichServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('immich.enabled')) {
            return;
        }

        $this->loadRoutesFrom(base_path('routes/immich.php'));
    }
}
