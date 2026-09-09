<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * The coordinates a post is pinned at on the map. Kept separate from
 * media.geo_* because they may be snapped to a city centre, or set from a
 * manually chosen place with no photo GPS involved at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();

            // 'city' | 'exact'
            $table->string('geo_precision', 8)->nullable();

            // 'exif' | 'place' | 'manual'
            $table->string('geo_source', 8)->nullable();

            // Viewport lookups scan on lat then filter on lng
            $table->index(['geo_lat', 'geo_lng'], 'statuses_geo_lat_geo_lng_index');
        });
    }

    public function down(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->dropIndex('statuses_geo_lat_geo_lng_index');
            $table->dropColumn([
                'geo_lat',
                'geo_lng',
                'geo_precision',
                'geo_source',
            ]);
        });
    }
};
