# Geo feed

A map of posts, built on GPS coordinates read from uploaded photos, with the
nearest city suggested at compose time, and any post on it openable beside the
map without leaving the page.

This is a **fork feature**. It is not upstream Pixelfed and never will be
unless it is proposed there separately. It is built to survive rebases onto
upstream: nearly all of it lives in files upstream does not have, and the
handful of upstream files it does touch are listed exhaustively below.

---

## Contents

- [What it does](#what-it-does)
- [Design decisions](#design-decisions)
- [The post pane](#the-post-pane)
- [The date filter](#the-date-filter)
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
4. **Opens a post beside the map.** Clicking a pin splits the page: map on one
   side, the post on the other. It is upstream's own feed components in there,
   so it can be liked, commented on, shared, bookmarked, reported or edited
   exactly as it could in the feed, and the map stays where it was.
5. **Filters by date.** A two-handled slider in the bar, spanning the oldest
   post on the map to today, with presets for the last 30 days, 6 months and
   year.

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

### Exact precision by default

`geo.precision.default` is `exact`: a pin sits where the shutter was pressed,
when the photo knows where that was. Authors can drop a single post to `city`,
or off the map entirely, from the composer.

This reverses the original default, and the argument for that default has not
been refuted — exact coordinates on a photo taken at home are a home address,
and defaulting to that on an instance full of strangers would not be
defensible. What changed is who the instance is for. It is one person's, and
at city precision a map is barely a map: every photo from one town lands on
the same pixel, so a dozen posts collapse into one pin reading "12" that says
nothing about where any of them were.

`GEO_PRECISION_DEFAULT=city` restores the cautious behaviour and `allow_exact`
is untouched, so the opt-down path is exactly as it was.

Note what the setting cannot do: it selects the most precise source available,
it does not invent one. A post whose photos carry no GPS is still pinned at its
city, because that is all there is to pin it at.

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

The bill for this choice comes due in [The post pane](#the-post-pane): the page
has to supply two things the SPA would have provided. It is still the cheaper
side of the trade — that plumbing lives entirely in fork-owned files, where a
component registry entry would not.

---

## The post pane

### It renders upstream's components, not a copy of them

A post opened from a pin has to behave like a post in the feed: same reaction
bar, same comment thread with replies and mentions, same context menu, same
report and edit dialogs. The only way to be sure of that — this release and
every release after it — is to render the same components. So the pane mounts
upstream's `TimelineStatus.vue`, `ContextMenu.vue`, `LikeModal.vue`,
`ShareModal.vue`, `ReportPost.vue` and `PostEditModal.vue` directly, and its own
file is little more than the event wiring around them.

That wiring is the **union** of what upstream's `Post.vue` and `Timeline.vue`
handle. Neither listens for everything `TimelineStatus` and `ContextMenu` emit —
`Post.vue` ignores `handle-report` and `mod-tools`, `Timeline.vue` ignores
`pinned`/`unpinned` — and an unhandled emit is a menu item that silently does
nothing, so the union is the only safe set.

### What the components need, and where it comes from

They are SPA components, and they reach for three things a Blade page has not
got:

| Reach | Used for |
|---|---|
| `this.$store` | `state.hideCounts`, `state.fixedHeight`, `state.autoloadComments`, `state.newReactions`; getters `getRelationship`, `getCustomEmoji`; mutation `updateRelationship` |
| `this.$router` | `push()`, always to a profile or a permalink; `currentRoute.name`; `$route.params` |
| Global components | `PostContent.vue` resolves `<photo-presenter>` and the three album presenters globally, the way `spa.js` registers them |

`resources/assets/js/geo/spa-bridge.js` supplies both, on `Vue.prototype` —
`App.boot()` in `app.js` creates this page's Vue root and there is no way to
hand it a `store` option without editing that file.

- **Store.** The subset of `spa.js`'s store these components read, off the same
  `pf_m2s.*` localStorage keys, so a viewer who turned counts off in the feed
  sees them off here. `spa.js`'s `set*` mutations and `setColorScheme` are
  deliberately absent: nothing on this page can reach the settings UI, and
  `setColorScheme` rewrites `document.body.className`, which here belongs to the
  layout.
- **Router.** A shim whose `push()` is `window.location.href`. Every push in
  these components leaves the page anyway, so a real navigation is the correct
  behaviour rather than a fallback. `currentRoute` and `$route.params` are
  present and empty, which is what `ContextMenu` and `PostEditModal` read them
  for: "are we already on the permalink?" — on the map, never.
- **Presenters.** `resources/assets/js/geo/post-presenters.js` repeats spa.js's
  media registrations. Vue 2 does not walk up the parent chain to resolve a
  component, so registering them on the pane would not reach `PostContent.vue`
  two levels down — they have to be global here too. It is imported from
  `GeoPostPane.vue`, so they land in the pane's chunk rather than the map's.
  Miss this and every photo in the pane is a silent blank: the tag resolves to
  nothing and Vue only warns.

No library is new to the page for this. Vue, Vuex, bootstrap-vue, vue-timeago,
vue-blurhash, vue-carousel and vue-infinite-loading are all in `vendor.js`,
which every page loads, and `components.js` has already installed the plugins.
The pane's own code is a separate chunk, so a viewer who only browses the map
never fetches it.

### Styles

The post components colour themselves from custom properties — `--card-bg`,
`--comment-bg`, `--border-color` and twenty more — that **only `spa.css`
declares**. `app.css` has none of them. So `resources/views/geo/index.blade.php`
loads `spa.css` ahead of `geo.css`.

`spa.css` chooses its light or dark set from `prefers-color-scheme`, but this
page was themed by the `dark-mode` cookie in `layouts/app.blade.php`. Left
alone, a viewer on a light instance with a dark OS gets a dark post card on a
light page. The view therefore adds `force-light-mode` or `force-dark-mode` to
`<html>` from the same cookie; `spa.scss` declares those classes after the media
query, so they win on source order.

Side effect, accepted: the map page's own chrome picks up `spa.css`'s body font
and background. It is the modern UI's stylesheet and the page looks more like
the rest of the app for it.

### The split itself

- Map and pane are flex **siblings**, not an overlay, so opening the pane
  genuinely shrinks the map. Leaflet is told with `invalidateSize()`, then the
  clicked pin is panned into what is left of the viewport. There is no width
  transition on purpose: a mid-animation `invalidateSize()` measures the wrong
  size.
- Pins with one post open the pane on click. Pins with several keep the popup as
  the picker — at city precision one pin can hold a whole town — and a thumbnail
  opens the pane. The popup's tiles keep their real `href`, so a middle click or
  a long press still gets the permalink.
- The pane carries prev/next across the posts at its pin, which is why the
  gallery is passed in rather than refetched.
- The open post is in the URL as `?post=<id>`, pushed with `history.pushState`,
  so it is linkable and Back closes the pane. Prev/next *replaces* instead:
  walking a pin's posts should not make Back a dozen presses. The id is checked
  against `/^[0-9]+$/` before it reaches an API path.
- Deleting, archiving or unlisting a post removes its pin client-side rather
  than refetching. Viewports are cached per viewer for `geo.feed.cache_ttl`
  seconds, so a refetch would hand the same pin straight back.
- Under 768px there is no useful side-by-side, so the split stacks: map on top
  at 38%, post below, and a button in the pane hides the map altogether. That
  state overlays rather than resizes, so Leaflet needs telling nothing and the
  map is exactly where it was on the way back.

---

## The date filter

### The slider spans the data, not a fixed window

Its left-hand end is `MIN(created_at)` over exactly the set of posts the map
draws — `GeoFeedService::mappableQuery()`, the non-spatial half of the viewport
query, which is why that half is split out. A fixed "last five years" would be
wrong in both directions on a real instance: `geo:backfill --places` pins posts
from years before this feature existed, so a fixed window either hides them or
offers years of empty track.

That costs one `MIN()` per page load, cached for an hour. It only moves when
the oldest pinned post is deleted. `Cache::remember` treats a null as a miss,
so "no pinned posts" is cached as an empty string instead — otherwise an
instance with an empty map rescans on every page load, which is the one case
where the scan is pure waste.

When there are no pinned posts at all the control is not rendered. A slider
with no honest end to it is worse than no slider.

### Handles are day offsets; the wire format is calendar days

The slider works in integers — days since the oldest post — because that is
what two `<input type="range">` elements can carry. It sends `from` and `to` as
`Y-m-d`, and `App\Geo\Support\DateWindow` turns those back into an inclusive
range: `from` at 00:00:00, `to` at 23:59:59. Somebody who drags to 1 March
means the photographs taken on 1 March.

A handle parked at the end of its track sends **no parameter at all** rather
than the oldest date or today. Two reasons:

- The default range then asks the API exactly what it asked before this filter
  existed, down to the cache key, so nothing regresses for a viewer who never
  touches the slider.
- The oldest date is a UTC calendar day, and the slider computes in the
  viewer's local one. At the extremes those can disagree by a day, and "no
  bound" cannot be off by a day.

`DateWindow` also owns the cache key, in the same class as the parsing. Two
requests for the same range must land on the same cached viewport, which means
"the same range" has to mean the same thing to the key as it does to the query.
It swaps a reversed range rather than returning an empty map, and reads an
unparseable date as absent — the controller has already rejected malformed
input with a 422 by then, so anything reaching it has been through validation.

`tests/Unit/Geo/DateWindowTest.php` covers all of that without a database,
which is the reason the parsing is a value object rather than four lines in the
controller.

### Two native range inputs, no slider library

The control is two `<input type="range">` elements stacked on one track, not a
dependency. `vue-slider-component` and friends are each a package, a build, a
peer-dependency argument with `--legacy-peer-deps`, and a thing to re-check on
every upstream bump — for a control that is two inputs and thirty lines of CSS.
Keyboard support and touch targets come free with the native element, which is
most of what a library would have been bought for.

What it costs is one piece of CSS cunning: the inputs are `pointer-events:
none` so neither shadows the other, with `pointer-events: auto` on both thumb
pseudo-elements so the thumbs still take a drag. If a browser ever declines
that on `::-moz-range-thumb`, the fallbacks are already there — clicking the
bare track moves the nearer handle (`onTrackClick`, standard slider behaviour
in its own right), the handles still take arrow keys, and the presets never
needed the slider at all.

Handles **push** rather than block. Blocking leaves whichever input is on top
unable to move when the two sit on the same day, which on a range input they
regularly do; pushing can never strand either of them.

The fill between the handles is positioned inline, in `calc()`, inset by half a
handle width at each end — that is where a range input puts the centre of its
thumb at the extremes, and without the inset the fill drifts off the handles.
`HANDLE_PX` in `GeoFeed.vue` and `$geo-handle` in `geo.scss` are the same
number in two places; change one and the fill stops lining up.

### The label's width is fixed, and that is load bearing

The range label sits between the slider and the presets, and its text changes
as the handles move: "All dates" measures 48px, "Apr 10, 2024 - Sep 14, 2026"
measures 161px. Left to size itself it made the group 113px wider mid-drag,
and because the group is pushed rightwards by the status text it is the
*left* edge that moves - so the slider slid out from under the cursor while
being dragged. Near a wrap threshold (around 850px) it was worse: the group
jumped between the first and second row of the bar on alternate frames.

So the label is `flex: 0 0 11.5rem` with the overflow clipped rather than
allowed to grow, which makes the group's width constant in every state and in
every locale. Give it back its intrinsic width and the flicker returns.

### In the bar, wrapping rather than hiding

`.geo-feed__bar` wraps. On a wide screen the whole filter sits on the line with
the title and the "near me" button; narrower, it drops to a line of its own;
under 768px it becomes two, with the dates and presets together and the slider
full width below them. A 140px dual-range spanning several years is not a touch
target — a full width one is. Nothing is hidden at any size: the presets are
the point of the control on a phone, and the slider is still there.

### It does not survive a reload

Deliberately. A remembered range is either absolute — so "last 30 days" chosen
three weeks ago silently means a month-old window today — or relative, which
means storing which preset was active and recomputing, and then a dragged range
cannot be stored at all. Neither is worth the confusion of landing on a map
that looks empty. The filter resets to All, which is the whole map.

### `max_age_days` is a ceiling, not a default

`geo.feed.max_age_days` still applies underneath, so an instance that caps its
map at 90 days cannot be widened by asking for a year. It also bounds the
slider's left-hand end, because it is part of `mappableQuery()`.

### Clusters cache per window

Cluster counts cache globally rather than per viewer, and the window is now
part of that key. Presets produce identical windows for everybody, so they
still share a cache entry; a hand-dragged range gets its own. Preset windows
end today, so their keys turn over daily.

---

## Fork patch inventory

### Files upstream does not have — safe on rebase

```
app/Geo/**                                       the whole feature
config/geo.php                                   configuration
routes/geo.php                                   routes
database/migrations/2026_09_09_1000*.php         three additive migrations
resources/assets/components/geo/GeoFeed.vue      the map and the split view
resources/assets/components/geo/GeoPostPane.vue  a post beside the map
resources/assets/components/geo/GeoSuggest.vue   composer suggestion card
resources/assets/js/geo.js                       bundle entry point
resources/assets/js/geo/spa-bridge.js            store + $router for the pane
resources/assets/js/geo/post-presenters.js       global media registrations
resources/assets/sass/geo.scss                   map styles + Leaflet CSS
resources/views/geo/index.blade.php              the map page
tests/Unit/Geo/**                                Coordinates, ExifGpsReader,
                                                 DateWindow
docs/fork/GEO_FEED.md                            this file
```

### Upstream files touched — check each one after a rebase

Every insertion is marked with a `pf-geo:` comment, so
`git grep -n 'pf-geo:'` lists them all — 12 hits across 7 files. The eighth,
`package.json`, is JSON and cannot carry a comment, so check it by hand.

Eight files, ~50 inserted lines, nothing removed or rewritten.

The split view added none of them. It renders six upstream components and
loads `spa.css`, but it *imports* and *links* them from fork-owned files — the
count above is the same as it was before the pane existed.

| File | Change | If the rebase eats it |
|---|---|---|
| `bootstrap/providers.php` | Registers `App\Geo\GeoServiceProvider`. | **Everything stops.** Re-add it; position in the array does not matter. |
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

The fork tracks upstream **release tags**, not `upstream/dev`. Upstream's
`dev` is their integration line — `staging` merges into it continuously and
tags are cut from it — so anything fetched between tags has not been through
a release. Merging at tags means the conflict work happens once per release,
at a known point, with a changelog to consult.

```bash
git fetch upstream --tags
git tag --sort=-v:refname | grep -v -- -fork | head -1   # newest upstream release
git merge vX.Y.Z
```

Conflicts, if any, will be in the eight files above — they are all small
insertions, so take upstream's version of the surrounding code and re-apply
the `pf-geo:` block.

**A clean merge is not a working merge.** The feature's own files never
conflict, because upstream doesn't have them — which means git will happily
merge a release that breaks them. Both merges so far were "clean" and both
were broken:

- 0.12.10 moved `App\Media`, `App\Status`, `App\Place` to `App\Models\*`
  and deleted the `providers` array from `config/app.php`. Nine files in
  `app/Geo` and the provider registration had to move.
- 0.12.10 also removed the `validemail` and `twofactor` middleware aliases
  (`1d96c9405`). `routes/geo.php` named both; every geo route would have
  thrown.

So after every merge, diff the incoming range against the things the feature
*depends on*, not just the files it *edits*:

```bash
# What changed among the feature's dependencies?
git diff --stat <old-base> vX.Y.Z -- \
  bootstrap/app.php bootstrap/providers.php \
  app/Models/Media.php app/Models/Status.php app/Models/Place.php \
  app/Services/PlaceService.php app/Services/StatusService.php \
  app/Services/UserFilterService.php

# Every `use App\...` in the feature still resolves?
grep -rh '^use App' app/Geo routes/geo.php | sort -u

# Every middleware routes/geo.php names is still defined in bootstrap/app.php?
grep -oE "middleware\(\[[^]]+\]" routes/geo.php
```

Then verify:

```bash
# 1. Insertion points still present (expect 12 hits across 7 files)
git grep -n 'pf-geo:'

# 1b. Everything the post pane imports from upstream still exists, and
#     `$store`/`$router` are still all it reaches for
ls resources/assets/components/partials/TimelineStatus.vue    resources/assets/components/partials/StatusPlaceholder.vue    resources/assets/components/partials/post/{ContextMenu,LikeModal,ShareModal,PostEditModal}.vue    resources/assets/components/partials/modal/ReportPost.vue
grep -rho '\$store\.state\.[a-zA-Z]*\|commit(.[a-zA-Z]*\|\$router\.[a-zA-Z]*'   resources/assets/components/partials/TimelineStatus.vue   resources/assets/components/partials/post resources/assets/components/partials/profile   | sort -u

# 1c. Any component tag in the pane's tree that is neither registered locally,
#     provided by a plugin (b-*, timeago, carousel, infinite-loading), nor in
#     post-presenters.js needs adding there — it renders as nothing otherwise.
grep -rhoE '<[a-z]+-[a-z-]+' resources/assets/components/partials/post   resources/assets/components/partials/TimelineStatus.vue   resources/assets/components/presenter | sort -u

# 2. The provider is registered, and leaflet is still a dependency
grep -n 'GeoServiceProvider' bootstrap/providers.php
grep -n 'leaflet' package.json

# 3. Routes resolve, and nothing upstream added shadows them
php artisan route:list | grep -E 'discover/map|api/geo'

# 4. Tests
./vendor/bin/pest tests/Unit/Geo

# 5. Build the frontend and commit it. Plain `npm install` fails on an
#    upstream peer conflict; the working command, and why, is in
#    DEPLOY_TRUENAS.md, "Building the frontend".
npm install --legacy-peer-deps && npm run production
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
| `window._sharedData.user` is the viewer's `ProfileService` payload | `GeoFeedController::index`, every post component | Comment box, owner checks and admin tools in the pane break. |
| `$store.state` keys the post components read are the four in [The post pane](#the-post-pane) | `spa-bridge.js` | A newly added key reads `undefined`, so it behaves as off. Degrades, does not throw. |
| Mutations the post components commit are `updateRelationship` and `updateCustomEmoji` | `spa-bridge.js` | Vuex logs "unknown mutation type" and carries on. |
| Every `$router.push` in the post components targets another page | `spa-bridge.js` | A push meant to stay in-page becomes a full navigation off the map. |
| `spa.css` declares the post card's custom properties, and `.force-*-mode` after the `prefers-color-scheme` block | `geo/index.blade.php` | Post card loses its colours, or ignores the page's light/dark choice. |
| `TimelineStatus` and `ContextMenu` emit no events beyond the ones `GeoPostPane` binds | `GeoPostPane.vue` | A new emit is a button in the pane that silently does nothing. |
| The only components the pane's tree resolves globally are the five in `post-presenters.js` | `post-presenters.js` | A new global tag renders as nothing — no error, just missing UI. The scan in the rebase procedure finds these. |
| `/api/pixelfed/v1/statuses/{id}` and `/api/v2/statuses/{id}/state` answer a session | `GeoPostPane.vue` | Pane shows "Cannot show this post" for everything. |

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
map ─ GET /api/geo/v1/feed?bbox=&zoom=&from=&to= ──▶ GeoFeedService
  │                                          ├─ zoom ≤ 12: SQL grid clusters
  │                                          └─ zoom > 12: posts + previews
  │
  └─ click a pin ─▶ GeoPostPane ─ GET /api/pixelfed/v1/statuses/:id
                       │          GET /api/pixelfed/v1/accounts/relationships
                       │          GET /api/v2/statuses/:id/state
                       │
                       └─ upstream TimelineStatus.vue + context menu + modals
                            └─ store and $router from geo/spa-bridge.js
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
| `geo.precision.default` | `exact` | `exact` or `city`. See [Exact precision by default](#exact-precision-by-default). |
| `geo.precision.allow_exact` | `true` | Whether authors may opt a post up to exact. |
| `geo.feed.cluster_max_zoom` | `12` | Below this zoom, results are grid clusters. |
| `geo.feed.max_results` | `250` | Pins per viewport. |
| `geo.feed.cache_ttl` | `120` | Seconds to cache a viewport. |
| `geo.feed.max_age_days` | `0` | `0` = no limit. A ceiling on the date filter, and on the left-hand end of its slider. |
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
php artisan config:clear
```

The compiled frontend is part of the source tree and is built into the
image, not on the instance — see DEPLOY_TRUENAS.md, "Building the
frontend". `config:clear` is only needed on installs that don't rebuild
the config cache at startup.

`geo:backfill --places` pins every existing post that already has a
`place_id`, which is what makes the map useful on day one rather than after
people have posted for a month. It writes with the query builder rather than
Eloquent, so historical posts do not get their `updated_at` bumped —
that column drives federation and cache invalidation elsewhere.

```bash
php artisan geo:backfill --places              # historical posts → pins
php artisan geo:backfill --media               # re-read EXIF from stored files
php artisan geo:backfill --repin               # re-derive pins at the current precision
php artisan geo:backfill --places --dry-run    # report only
php artisan geo:backfill --places --limit=1000
```

`--repin` is for after `geo.precision.default` changes. `resolve()` leaves an
existing pin alone unless it is forced, so a post pinned at its city under the
old setting has a more precise position available and no way to reach it.
Only posts whose own photos carry GPS are candidates — one pinned from its
`place_id` has nothing better to offer, and the count at the end says how many
were already as precise as they can be.

Expect it to move only recent posts. Anything uploaded before this feature
existed had its EXIF stripped by the resize pipeline, so it has no photo
coordinates to be precise about.

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
&from=YYYY-MM-DD                    optional, inclusive from 00:00:00
&to=YYYY-MM-DD                      optional, inclusive to 23:59:59
```

`from` and `to` are independent: either, both or neither. A reversed pair is
read as the range it obviously means. Both are applied under
`geo.feed.max_age_days`, which they cannot widen.

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
- A pin holding one post opens it in the pane on click; the map shrinks and
  pans so the pin is still visible, and the pin is ringed.
- A pin holding several opens the popup; a thumbnail opens the pane, and
  prev/next walks the rest of them.
- In the pane: like, share, bookmark, post a comment, reply to one, open the
  likes and shares lists, the context menu, report, and — on your own post —
  edit and delete. A deleted post's pin disappears without a reload.
- `?post=<id>` opens straight into a post; Back closes the pane; Escape closes
  the pane but closes an open modal first.
- Under 768px the map keeps the top of the screen and the expand button in the
  pane hides it; the map is unmoved on the way back.
- Both themes: set and clear the dark-mode cookie and check the post card
  follows the page rather than the OS.
- Date filter: each preset narrows the map and highlights itself; All restores
  it. Drag either handle — the label follows, the highlight clears, and the
  handles push rather than block when they meet. Click the bare track: the
  near handle moves. Tab to a handle and use the arrow keys.
- A range with nothing in it says so, rather than reading as an empty map.
- With the filter at All, the request carries no `from` or `to` at all.

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
- **The pane's own strings follow the classic pages' locales.** `App.boot()` in
  `app.js` builds its i18n from `en`, `pt` and `ja`; the SPA carries twenty.
  A viewer whose locale is neither of the three reads the pane's menus in
  English while the feed shows them translated. Widening it means either
  editing `app.js` — an upstream file — or bundling the locale set a second
  time.
- **Prev/next stops at the pin.** There is no "next post on the map"; the
  gallery is the posts sharing one coordinate and nothing more.
- **Cluster counts ignore the date filter's cache generation.** They are keyed
  by window, so they are correct — but a narrow hand-dragged range is a cache
  entry only that viewer will ever read, for `geo.feed.cache_ttl` seconds.
- **The date filter resets on reload.** See [The date filter](#the-date-filter)
  for why that is a choice rather than an omission.
- **Month arithmetic overflows rather than clamps.** "6 months" back from
  31 August is 3 March, because that is what `Date.setMonth` does. Invisible
  at the scale the slider works at.
- **`mix.extract()` puts Leaflet in `vendor.js`.** The dynamic import in
  `GeoFeed.vue` keeps the module from being *evaluated* on pages with no map,
  but the bytes are in the bundle every page loads. Only the post pane is a
  genuinely separate chunk, because it is app code rather than a package.
- **Author edits to precision after publishing** re-derive the pin, but the
  composer only offers that control before publishing.
