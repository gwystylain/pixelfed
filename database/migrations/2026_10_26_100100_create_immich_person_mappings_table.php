<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * "This name in my Immich is this account here." Keyed by name rather than
 * by Immich's person id: one person is often split across several face
 * clusters that share a name, and a mapping should cover all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('immich_person_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();

            // As typed in Immich, for display
            $table->string('name', 191);
            // Lower-cased and whitespace-collapsed, for matching
            $table->string('name_key', 191);

            $table->unsignedBigInteger('profile_id')->index();
            $table->timestamps();

            $table->unique(['user_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immich_person_mappings');
    }
};
