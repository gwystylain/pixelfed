<?php

use App\Geo\Services\MediaGeoService;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Geo feed: the composer's location card (fork feature)
|--------------------------------------------------------------------------
|
| The web composer only uploads on publish, so it asks for a suggestion by
| the coordinates it read from the photo in the browser, and hands them to
| the upload afterwards for photos whose EXIF the editor stripped. See
| docs/fork/GEO_FEED.md.
|
*/

beforeEach(function () {
    Redis::spy();
    Queue::fake();
    Storage::fake('local');
    config([
        'geo.enabled' => true,
        'geo.exif.enabled' => true,
        'geo.autotag.max_distance_km' => 50,
        'geo.precision.default' => 'exact',
        'geo.precision.allow_exact' => true,
    ]);

    DB::table('places')->insert([
        ['slug' => 'london', 'name' => 'London', 'country' => 'United Kingdom', 'lat' => 51.507400, 'long' => -0.127800, 'score' => 10],
        ['slug' => 'croydon', 'name' => 'Croydon', 'country' => 'United Kingdom', 'lat' => 51.376200, 'long' => -0.098200, 'score' => 1],
    ]);
});

function geoDraftMedia(User $user, array $attributes = []): Media
{
    $media = new Media;
    $media->user_id = $user->id;
    $media->profile_id = $user->profile_id;
    $media->media_path = 'public/m/_v2/'.$user->profile_id.'/geo/test.jpg';
    $media->mime = 'image/jpeg';
    $media->size = 100;
    $media->save();

    if ($attributes) {
        $media->forceFill($attributes)->saveQuietly();
    }

    return $media->refresh();
}

it('suggests the nearest city for coordinates read in the browser', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/geo/v1/compose/suggest?lat=51.5007&lng=-0.1246')
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('media_id', null)
        ->assertJsonPath('place.name', 'London')
        ->assertJsonPath('alternatives.0.name', 'Croydon')
        ->assertJsonPath('precision', 'exact')
        ->assertJsonPath('allow_exact', true);
});

it('suggests nothing at null island or with EXIF reading turned off', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/geo/v1/compose/suggest?lat=0&lng=0')
        ->assertOk()
        ->assertJsonPath('available', false);

    config(['geo.exif.enabled' => false]);

    $this->actingAs($user)
        ->getJson('/api/geo/v1/compose/suggest?lat=51.5007&lng=-0.1246')
        ->assertOk()
        ->assertJsonPath('available', false);
});

it('needs either media ids or a whole coordinate pair', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/geo/v1/compose/suggest')->assertStatus(422);
    $this->actingAs($user)->getJson('/api/geo/v1/compose/suggest?lat=51.5')->assertStatus(422);
    $this->actingAs($user)->getJson('/api/geo/v1/compose/suggest?lat=91&lng=0')->assertStatus(422);
});

it('still suggests by media id', function () {
    $user = User::factory()->create();
    $media = geoDraftMedia($user, ['geo_lat' => 51.5007, 'geo_lng' => -0.1246]);

    $this->actingAs($user)
        ->getJson('/api/geo/v1/compose/suggest?ids='.$media->id)
        ->assertOk()
        ->assertJsonPath('media_id', (string) $media->id)
        ->assertJsonPath('place.name', 'London');
});

it('fills in coordinates for a draft whose upload lost them', function () {
    $user = User::factory()->create();
    $media = geoDraftMedia($user);

    $this->actingAs($user)
        ->putJson('/api/geo/v1/compose/media/'.$media->id, ['lat' => 51.5007, 'lng' => -0.1246])
        ->assertOk()
        ->assertJsonPath('located', true)
        ->assertJsonPath('precision', null);

    $media->refresh();
    expect((float) $media->geo_lat)->toBe(51.5007)
        ->and((float) $media->geo_lng)->toBe(-0.1246)
        ->and($media->geo_precision)->toBeNull();
});

it('records a precision and coordinates together', function () {
    $user = User::factory()->create();
    $media = geoDraftMedia($user);

    $this->actingAs($user)
        ->putJson('/api/geo/v1/compose/media/'.$media->id, ['lat' => 51.5007, 'lng' => -0.1246, 'precision' => 'city'])
        ->assertOk();

    $media->refresh();
    expect($media->geo_precision)->toBe('city')
        ->and((float) $media->geo_lat)->toBe(51.5007);
});

it('never overwrites what the server read from the file', function () {
    $user = User::factory()->create();
    $media = geoDraftMedia($user, ['geo_lat' => 48.8584, 'geo_lng' => 2.2945]);

    $this->actingAs($user)
        ->putJson('/api/geo/v1/compose/media/'.$media->id, ['lat' => 51.5007, 'lng' => -0.1246])
        ->assertOk();

    expect((float) $media->refresh()->geo_lat)->toBe(48.8584);
});

it('does not move the pin of a published post', function () {
    $user = User::factory()->create();
    $media = geoDraftMedia($user, ['status_id' => 123456]);

    $this->actingAs($user)
        ->putJson('/api/geo/v1/compose/media/'.$media->id, ['lat' => 51.5007, 'lng' => -0.1246])
        ->assertOk()
        ->assertJsonPath('located', false);

    expect($media->refresh()->geo_lat)->toBeNull();
});

it('ignores coordinates when EXIF reading is turned off', function () {
    config(['geo.exif.enabled' => false]);
    $user = User::factory()->create();
    $media = geoDraftMedia($user);

    $this->actingAs($user)
        ->putJson('/api/geo/v1/compose/media/'.$media->id, ['lat' => 51.5007, 'lng' => -0.1246])
        ->assertOk()
        ->assertJsonPath('located', false);
});

it('keeps a decline: coordinates gone, not read again, not refilled', function () {
    $user = User::factory()->create();
    $media = geoDraftMedia($user, ['geo_lat' => 51.5007, 'geo_lng' => -0.1246, 'geo_extracted_at' => null]);

    $this->actingAs($user)
        ->putJson('/api/geo/v1/compose/media/'.$media->id, ['precision' => 'none'])
        ->assertOk()
        ->assertJsonPath('precision', 'none')
        ->assertJsonPath('located', false);

    $media->refresh();
    expect($media->geo_lat)->toBeNull()
        ->and($media->geo_extracted_at)->not->toBeNull()
        ->and(app(MediaGeoService::class)->shouldExtract($media))->toBeFalse();

    $this->actingAs($user)
        ->putJson('/api/geo/v1/compose/media/'.$media->id, ['lat' => 51.5007, 'lng' => -0.1246])
        ->assertOk()
        ->assertJsonPath('located', false);
});

it('does not queue an EXIF read for an upload already declined', function () {
    $user = User::factory()->create();
    $media = geoDraftMedia($user, ['geo_precision' => 'none', 'geo_extracted_at' => null]);

    expect(app(MediaGeoService::class)->shouldExtract($media))->toBeFalse();
});

it('only touches the author\'s own uploads', function () {
    $author = User::factory()->create();
    $someoneElse = User::factory()->create();
    $media = geoDraftMedia($author);

    $this->actingAs($someoneElse)
        ->putJson('/api/geo/v1/compose/media/'.$media->id, ['lat' => 51.5007, 'lng' => -0.1246])
        ->assertNotFound();

    expect($media->refresh()->geo_lat)->toBeNull();
});

it('needs a precision or coordinates', function () {
    $user = User::factory()->create();
    $media = geoDraftMedia($user);

    $this->actingAs($user)->putJson('/api/geo/v1/compose/media/'.$media->id, [])->assertStatus(422);
    $this->actingAs($user)->putJson('/api/geo/v1/compose/media/'.$media->id, ['lat' => 51.5])->assertStatus(422);
});
