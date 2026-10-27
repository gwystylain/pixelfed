<?php

/*
|--------------------------------------------------------------------------
| Immich library routes (fork feature)
|--------------------------------------------------------------------------
|
| Loaded by App\Immich\ImmichServiceProvider. See docs/fork/IMMICH.md.
|
| Like routes/geo.php, every path is at least two segments deep, so the
| single-segment `{username}` catch-all at the end of routes/web.php can
| never shadow one of them.
|
*/

use App\Immich\Http\Controllers\ImmichLibraryController;
use App\Immich\Http\Controllers\ImmichSettingsController;
use Illuminate\Support\Facades\Route;

Route::domain(config('pixelfed.domain.app'))
    ->middleware(['web', 'localization'])
    ->group(function () {
        Route::get('settings/immich', [ImmichSettingsController::class, 'index'])->name('settings.immich');

        Route::prefix('api/immich/v1')->group(function () {
            // Settings: the connection. Each of these calls out to Immich.
            Route::get('connection', [ImmichSettingsController::class, 'show']);
            Route::put('connection', [ImmichSettingsController::class, 'update'])->middleware('throttle:20,1');
            Route::post('connection/check', [ImmichSettingsController::class, 'check'])->middleware('throttle:20,1');
            Route::delete('connection', [ImmichSettingsController::class, 'destroy']);

            // Settings: which Immich name is which account.
            Route::get('people', [ImmichSettingsController::class, 'people']);
            Route::get('people/{id}/thumbnail', [ImmichSettingsController::class, 'personThumbnail']);
            Route::put('mappings', [ImmichSettingsController::class, 'saveMapping']);
            Route::delete('mappings/{id}', [ImmichSettingsController::class, 'deleteMapping']);

            // The composer's picker.
            Route::get('status', [ImmichLibraryController::class, 'status']);
            Route::get('albums', [ImmichLibraryController::class, 'albums']);
            Route::get('assets', [ImmichLibraryController::class, 'assets']);
            Route::get('assets/{id}', [ImmichLibraryController::class, 'asset']);
            Route::get('assets/{id}/thumbnail', [ImmichLibraryController::class, 'thumbnail']);
            Route::get('assets/{id}/file', [ImmichLibraryController::class, 'file'])->middleware('throttle:60,1');
            Route::post('compose/attach', [ImmichLibraryController::class, 'attach']);
        });
    });
