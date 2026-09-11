<?php

/*
|--------------------------------------------------------------------------
| Geo feed routes (fork feature)
|--------------------------------------------------------------------------
|
| Loaded by App\Geo\GeoServiceProvider, not by RouteServiceProvider, so
| upstream's routes/*.php files stay untouched. See docs/fork/GEO_FEED.md.
|
| Every path here is at least two segments deep on purpose. routes/web.php
| ends with `Route::get('{username}', ...)`, a single-segment catch-all, and
| a two-segment path cannot be shadowed by it — so these routes work no
| matter which order the providers boot in.
|
*/

use App\Geo\Http\Controllers\GeoFeedController;
use App\Geo\Http\Controllers\GeoLocationController;
use Illuminate\Support\Facades\Route;

Route::domain(config('pixelfed.domain.app'))
    ->middleware(['web', 'localization'])
    ->group(function () {
        Route::get('discover/map', [GeoFeedController::class, 'index'])->name('geo.map');

        Route::prefix('api/geo/v1')->group(function () {
            Route::get('feed', [GeoFeedController::class, 'viewport']);
            Route::get('places/nearby', [GeoLocationController::class, 'nearby']);
            Route::get('compose/suggest', [GeoLocationController::class, 'suggest']);
            Route::put('compose/media/{id}', [GeoLocationController::class, 'updateMedia']);
        });
    });
