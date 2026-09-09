# Geo feed

A map of posts, built on GPS coordinates read from uploaded photos, with the
nearest city suggested at compose time.

This is a **fork feature**. It is not upstream Pixelfed and never will be
unless it is proposed there separately. It is built to survive rebases onto
upstream: nearly all of it lives in files upstream does not have, and the
handful of upstream files it does touch are listed exhaustively below.

---

## Contents

- [What it does](#what-it-does)
- [Design decisions](#design-decisions)
- [Fork patch inventory](#fork-patch-inventory) ← **read this before rebasing**
- [Rebase procedure](#rebase-procedure)
- [Architecture](#architecture)
- [Configuration](#configuration)
- [Operating it](#operating-it)
- [API](#api)
- [Testing](#testing)
- [Known limitations](#known-limitations)

---

## What it does

1. **Captures photo coordinates.** When a photo is uploaded, GPS coordinates
   are read out of its EXIF and stored on the media row.
2. **Suggests a location.** The composer offers the nearest city, which the
   author can accept, replace, or decline. Posts published without a location
   get the nearest city assigned server-side, so the mobile apps and any other
   API client behave the same way with no client changes.
3. **Serves a map.** `/discover/map` shows public photo posts as pins,
   clustered when zoomed out.

## Design decisions

### Coordinates are read before the strip, not by keeping metadata

The original brief was to stop stripping photo metadata so coordinates could
be read from published files. This does the same job without that trade-off.

Stripping is a side effect of `App\Util\Media\Image::handleImageTransform()`
re-encoding uploads through Intervention Image, and for JPEG/PNG/WebP it
writes back over the original path. That happens in `ImageOptimize`, a queued
job dispatched *after* the `Media` row is saved — so the upload still has its
EXIF at the moment the row is created, and `MediaGeoObserver::created` reads it
there.

Result: coordinates end up in the database, where they can be scoped,
coarsened, and deleted, while published images stay stripped. Enabling the map
does not start handing everyone who downloads a photo the GPS of the author's
home. If you *do* want full EXIF passed through to published files, that is a
separate change to the resize pipeline and is not implemented here.

### City precision by default

`geo.precision.default` is `city`: a pin sits at the centre of the matched
city, not where the shutter was pressed. Authors can opt a single post up to
`exact` from the composer, and can decline location for a post entirely.

Exact coordinates on a photo taken at home are a home address. Defaulting to
that and relying on people to notice is not a defensible default for a photo
sharing platform.

### No third-party geocoder

Upstream already ships a dataset of ~128k cities with coordinates
(`storage/app/cities.json`, loaded by `php artisan import:cities` into
`places`). Reverse geocoding runs against that table.

This keeps the feature self-hosted, means photo coordinates never leave the
instance, adds no API key or rate limit to operate, and — the reason it is
genuinely the right choice rather than merely the cheap one — produces a real
`place_id`, so suggestions feed straight into upstream's existing location
pages, `place` API field and location search.

### Model observers instead of controller edits

The two moments that matter — an upload being stored, and media being attached
to a post — are both observable on the `Media` model. Hooking there instead of
editing `ComposeController` and `Api\ApiV1Controller` means:

- the web composer, the Mastodon API and any future upload path are covered at
  once;
- the hooks live in files upstream does not have, so they cannot conflict.

### Its own page, not an SPA route

The map is `/discover/map`, a normal Blade page with its own bundle, rather
than a route inside the Vue SPA. `resources/assets/js/spa.js` and
`sidebar.vue` are two of the files upstream changes most often, and a
component registry entry there would conflict on most rebases.

Every route the feature adds is at least two path segments deep. `routes/web.php`
ends with `Route::get('{username}', ...)`, a single-segment catch-all, and a
two-segment path cannot be shadowed by it — so the routes resolve correctly no
matter which order the service providers boot in.

---

## Fork patch inventory

### Files upstream does not have — safe on rebase

```
app/Geo/**                                       the whole feature
config/geo.php                                   configuration
routes/geo.php                                   routes
database/migrations/2026_09_09_1000*.php         three additive migrations
resources/assets/components/geo/GeoFeed.vue      the map
resources/assets/components/geo/GeoSuggest.vue   composer suggestion card
resources/assets/js/geo.js                       bundle entry point
resources/assets/sass/geo.scss                   map styles + Leaflet CSS
resources/views/geo/index.blade.php              the map page
tests/Unit/Geo/**                                tests
docs/fork/GEO_FEED.md                            this file
```

### Upstream files touched — check each one after a rebase

Every insertion is marked with a `pf-geo:` comment, so
`git grep -n 'pf-geo:'` lists them all — 12 hits across 7 files. The eighth,
`package.json`, is JSON and cannot carry a comment, so check it by hand.

Eight files, ~50 inserted lines, nothing removed or rewritten.

| File | Change | If the rebase eats it |
|---|---|---|
| `config/app.php` | Registers `App\Geo\GeoServiceProvider` in `providers`. | **Everything stops.** Re-add it; position in the array does not matter. |
| `webpack.mix.js` | Adds `geo.js` and `geo.scss` to the build. | Map page 500s on `mix('js/geo.js')`. Re-add both lines. |
| `package.json` | Adds `leaflet` to `dependencies`. | Build fails resolving `leaflet`. Re-add and `npm install`. |
| `app/Util/Site/Config.php` | Adds `features.geo` to the frontend config. | SPA sidebar link disappears. Nothing else. |
| `resources/views/layouts/partial/nav.blade.php` | Adds a nav item. | Link disappears; `/discover/map` still works. |
| `resources/assets/components/partials/sidebar.vue` | Adds a nav item and `hasPhotoMap`. | As above. |
| `resources/assets/js/components/ComposeModal.vue` | Three insertions: import, `components` entry, one `<geo-suggest>` tag. | Composer stops suggesting locations. Posts still get one assigned server-side on publish, so this degrades rather than breaks. |
| `.env.example` | Commented documentation block. | Cosmetic. |

Note the shape of that table: the only entry that stops the feature working is
a one-line provider registration. Everything else degrades to "still works,
looks slightly less finished".

---

## Rebase procedure

```bash
git fetch upstream
git rebase upstream/dev            # or: git merge upstream/dev
```

Conflicts, if any, will be in the eight files above — they are all small
insertions, so take upstream's version of the surrounding code and re-apply
the `pf-geo:` block.

Then verify:

```bash
# 1. Insertion points still present (expect 12 hits across 7 files)
git grep -n 'pf-geo:'

# 2. The provider is registered, and leaflet is still a dependency
grep -n 'GeoServiceProvider' config/app.php
grep -n 'leaflet' package.json

# 3. Routes resolve, and nothing upstream added shadows them
php artisan route:list | grep -E 'discover/map|api/geo'

# 4. Tests
./vendor/bin/pest tests/Unit/Geo

# 5. Build
npm install && npm run production
```

### What to re-check when upstream changes specific things

The feature makes assumptions about upstream behaviour. These are the ones
worth re-verifying when the surrounding code changes, and the practical
consequence if the assumption breaks:

| Assumption | Where it matters | Symptom if broken |
|---|---|---|
| `Media` rows are saved before `ImageOptimize` is dispatched | `MediaGeoObserver::created` | Coordinates stop being found: the resize wins the race. Photos get no location. |
| Media is attached to a status by setting `status_id` on the media row | `MediaGeoObserver::updated` | Posts stop getting pinned. |
| `Status::$type` is written after attachments are saved | `StatusGeoObserver::updated` | Nothing: the delayed media-observer job still covers it. |
| `places` carries `lat`/`long` for cities | `ReverseGeocoder` | No suggestions. Check `php artisan import:cities` has been run. |
| `UserFilterService::filters()` returns blocked/muted profile ids | `GeoFeedService::posts` | Blocked accounts become visible on the map. |
| `StatusService::get()` returns `media_attachments` and `account` | `GeoFeedService::preview` | Pins vanish (previews are skipped, not fatal). |
| The composer keeps `media` and `place` on its root component | `ComposeModal.vue` insertion | Suggestion card stops appearing. |

If a new upload path appears upstream that does **not** go through the `Media`
model, it will need its own hook.

---

## Architecture

```
upload
  │
  ├─ Media::created ──▶ MediaGeoObserver ──▶ MediaGeoService::extract
  │                                            └─ ExifGpsReader (TIFF/GPS IFD)
  │                                            └─ media.geo_lat / geo_lng
  │                     ┌── runs BEFORE ImageOptimize strips the file
  │
compose ─ GET /api/geo/v1/compose/suggest ──▶ ReverseGeocoder ──▶ places
  │       PUT /api/geo/v1/compose/media/:id ─▶ media.geo_precision
  │
publish
  │
  ├─ Media::updated (status_id set) ─▶ ResolveStatusGeoJob (+5s)
  ├─ Status::updated (type/scope)   ─▶ ResolveStatusGeoJob
  │                                     └─ StatusGeoService::resolve
  │                                          ├─ ReverseGeocoder → place_id
  │                                          └─ statuses.geo_lat / geo_lng
  │
map ─ GET /api/geo/v1/feed?bbox=&zoom= ──▶ GeoFeedService
                                             ├─ zoom ≤ 12: SQL grid clusters
                                             └─ zoom > 12: posts + previews
```

Both publish triggers exist deliberately. `Status::updated` fires at exactly
the right moment in both the composer and the Mastodon API; the delayed media
hook is the fallback if upstream reorders that. `ResolveStatusGeoJob` is
idempotent and unique-per-status, so running twice costs nothing.

### Schema

```
media.geo_lat            decimal(10,7)  coordinates read from EXIF
media.geo_lng            decimal(10,7)
media.geo_precision      varchar(8)     city | exact | none  (author's choice)
media.geo_extracted_at   timestamp      set once read is attempted, pass or fail

statuses.geo_lat         decimal(10,7)  where the pin goes
statuses.geo_lng         decimal(10,7)
statuses.geo_precision   varchar(8)     city | exact
statuses.geo_source      varchar(8)     exif | place
                         + index (geo_lat, geo_lng)

places                   + index (lat, long)   for coordinate range scans
```

`places` already had the coordinates; the only index covering them started
with `slug`, so it could not serve a range scan.

### Why the EXIF reader does not use `ext-exif`

`exif_read_data()` cannot parse HEIC/HEIF at all — it returns `false`. HEIC is
the default capture format on every recent iPhone and therefore the single
biggest source of photo GPS. `App\Geo\Support\ExifGpsReader` locates the
embedded TIFF block (the container stops mattering once you have found it) and
walks the GPS IFD itself, which covers JPEG, HEIC, AVIF, WebP, PNG and TIFF
through one code path.

It parses untrusted uploads, so every read is bounds-checked and the IFD entry
count is capped. `tests/Unit/Geo/ExifGpsReaderTest.php` fuzzes it, and the
fixture it uses is cross-validated against `exif_read_data()` for the JPEG
cases — so the encoder and decoder are known to agree with the reference
implementation rather than merely with each other.

---

## Configuration

All keys live in `config/geo.php`; the env vars are documented in
`.env.example`. The defaults are sensible for a public instance.

| Key | Default | Notes |
|---|---|---|
| `geo.enabled` | `true` | Master switch. Off: no routes, no observers, no EXIF reads. |
| `geo.exif.enabled` | `true` | Read GPS from uploads. |
| `geo.exif.inline` | `true` | Read during the upload request. **Keep this on for local storage** — see below. |
| `geo.exif.max_read_bytes` | `8388608` | HEIC keeps its EXIF item in `mdat`, sometimes megabytes in. |
| `geo.autotag.enabled` | `true` | Assign the nearest city when the author sets none. |
| `geo.autotag.max_distance_km` | `50` | Never guess a city further away than this. |
| `geo.precision.default` | `city` | `city` or `exact`. |
| `geo.precision.allow_exact` | `true` | Whether authors may opt a post up to exact. |
| `geo.feed.cluster_max_zoom` | `12` | Below this zoom, results are grid clusters. |
| `geo.feed.max_results` | `250` | Pins per viewport. |
| `geo.feed.cache_ttl` | `120` | Seconds to cache a viewport. |
| `geo.feed.max_age_days` | `0` | `0` = no limit. |
| `geo.map.tile_url` | OSM | Any `{z}/{x}/{y}` tile server. |

### `geo.exif.inline`

Inline extraction is race-free: it runs inside the upload request, before
`ImageOptimize` is dispatched. Queued extraction (`false`) can lose that race
on local storage, because the resize writes back over the original path — so
only turn it off if `filesystems.default` is a remote disk.

### Tile privacy

The default tile URL means every viewer's browser talks to
`tile.openstreetmap.org`, which sees their IP and roughly where they are
looking. Point `GEO_MAP_TILE_URL` at your own tile server or a proxy if that
matters for your instance.

---

## Operating it

### Enabling on an existing instance

```bash
php artisan migrate
php artisan import:cities        # only if `places` is empty
php artisan geo:backfill --places
npm install && npm run production
php artisan config:clear
```

`geo:backfill --places` pins every existing post that already has a
`place_id`, which is what makes the map useful on day one rather than after
people have posted for a month. It writes with the query builder rather than
Eloquent, so historical posts do not get their `updated_at` bumped —
that column drives federation and cache invalidation elsewhere.

```bash
php artisan geo:backfill --places              # historical posts → pins
php artisan geo:backfill --media               # re-read EXIF from stored files
php artisan geo:backfill --places --dry-run    # report only
php artisan geo:backfill --places --limit=1000
```

`--media` will have a low hit rate: anything the resize pipeline has already
touched lost its EXIF before this feature existed. It is worth running once
for recent uploads and for anything with `skip_optimize` set.

### Turning it off

`GEO_ENABLED=false` disables routes, observers and EXIF reads. Existing
coordinates stay in the database; drop the columns with the migrations if you
want them gone.

### Deleting coordinates

```sql
UPDATE media    SET geo_lat = NULL, geo_lng = NULL;
UPDATE statuses SET geo_lat = NULL, geo_lng = NULL, geo_precision = NULL, geo_source = NULL;
```

Then `php artisan cache:clear` to drop cached viewports.

---

## API

All endpoints require an authenticated session and are under
`/api/geo/v1`.

### `GET /feed`

```
?bbox=minLng,minLat,maxLng,maxLat   required
&zoom=0..20                         required
&limit=1..250                       optional
```

Returns clusters at or below `geo.feed.cluster_max_zoom`, individual posts
above it:

```json
{
  "mode": "clusters",
  "zoom": 5,
  "cell_size": 2.8125,
  "clusters": [{ "lat": 51.5, "lng": -0.12, "count": 412, "cover_id": "..." }],
  "posts": []
}
```

```json
{
  "mode": "posts",
  "zoom": 14,
  "posts": [{
    "id": "...", "lat": 51.5074, "lng": -0.1278, "precision": "city",
    "url": "...", "thumbnail": "...", "blurhash": "...", "sensitive": false,
    "place": { "id": "1", "name": "London", "country": "United Kingdom", "url": "..." },
    "account": { "id": "...", "username": "...", "avatar": "..." }
  }]
}
```

A bbox where `minLng > maxLng` is valid and means the viewport crosses the
antimeridian; the query splits the box.

### `GET /places/nearby?lat=&lng=&limit=`

Nearest cities, nearest first, each with `distance_km`.

### `GET /compose/suggest?ids=1,2,3`

Suggestion for a set of the caller's own draft uploads. Returns the nearest
city plus alternatives, or `{"available": false}`.

### `PUT /compose/media/{id}` — `{"precision": "city"|"exact"|"none"}`

Records the author's choice for one upload. `none` keeps the post off the map
**and discards the stored coordinates** — an author asking not to share their
location should not leave it sitting in the database.

---

## Testing

```bash
./vendor/bin/pest tests/Unit/Geo
```

`ExifGpsReaderTest` and `CoordinatesTest` are pure unit tests — no app, no
database — covering both byte orders, all four hemispheres, HEIC containers,
altitude, malformed input and a fuzz pass.

`ExifGpsFixture` builds EXIF blocks in code, so no binary photos are committed.
Its JPEG output is valid enough that `exif_read_data()` agrees with it, which
is what makes the reader's results trustworthy rather than self-confirming.

Not covered by automated tests, and worth checking by hand after a rebase:

- Upload a photo with GPS through the web composer; the suggestion card
  appears with the right city.
- "Somewhere else", "Don't add" and the exact-precision switch each do what
  they say.
- Publish from a mobile app with no location set; the post gets one anyway.
- `/discover/map` clusters when zoomed out and shows pins when zoomed in.
- A blocked account's posts do not appear on the map.

`ReverseGeocoder` and `GeoFeedService` are database-bound and untested; the
repository has no fixtures for a seeded `places` table.

---

## Known limitations

- **Cluster counts ignore blocks and mutes.** Cluster aggregates are shared
  across all viewers so they can be cached once. Individual pins are filtered
  per viewer. A blocked account can therefore contribute to a count without
  being visible in it.
- **Viewers with more than 250 blocks** get filtering applied after the query
  limit, so a viewport may return slightly fewer than `limit` pins.
- **No video GPS.** MP4 has a location atom; only images are read.
- **Remote posts are never pinned.** Federated posts carry no coordinates and
  ActivityPub `location` is not consumed. Everything on the map is local.
- **Nothing is federated.** `geo_lat`/`geo_lng` are not published in
  ActivityPub output, so other instances see the existing `place`/`location`
  behaviour and nothing more.
- **No admin UI.** Configuration is env vars only; there is no panel in
  `/i/admin`.
- **Author edits to precision after publishing** re-derive the pin, but the
  composer only offers that control before publishing.
