<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * `places` already carries lat/long for ~128k cities, but the only index
 * covering them starts with `slug`, so it cannot serve a coordinate range
 * scan. Reverse geocoding a photo needs exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->index(['lat', 'long'], 'places_lat_long_index');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropIndex('places_lat_long_index');
        });
    }
};
