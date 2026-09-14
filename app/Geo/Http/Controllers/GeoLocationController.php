<?php

namespace App\Geo\Http\Controllers;

use App\Geo\Jobs\ResolveStatusGeoJob;
use App\Geo\Services\AddressGeocoder;
use App\Geo\Services\GeoFeedService;
use App\Geo\Services\ReverseGeocoder;
use App\Geo\Services\StatusGeoService;
use App\Geo\Support\Coordinates;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Status;
use App\Services\StatusService;
use Illuminate\Http\Request;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * Location suggestion for the composer.
 *
 * The suggestion is advisory: it returns a place in exactly the shape the
 * existing composer already uses for `place`, so accepting it is a local
 * assignment and overriding it goes through upstream's existing location
 * search. Nothing here is required for a post to get a location — the
 * server assigns the same suggestion on publish if the client stays quiet,
 * which is how the mobile apps get this for free.
 */
class GeoLocationController extends Controller
{
    /** Media ids accepted in one suggestion lookup. */
    const MAX_MEDIA_IDS = 10;

    public function __construct(protected ReverseGeocoder $geocoder)
    {
        $this->middleware('auth');
    }

    /**
     * Cities near a coordinate pair, nearest first.
     */
    public function nearby(Request $request)
    {
        abort_unless(config('geo.enabled'), 404);
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'limit' => 'nullable|integer|min:1|max:25',
        ]);

        $places = $this->geocoder->nearby(
            (float) $request->input('lat'),
            (float) $request->input('lng'),
            (int) ($request->input('limit') ?? 5)
        );

        return response()->json([
            'places' => array_map([$this, 'presentPlace'], $places),
        ]);
    }

    /**
     * Suggest a location for a set of draft uploads.
     *
     * Returns the coordinates of the first attachment that has any, the
     * nearest city, and the next few alternatives so the author can correct
     * a wrong guess without typing.
     */
    public function suggest(Request $request)
    {
        abort_unless(config('geo.enabled'), 404);
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'ids' => 'required|string|max:256',
        ]);

        $ids = collect(explode(',', (string) $request->input('ids')))
            ->map(fn ($id) => trim($id))
            ->filter(fn ($id) => ctype_digit($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(self::MAX_MEDIA_IDS)
            ->values();

        abort_if($ids->isEmpty(), 422, 'No valid media ids');

        $media = Media::whereProfileId($request->user()->profile_id)
            ->whereIn('id', $ids->all())
            ->whereNotNull('geo_lat')
            ->orderBy('order')
            ->orderBy('id')
            ->first();

        if (! $media || ! Coordinates::isValid($media->geo_lat, $media->geo_lng)) {
            return response()->json([
                'available' => false,
                'media_id' => null,
                'place' => null,
                'alternatives' => [],
            ]);
        }

        // Author already opted this upload out; do not keep offering it.
        if ($media->geo_precision === StatusGeoService::PRECISION_NONE) {
            return response()->json([
                'available' => false,
                'media_id' => (string) $media->id,
                'place' => null,
                'alternatives' => [],
            ]);
        }

        $nearby = $this->geocoder->nearby(
            (float) $media->geo_lat,
            (float) $media->geo_lng,
            5,
            (float) config('geo.autotag.max_distance_km', 50)
        );

        return response()->json([
            'available' => ! empty($nearby),
            'media_id' => (string) $media->id,
            'precision' => $media->geo_precision ?? config('geo.precision.default'),
            'allow_exact' => (bool) config('geo.precision.allow_exact'),
            'place' => isset($nearby[0]) ? $this->presentPlace($nearby[0]) : null,
            'alternatives' => array_map(
                [$this, 'presentPlace'],
                array_slice($nearby, 1)
            ),
        ]);
    }

    /**
     * Record the author's choice for one upload: pin at the city centre, pin
     * at the exact coordinates, or keep the post off the map altogether.
     *
     * Opting out discards the stored coordinates rather than just flagging
     * them — an author asking not to share their location should not leave
     * it sitting in the database.
     */
    public function updateMedia(Request $request, $id)
    {
        abort_unless(config('geo.enabled'), 404);
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'precision' => 'required|string|in:city,exact,none',
        ]);

        abort_if(! ctype_digit((string) $id), 422);

        $media = Media::whereProfileId($request->user()->profile_id)
            ->findOrFail((int) $id);

        $precision = $request->input('precision');

        if ($precision === StatusGeoService::PRECISION_EXACT && ! config('geo.precision.allow_exact')) {
            abort(422, 'Exact coordinates are disabled on this instance');
        }

        $media->geo_precision = $precision;

        if ($precision === StatusGeoService::PRECISION_NONE) {
            $media->geo_lat = null;
            $media->geo_lng = null;
        }

        $media->saveQuietly();

        // Normally this is called on a draft, before the post exists. If the
        // post is already published the change has to be pushed through to
        // its pin, which saveQuietly deliberately did not do.
        if ($media->status_id) {
            ResolveStatusGeoJob::dispatch((int) $media->status_id, true)->onQueue('feed');
        }

        return response()->json([
            'media_id' => (string) $media->id,
            'precision' => $media->geo_precision,
        ]);
    }

    /**
     * Text to coordinates, for the map's "edit location" box.
     *
     * A pasted coordinate pair comes back as itself; everything else goes to
     * whichever geocoder is configured. See docs/fork/GEO_FEED.md.
     */
    public function geocode(Request $request, AddressGeocoder $geocoder)
    {
        abort_unless(config('geo.enabled'), 404);
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'q' => 'required|string|min:2|max:180',
            'limit' => 'nullable|integer|min:1|max:10',
        ]);

        return response()->json([
            'driver' => $geocoder->driver(),
            'results' => $geocoder->search(
                (string) $request->input('q'),
                (int) ($request->input('limit') ?? 5)
            ),
        ]);
    }

    /**
     * Put a post's pin where its author says it belongs.
     *
     * The whole point is that the camera can be wrong, so this outranks
     * everything derived: it writes `geo_source = manual`, and resolve()
     * refuses to touch a post carrying that.
     */
    public function updateStatusLocation(Request $request, $id)
    {
        abort_unless(config('geo.enabled'), 404);
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');

        // between: lets 0,0 through, and Coordinates does not consider that
        // a place — it is what a camera writes when it has no fix.
        abort_unless(Coordinates::isValid($lat, $lng), 422, 'That is not a usable coordinate');

        // Scoped to the author: this is "fix my own post", and an admin
        // moving someone else's pin is a different feature with different
        // consequences.
        $status = Status::whereProfileId($request->user()->profile_id)
            ->findOrFail((int) $id);

        abort_unless(
            in_array($status->type, StatusGeoService::MAPPABLE_TYPES, true),
            422,
            'This post cannot appear on the map'
        );

        $status->geo_lat = $lat;
        $status->geo_lng = $lng;
        $status->geo_precision = StatusGeoService::PRECISION_EXACT;
        $status->geo_source = StatusGeoService::SOURCE_MANUAL;

        // Quietly: the post itself has not been edited, so this should not
        // bump updated_at and federate as a new version of it.
        $status->saveQuietly();

        StatusService::del($status->id);
        GeoFeedService::flush();

        return response()->json($this->presentStatusLocation($status));
    }

    /**
     * Drop a hand placed pin and go back to what the photo says.
     *
     * Run inline rather than queued so the answer in the response is the
     * position the map is about to show.
     */
    public function resetStatusLocation(Request $request, $id, StatusGeoService $statusGeo)
    {
        abort_unless(config('geo.enabled'), 404);
        abort_if(! $request->user(), 403);

        $status = Status::whereProfileId($request->user()->profile_id)
            ->findOrFail((int) $id);

        $statusGeo->clear($status);
        $statusGeo->resolve($status->refresh(), true);

        return response()->json($this->presentStatusLocation($status->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentStatusLocation(Status $status): array
    {
        return [
            'status_id' => (string) $status->id,
            'lat' => $status->geo_lat !== null ? (float) $status->geo_lat : null,
            'lng' => $status->geo_lng !== null ? (float) $status->geo_lng : null,
            'precision' => $status->geo_precision,
            'source' => $status->geo_source,
        ];
    }

    /**
     * The shape upstream's composer already uses for `place`, plus distance
     * so the UI can say how far off the guess might be.
     *
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    protected function presentPlace(array $place): array
    {
        return [
            'id' => $place['id'],
            'name' => $place['name'],
            'country' => $place['country'],
            'url' => url('/discover/places/'.$place['id'].'/'.$place['slug']),
            'lat' => $place['lat'],
            'lng' => $place['lng'],
            'distance_km' => $place['distance_km'],
        ];
    }
}
