# Immich library

Pick photos straight out of an Immich library in the composer, and have the
people Immich recognised in them offered as tags.

This is a **fork feature**, built the same way as the [geo feed](GEO_FEED.md):
nearly all of it lives in files upstream does not have, and the upstream files
it touches carry a `pf-immich:` marker and are listed exhaustively below.

---

## Contents

- [What it does](#what-it-does)
- [Connecting an account](#connecting-an-account)
- [Design decisions](#design-decisions)
- [Fork patch inventory](#fork-patch-inventory) ← **read this before merging upstream**
- [After an upstream merge](#after-an-upstream-merge)
- [After an Immich upgrade](#after-an-immich-upgrade)
- [Configuration](#configuration)
- [Operating it](#operating-it)
- [API](#api)
- [Testing](#testing)
- [Known limitations](#known-limitations)

---

## What it does

1. **Settings → Immich** connects an account to its own Immich server with an
   API key, checks what the key is allowed to do, and explains how to create
   one with only the permissions it needs.
2. **The composer gets a "From Immich" card** next to *New Post*, and a button
   beside the *+* on the filter page. It opens a picker over the composer:
   newest first, an album filter, and Immich's smart search ("beach at
   sunset"). Chosen photos land in the composer as if they had been picked off
   the disk — filters, crop, alt text and upload are all upstream's.
3. **People are suggested as tags.** On the caption page, a card lists the
   named people Immich recognised in the chosen photos. Each one linked to a
   Pixelfed account has a *Tag* button (and there is *Tag all*); an unlinked
   name has *Link*, which asks for the account and remembers it.
4. **Names are linked to accounts** on the settings page: every named person
   in Immich, with a face thumbnail, filterable, each linkable to an account.
5. **Photos keep their place on the photo map.** Where an Immich photo reaches
   the upload without its GPS — see [below](#coordinates-come-from-immich-when-the-file-lost-them)
   — the server asks Immich where it was taken.

## Connecting an account

What the settings page tells the user, in short:

1. In Immich: profile picture → **Account Settings** → **API Keys** → **New API Key**.
2. Name it (e.g. *Pixelfed*). In the permission list tick only:

   | Permission | Needed for | |
   |---|---|---|
   | `asset.read` | listing, searching, people and GPS on an asset | required |
   | `asset.view` | thumbnails; Immich's JPEG of a HEIC/RAW photo | required |
   | `asset.download` | the original file | required |
   | `person.read` | the people list on the settings page | recommended |
   | `album.read` | the picker's album filter | optional |
   | `user.read` | showing which Immich account is connected | optional |

3. Create, copy (Immich shows it once), paste it in with the server address.

Every permission listed is read-only. Immich versions with no permission
picker issue full-access keys; those work, and the page says they grant more
than needed.

The permission → endpoint mapping was read from Immich's source
(`server/src/controllers/*.controller.ts`, the `@Authenticated({ permission })`
decorators) at v3.3.5. `person.read` is not needed for the suggestions
themselves — an asset's `people` come back with `asset.read` — only for
listing everyone on the settings page.

On saving, the server makes one call per permission (a thumbnail, a one-byte
ranged read of an original, and so on) and records which worked. A key that is
valid but short of permissions is **saved anyway**, with the gaps listed, so
fixing it in Immich needs only *Check again* rather than pasting the key twice.
A key Immich rejects outright is not saved.

---

## Design decisions

### The browser never talks to Immich

Every Immich request — thumbnails included — is made by the Pixelfed server
and relayed. Two reasons, either sufficient:

- **The API key stays on the server.** It is stored with Laravel's
  `encrypted` cast, is `$hidden` on the model, and is never sent back to the
  browser; the settings page shows its last four characters.
- **Immich does not have to be reachable from outside.** The usual setup has
  both on one box or one LAN, with Immich on a private address. The browser —
  on a phone, on mobile data — could not reach it, but Pixelfed can.

The cost is that every thumbnail is a round trip through this server. They are
served with `Cache-Control: private, max-age=86400`, so scrolling back is free.

### Photos enter the composer as files

Upstream's composer does not upload when a file is picked. It keeps `File`
objects in `files`, previews them, lets the WebGL editor filter and crop them,
and uploads everything when *Post* is pressed. So the picker fetches each photo
from `/api/immich/v1/assets/{id}/file` as a blob, wraps it in a `File`, and
calls upstream's own `onInputFile` with it — the same method the file input
calls.

Nothing after that point knows the photo came from Immich: mime and album-limit
checks, filters, crop, alt text, quotas, the upload, `ImageOptimize`, the geo
feature's EXIF read. That is what makes the feature survive upstream changes
to any of them.

The price is that the photo travels Immich → Pixelfed → browser → Pixelfed.
A server-side import would save the middle two legs, but it would have to
bypass the composer's editing, which is client-side, and fake an upload into
upstream's controller. Not worth it at personal-instance scale.

### Which copy is fetched

`App\Immich\Support\FileSource`. The original is preferred: full resolution,
and its EXIF intact, so the geo feature reads its GPS exactly as it would from
a disk. It is passed over only when this instance would refuse it at upload:
a mime type not in `MEDIA_TYPES` (an iPhone HEIC on the default
`image/jpeg,image/png,image/gif`), a RAW file, or anything over
`MAX_PHOTO_SIZE`.

Then Immich's own rendering stands in: `thumbnail?size=fullsize`, which Immich
answers with the original if that is web friendly, its full-size rendering if
it made one, and the 1440px preview otherwise. Servers too old to know
`fullsize` reject it with a 400, and `size=preview` is tried next.

Each candidate is checked **after** download too — content type and size —
because Immich's renderings can be configured as WebP, which an instance may
not accept either. A key without `asset.download` still gets photos, as
previews: a 403 on the original moves on to the next candidate rather than
failing.

Downloads go to a temp file with the size checked from `Content-Length` before
the body is read (`on_headers`) and from the file afterwards, then are served
with `deleteFileAfterSend`.

Videos are offered only when `MEDIA_TYPES` includes `video/mp4`: the original
if it is an MP4 that fits, else Immich's playback transcode.

### Coordinates come from Immich when the file lost them

The geo feature reads GPS from a photo's EXIF at upload. An Immich photo can
reach the upload without it:

- a HEIC original swapped for Immich's JPEG rendering — renderings have no GPS;
- **any** photo after a filter or crop, because the browser re-encodes it
  (this is equally true of photos picked off the disk — upstream behaviour).

Immich read the original's EXIF when it was imported, so it still knows. One
line in the composer's `compose()`, after upload and before publish, calls
`POST /api/immich/v1/compose/attach` with `{media_id, asset_id}` pairs. The
server checks each media row is the caller's own unattached draft, asks Immich
for the asset, and writes `geo_lat`/`geo_lng` — only when the upload had none
of its own and the author has not opted it off the map. The publish that
follows pins the post through the geo feature's normal path.

It never fails a post: the client gives it 8 seconds and ignores errors.

The geo feature's location card reads the same location before upload:
`fetchImmichFile()` puts the asset's `location` on `file.immich`, and the card
(`resources/assets/js/geo/compose.js`) prefers it to reading the file's EXIF.
Its own `applyGeoChoices()`, the line after the attach call, sends that
location again for the upload the pin comes from. Both only fill a gap, so
the second is a no-op; and the attach call skips an upload the author
declined, whichever order they run in.

### Names, not person ids

A mapping is "the name *Mum* is `@mum`", matched on a lower-cased,
whitespace-collapsed form (`App\Immich\Support\PersonName`). Not Immich's person
id, because one person is routinely several face clusters in Immich, each
given the same name; a mapping per name covers all of them, and survives
clusters being merged or re-run.

Unnamed and hidden people are never offered: there is nothing to match, and
hiding is the Immich user saying not to.

Mappings belong to the Pixelfed account, not to the connection.
Disconnecting keeps them; a mapping whose name Immich no longer has is listed
separately on the settings page and still works.

### Suggested, never automatic

Tagging notifies the person tagged. A wrong face match in Immich should cost a
click to ignore, not a notification to a stranger, so nothing is tagged
without one. A suggestion is pushed into the composer's `taggedUsernames` in
exactly the shape upstream's tag search returns (`{id, name, privacy, avatar}`,
built by `ImmichPeople::present()`), so it appears on *Tag people*, can be
removed there, counts against the ten-tag limit, and is published by upstream's
code. The accounts offered get the same exclusions as upstream's tag search:
anyone who has blocked the author, and the author.

### Two search dialects

Immich 3.2 replaced the flat search fields (`type`, `albumIds`, `page`,
`order`, …) with a `filter` tree, `orderBy` and cursor paging, deprecated the
flat ones, and **rejects a body that mixes them**. `App\Immich\Support\SearchRequest`:

- library listing: the new dialect for 3.2 and later, flat for older servers.
  The server version is read from `/api/server/version` when the key is saved
  or checked, and stored on the connection.
- smart search: flat everywhere. In 3.2 it has `filter` but no cursor, and
  `page` cannot be combined with `filter`; the flat form is the only one that
  pages. When Immich gives smart search a cursor, move it over.

The page token handed to the browser is opaque and typed — `c:<cursor>` or
`p:<page>` — so a token from one dialect can never be read as the other.

### An overlay inside the composer, not a `b-modal`

On classic pages the composer is itself inside a Bootstrap 4 modal, whose
focus trap pulls focus back from anything outside it — a `b-modal` is mounted
on `body`, so its search box would be untypeable. The picker is a
`position: fixed` overlay rendered *inside* the composer component, so the
trap counts it as its own. Escape is stopped at the overlay so it does not
close the composer underneath.

### Its own settings page, not an upstream one

`/settings/immich` is a fork route and a fork view that extends upstream's
`settings.template`, so it looks like every other settings page. The only
upstream edit is the sidebar link.

---

## Fork patch inventory

### Files upstream does not have

```
app/Immich/**                                     the whole backend
config/immich.php                                 configuration
routes/immich.php                                 routes
database/migrations/2026_10_26_1000*.php          immich_connections
database/migrations/2026_10_26_1001*.php          immich_person_mappings
resources/assets/components/immich/ImmichPicker.vue         the picker
resources/assets/components/immich/ImmichPeopleSuggest.vue  the composer's people card
resources/assets/components/immich/ImmichProfilePicker.vue  account search, both pages
resources/assets/components/immich/ImmichSettings.vue       Settings → Immich
resources/assets/js/immich.js                     settings page bundle entry
resources/assets/js/immich/compose.js             fetch-as-File, attach
resources/views/immich/settings.blade.php         the settings page
tests/Unit/Immich/**                              URL, file source, search bodies, names
docs/fork/IMMICH.md                               this file
```

### Upstream files touched

`git grep -n 'pf-immich:' -- ':!docs'` lists them: 11 hits across 6 files.

| File | Change | If a merge eats it |
|---|---|---|
| `bootstrap/providers.php` | Registers `App\Immich\ImmichServiceProvider`. | **Everything stops.** Re-add it. |
| `app/Util/Site/Config.php` | `features.immich` in the frontend config. | The composer never offers Immich. Settings still works. |
| `resources/views/settings/partial/sidebar.blade.php` | The *Immich* link. | Link gone; `/settings/immich` still works. |
| `resources/assets/js/components/ComposeModal.vue` | Six markers: the imports, the `components` entries, the page 1 `<immich-picker>` card, the page 2 compact `<immich-picker>` beside *+*, `<immich-people-suggest>` under *Tag people*, and `attachImmichUploads` (plus `isPosting = true`, so *Post* stays a spinner while it runs) before publish. | Each degrades alone: no card, no button, no suggestions, or Immich imports that lost their EXIF stay off the map. |
| `webpack.mix.js` | Adds `immich.js` to the build. | Settings page 500s on `mix('js/immich.js')`. |
| `.env.example` | Commented documentation block. | Cosmetic. |

No new npm packages: `@trevoreyre/autocomplete-vue` (account search) and
`bootstrap-vue` (spinners) are upstream's.

## After an upstream merge

The geo feed's [rebase procedure](GEO_FEED.md#rebase-procedure) covers the
mechanics, and the frontend has to be rebuilt either way. What this feature
additionally depends on:

| Assumption | Where it matters | Symptom if broken |
|---|---|---|
| The composer keeps picked files in `files: [{file}]`, parallel to `media`, and uploads at publish | `ImmichPeopleSuggest`, `attachImmichUploads` | No suggestions; or coordinates attached to the wrong media (the attach call checks ownership, so never someone else's). |
| `onInputFile(event)` reads only `event.target.files` and writes `event.target.value` | the two `@add` handlers | Picked photos never appear. |
| `compose()` uploads (`mediaUpload`) before it posts to `/api/compose/v0/publish`, and leaves `media[i].id` set | the attach call | Coordinates not attached; posts still publish. |
| `taggedUsernames` entries are `{id, name, privacy, avatar}` and the publish reads `id`/`name` | `ImmichPeople::present()` | Suggested tags silently dropped at publish. |
| `/api/compose/v0/search/tag` returns that same shape | `ImmichProfilePicker` | Account search on both pages breaks. |
| `UserFilterService::searchExcludedProfileIds()` returns blockers + self | `ImmichPeople::profiles()` | Someone who blocked the author could be suggested. |
| `config_cache('pixelfed.media_types')` and `max_photo_size` (KB) are what the upload enforces | `FileSource` | Files fetched that the upload then refuses — a clear error, not a silent one. |
| `settings.template` pushes `settings.js` (the Vue root) after the page's own `@push('scripts')` | `immich/settings.blade.php` | Settings page renders an empty `<immich-settings>` tag. |
| The geo feature's `media.geo_lat/geo_lng/geo_precision` columns and `StatusGeoService::PRECISION_NONE` | `ImmichLibraryController::attach` | Attach throws; the client swallows it. |

```bash
# Insertion points still present (expect 11 hits across 6 files)
git grep -n 'pf-immich:' -- ':!docs'
grep -n 'ImmichServiceProvider' bootstrap/providers.php
php artisan route:list --path=immich        # 16 routes
./vendor/bin/pest tests/Unit/Immich
```

## After an Immich upgrade

Immich's API moves faster than Pixelfed's. Things to re-check, in order of
likelihood:

- **The flat search fields are removed.** Deprecated in 3.2. Library listing
  on 3.2+ already avoids them; smart search does not (no alternative yet).
  Symptom: the picker's search returns an error, browsing still works.
  Fix in `SearchRequest::smart()`.
- **Permissions renamed or split.** Symptom: *Check again* reports one missing
  that the key has. Check `@Authenticated` on the endpoints in `ImmichClient`
  and update `PERMISSIONS` in `ImmichSettings.vue` and the constants in
  `ImmichSettingsController`.
- **`duration` format.** Milliseconds since 3.0, `"0:00:12.345"` before;
  `ImmichLibraryController::seconds()` reads both.
- **`people` on an asset** gaining paging or moving behind its own permission:
  suggestions stop appearing.

---

## Configuration

`config/immich.php`, env vars in `.env.example`.

| Key | Default | Notes |
|---|---|---|
| `immich.enabled` | `true` | Master switch: no routes, no composer card, no settings link. |
| `immich.default_url` | empty | Pre-fills the address on the settings page. |
| `immich.allowed_hosts` | empty = any | Comma separated `host`, `host:port` or `*.domain`. See below. |
| `immich.timeout` | `10` | Seconds, for API calls. |
| `immich.download_timeout` | `120` | Seconds, for fetching a photo. |
| `immich.page_size` | `60` | Assets per picker page (max 200). |
| `immich.thumbnail_cache_seconds` | `86400` | Browser cache on proxied thumbnails. |

### `IMMICH_ALLOWED_HOSTS` and who can use it

The server makes requests to whatever URL an account saves. On a one-person
instance that is the point. On an instance with accounts you do not trust,
leave it open and anyone can have Pixelfed make requests to hosts on its own
network (an SSRF). What they can get back is narrow — every path is fixed,
every id is checked to be a UUID, responses are reduced to known fields or
must be images — but set `IMMICH_ALLOWED_HOSTS` anyway.

Redirects are followed only within the same scheme, host and port: Guzzle
strips `Authorization` on a cross-host redirect but not a custom header, and
the key travels in `x-api-key`.

### The API key and `APP_KEY`

Keys are encrypted with `APP_KEY`. Rotating it makes every stored key
unreadable; each account then has to reconnect (the page will show an error
until they do).

## Operating it

```bash
php artisan migrate        # two new tables
php artisan cache:clear    # or wait 15 minutes: features.immich is in the cached site config
```

The image is built from the committed frontend, so a release needs the
rebuilt `public/` committed like any other frontend change
(DEPLOY_TRUENAS.md, "Building the frontend").

Disabling: `IMMICH_ENABLED=false`. The tables stay; drop them with the
migrations if wanted.

On the TrueNAS instance Immich runs as the `immich` app on the same host, at
host port 30041, so the address to use from Pixelfed is
`http://192.168.1.82:30041`.

---

## API

All under `/api/immich/v1`, session-authenticated. Immich failures come back
as JSON `{error, message, permission}` with 502 (or 404/413/422) — never
401/403, which would read as Pixelfed refusing the viewer. Not connected is
`409 {error: "not_connected"}`.

| Method | Path | |
|---|---|---|
| `GET` | `connection` | `{connected, url, key_hint, server_version, user, permissions, missing_required, verified_at}` |
| `PUT` | `connection` | `{url, api_key?}` — check and save. Blank key keeps the saved one. 422 with `errors.url`/`errors.api_key`. |
| `POST` | `connection/check` | Re-run the permission check. |
| `DELETE` | `connection` | Forget the connection (mappings kept). |
| `GET` | `people` | `{connected, error, people: [{name, key, thumbnail, mapping}], unmatched: [mapping]}` |
| `GET` | `people/{id}/thumbnail` | Face image. |
| `PUT` | `mappings` | `{name, profile_id}` → mapping. Replaces any mapping for that name. |
| `DELETE` | `mappings/{id}` | |
| `GET` | `status` | `{enabled, connected, settings_url, videos}` — no Immich call. |
| `GET` | `albums` | `{albums: [{id, name, count}], error}` |
| `GET` | `assets?q=&album=&next=` | `{items: [{id, type, filename, taken_at, duration, thumbnail}], next}` |
| `GET` | `assets/{id}` | `{id, type, filename, mime, taken_at, location, importable, people: [{name, key, person_id, profile}], file_url}` |
| `GET` | `assets/{id}/thumbnail` | |
| `GET` | `assets/{id}/file` | The file, `X-Immich-Variant: original\|fullsize\|preview\|playback`. |
| `POST` | `compose/attach` | `{items: [{media_id, asset_id}]}` → `{located}` |

---

## Testing

```bash
./vendor/bin/pest tests/Unit/Immich
```

Pure unit tests, no app or database: URL normalisation and the allow-list,
same-origin redirects, UUID checks, which copy is fetched for every
type/size/`MEDIA_TYPES` combination, file naming, both search dialects and
that they never mix, page tokens, version comparison, name matching.

Not automated — check by hand after a merge or an Immich upgrade:

- Settings → Immich: connect with a limited key; the table shows each
  permission; remove `asset.download` in Immich, *Check again*, see it missing;
  a wrong key is refused and not saved; a wrong address says so.
- The people list shows faces; filter, *Only unlinked*, link, change, remove;
  *Add a name by hand*.
- Composer page 1: *From Immich* opens the picker; scrolling loads more; the
  album filter and smart search narrow it; selection order numbers; the limit
  stops at the album limit.
- Pick a JPEG with GPS → it posts and lands on the map.
- Pick an iPhone HEIC (with `MEDIA_TYPES` lacking `image/heic`) → arrives as
  JPEG (`X-Immich-Variant: fullsize` or `preview`) and still lands on the map.
- Pick a photo, apply a filter, post → still on the map.
- Photo with a linked person: the card offers *Tag*; *Tag people* shows them;
  the post notifies them. An unlinked person: *Link* → account → *Tag*
  appears, and Settings shows the new link.
- Page 2's Immich button adds to an existing selection.
- On a classic page (`/` with the compose modal): the picker's search box can
  be typed into, and Escape closes the picker without closing the composer.
- Not connected: the card says so and leads to Settings.

## Known limitations

- **Suggestions only for photos picked from Immich.** A photo uploaded from
  disk that is also in Immich gets none. Matching by checksum
  (`assets.checksum` is SHA-1 of the original) is possible but not done.
- **Smart search ignores the album filter**, and needs Immich's machine
  learning enabled.
- **Photos travel through the browser** (see above): a large original on a
  slow mobile connection takes as long as uploading it would.
- **Live photos come in as stills.** The motion part is a separate Immich
  asset and is not offered.
- **One Immich server per account.**
- **No admin UI.** Configuration is env vars only.
