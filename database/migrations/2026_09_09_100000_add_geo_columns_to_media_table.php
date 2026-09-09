<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Coordinates read out of an upload's EXIF, kept on the media row so the
 * status level coordinates can be re-derived if the author changes the
 * location or precision of a post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();

            // 'city' or 'exact' — author's choice for this upload, null = instance default
            $table->string('geo_precision', 8)->nullable();

            // Set once extraction has been attempted, successful or not
            $table->timestamp('geo_extracted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn([
                'geo_lat',
                'geo_lng',
                'geo_precision',
                'geo_extracted_at',
            ]);
        });
    }
};
