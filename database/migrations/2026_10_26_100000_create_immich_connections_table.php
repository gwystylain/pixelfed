<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fork feature: Immich library. See docs/fork/IMMICH.md
 *
 * One Immich server per account. The API key is stored encrypted with the
 * app key; the rest is what the settings page shows about the connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('immich_connections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('base_url', 500);
            $table->text('api_key');

            // What the last successful check found, for the settings page
            $table->string('server_version', 32)->nullable();
            $table->string('immich_user_name', 191)->nullable();
            $table->string('immich_user_email', 191)->nullable();
            $table->json('permissions')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immich_connections');
    }
};
