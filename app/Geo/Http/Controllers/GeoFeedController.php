<?php

namespace App\Geo\Http\Controllers;

use App\Geo\Services\GeoFeedService;
use App\Geo\Support\Coordinates;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Fork feature: geo feed. See docs/fork/GEO_FEED.md
 *
 * The map page and the viewport API behind it.
 */
class GeoFeedController extends Controller
{
    public function __construct(protected GeoFeedService $feed)
    {
        $this->middleware('auth');
    }

    /**
     * The map itself, served as its own page rather than an SPA route so the
     * feature stays clear of upstream's router and component registry.
     */
    public function index(Request $request)
    {
        abort_unless(config('geo.enabled'), 404);

        return view('geo.index', [
            'title' => __('Photo Map'),
            'geoConfig' => [
                'tileUrl' => config('geo.map.tile_url'),
                'tileAttribution' => config('geo.map.tile_attribution'),
                'minZoom' => (int) config('geo.map.min_zoom'),
                'maxZoom' => (int) config('geo.map.max_zoom'),
                'clusterMaxZoom' => (int) config('geo.feed.cluster_max_zoom'),
                'defaultLat' => (float) config('geo.map.default_lat'),
                'defaultLng' => (float) config('geo.map.default_lng'),
                'defaultZoom' => (int) config('geo.map.default_zoom'),
            ],
        ]);
    }

    /**
     * Posts within a map viewport, clustered or individual depending on zoom.
     */
    public function viewport(Request $request)
    {
        abort_unless(config('geo.enabled'), 404);
        abort_if(! $request->user(), 403);

        $this->validate($request, [
            'bbox' => 'required|string|max:128',
            'zoom' => 'required|integer|min:0|max:20',
            'limit' => 'nullable|integer|min:1|max:250',
        ]);

        $bbox = $this->parseBbox($request->input('bbox'));

        abort_if($bbox === null, 422, 'Invalid bbox, expected "minLng,minLat,maxLng,maxLat"');

        return response()->json($this->feed->viewport(
            $bbox,
            (int) $request->input('zoom'),
            $request->user()->profile_id,
            $request->input('limit') ? (int) $request->input('limit') : null
        ));
    }

    /**
     * "minLng,minLat,maxLng,maxLat" — the order Leaflet, GeoJSON and every
     * tile server agree on — into [minLat, minLng, maxLat, maxLng].
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    protected function parseBbox(string $bbox): ?array
    {
        $parts = explode(',', $bbox);

        if (count($parts) !== 4) {
            return null;
        }

        foreach ($parts as $part) {
            if (! is_numeric(trim($part))) {
                return null;
            }
        }

        [$minLng, $minLat, $maxLng, $maxLat] = array_map(fn ($v) => (float) trim($v), $parts);

        if (! Coordinates::isValidLat($minLat) || ! Coordinates::isValidLat($maxLat)) {
            return null;
        }

        if (! Coordinates::isValidLng($minLng) || ! Coordinates::isValidLng($maxLng)) {
            return null;
        }

        if ($minLat > $maxLat) {
            return null;
        }

        // minLng > maxLng is legitimate: the viewport crosses the
        // antimeridian, and the query splits the box to handle it.
        return [$minLat, $minLng, $maxLat, $maxLng];
    }
}
