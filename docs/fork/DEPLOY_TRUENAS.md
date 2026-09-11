# Deploying the fork on TrueNAS SCALE

How this fork is built and run on TrueNAS SCALE 25.10, and how a new upstream
release gets from `pixelfed/pixelfed` to the running instance. Companion to
[GEO_FEED.md](GEO_FEED.md), which owns the geo feature itself.

---

## Contents

- [Why this shape](#why-this-shape)
- [The release loop](#the-release-loop) ← the ongoing workflow
- [Building the frontend](#building-the-frontend)
- [Datasets](#datasets)
- [Building the image](#building-the-image)
- [`.env`](#env)
- [App YAML](#app-yaml)
- [First install](#first-install)
- [Upgrading a running instance](#upgrading-a-running-instance)
- [Rollback](#rollback)
- [Reverse proxy](#reverse-proxy)
- [Lessons](#lessons)

---

## Why this shape

There is no Pixelfed app in the TrueNAS catalog, and Pixelfed publishes no
official Docker images. Third-party prebuilt images lag upstream badly — the
best-maintained one (`ghcr.io/jippi/docker-pixelfed`) had tagged releases stuck
at 0.12.4 while upstream was at 0.12.9, and its `nightly-*` tags build from
unreleased branches. None of them would carry this fork's features anyway.

TrueNAS custom apps cannot `build:`. So the working path is: build the image on
the TrueNAS host with the Docker CLI from a **fork tag**, then reference it as a
local image from the app YAML.

## The release loop

The fork's `dev` is always *latest upstream release tag + fork commits*. Fork
features are developed on `dev` between upstream releases. When upstream tags a
release, it is merged in and a new fork tag is cut. The deployed instance only
ever runs a fork tag.

```
upstream tags vX.Y.Z
  │
  ├─ git fetch upstream --tags
  ├─ git merge vX.Y.Z                       # into dev. Merge — see below.
  ├─ resolve conflicts
  │    ├─ pf-geo insertion points ........... GEO_FEED.md, "Fork patch inventory"
  │    └─ compiled assets .................. take upstream's, they get rebuilt:
  │         git checkout vX.Y.Z -- public/js public/css public/mix-manifest.json package-lock.json
  ├─ post-merge dependency checklist ....... GEO_FEED.md, "Rebase procedure"
  ├─ rebuild the frontend .................. below; commit as "Update compiled assets"
  ├─ php vendor/bin/pest tests/Unit/Geo  +  php vendor/bin/pint --test app/Geo
  ├─ git tag -a vX.Y.Z-fork.1 -m "..."  &&  git push origin dev vX.Y.Z-fork.1
  │
  └─ TrueNAS: build image → snapshot → deploy → migrate      ("Upgrading", below)

fork work with no upstream release in between → vX.Y.Z-fork.2, -fork.3, …
```

**Tag scheme.** `vX.Y.Z-fork.N`: the upstream version this fork tag is built on,
plus a counter. It says at a glance what upstream code is running. One
consequence: git's version sort puts `v0.12.10-fork.1` *above* `v0.12.10`
(it does not know semver pre-release rules), so anything that looks for the
newest upstream tag has to filter fork tags out:

```bash
git tag --sort=-v:refname | grep -v -- -fork | head -1     # newest upstream release
git tag --sort=-v:refname | grep -- -fork | head -1        # newest fork release
```

**Merge, don't rebase.** The fork "rebases onto stable releases" in the loose
sense — it moves to the new base — but mechanically it merges. A real `git
rebase` rewrites the fork's commits, which detaches every fork tag from `dev`
(the tagged commit is no longer on the branch) and means the commit the instance
is running no longer exists in its own branch's history. Merging keeps history
append-only: every fork tag stays an ancestor of `dev`, and nothing is ever
force-pushed.

**A clean merge is not a working merge.** The fork's own files never conflict,
because upstream doesn't have them — so git can't warn when upstream changes
something they depend on. Both merges so far were textually clean and broken.
Run the dependency checklist in GEO_FEED.md every time.

## Building the frontend

Pixelfed **commits its compiled assets** (`public/js`, `public/css`,
`public/mix-manifest.json`) and the Dockerfile has no `npm` step — it just
copies the tree. So the fork must commit compiled assets too, or its frontend
changes never reach the image. That means rebuilding after:

- any change under `resources/assets`
- any upstream merge (upstream's bundles come in without the fork's changes)

No local Node is needed; build in a throwaway container. From the repo root:

```bash
docker run --rm \
  -v "$PWD:/app" \
  -v pixelfed_node_modules:/app/node_modules \
  -w /app \
  -e NODE_OPTIONS=--max-old-space-size=6144 \
  node:20-bookworm \
  sh -c "npm install --legacy-peer-deps --no-audit --no-fund && npm run production"
```

On Windows Git Bash, prefix the command with `MSYS_NO_PATHCONV=1` and give the
host path in `C:/...` form, or `/app` gets rewritten into a Windows path.

- `--legacy-peer-deps` is required, not optional. Upstream's `package.json`
  pins `blurhash@^2` while `vue-blurhash@0.1.4` wants `blurhash@^1`; upstream's
  own lockfile was generated ignoring that peer, and a plain `npm install`
  refuses. This is not documented upstream — it's inferred from the lockfile.
- `npm install`, not `npm ci`: the lockfile must be allowed to pick up
  fork-added packages (`leaflet`).
- The named volume keeps `node_modules` on the Linux side of Docker Desktop.
  Installing tens of thousands of files across a Windows bind mount is very
  slow; this makes the whole build a couple of minutes.
- Upstream doesn't pin a Node version. 20 LTS works.

Then commit `public/js`, `public/css`, `public/mix-manifest.json` and
`package-lock.json` as **"Update compiled assets"** — upstream's own commit
message for the same thing.

## Datasets

One on SSD for database, Redis and the `.env`; one on HDD for media. Create the
subdirectories explicitly — the image has no scaffolding step, and Laravel will
not create them:

```bash
mkdir -p <SSD>/PixelFed/{db,redis}
mkdir -p <HDD>/PixelFed/storage/{app/public,framework/{cache,sessions,testing,views},logs}
chown -R 33:33 <HDD>/PixelFed/storage
chown -R 999:999 <SSD>/PixelFed/{db,redis}
```

33 is `www-data`; 999 is `mysql`/`redis`.

There is no longer a `cache/` directory: `bootstrap/cache` is not bind-mounted
any more (see [App YAML](#app-yaml)). If one exists from an earlier deploy it is
simply unused.

## Building the image

On the TrueNAS host, from the fork tag — never from `dev`, never from upstream:

```bash
git clone --branch v0.12.10-fork.1 --depth 1 https://github.com/gwystylain/pixelfed.git src
cd src && docker build -t local/pixelfed:0.12.10-fork.1 .
```

No `chown` on the clone first: the Dockerfile does `COPY --chown=www-data`
and a `chown -R` of its own, so host ownership never reaches the image.

Image tag = fork tag without the `v`. Do not `docker rmi` the previous image
until the new one has been running for a while; it is the rollback.

## `.env`

A real `.env` file on the SSD dataset, owned `33:33`, mode `644`, bind-mounted
into both PHP containers. (Upstream's compose uses `env_file:` and lets Laravel
read the environment instead; either works. The mount is what this deploy uses.)

- `APP_KEY` must be exactly 32 base64-encoded bytes.
- DB passwords in hex, to avoid quoting problems. `MARIADB_PASSWORD` in the
  YAML and `DB_PASSWORD` in `.env` must be identical.
- `APP_DOMAIN` is baked into federated actor URIs and cannot be changed after
  federating.
- 0.12.10 renamed `CACHE_DRIVER` to `CACHE_STORE`. The old name still works as
  a fallback; rename at leisure.
- `IMAGE_DRIVER` is new in 0.12.10 and defaults to `gd`, which the image has.
  Leave it.
- Geo (fork): everything defaults to on. The one to set deliberately is the
  precision — `GEO_PRECISION_DEFAULT=city` snaps pins to city centre unless an
  author opts a single post up to exact. The full block is in `.env.example`
  under `pf-geo`.

## App YAML

Apps → Discover → ⋮ → Install via YAML. Five services. What's specific to this
image and this host:

- `image: local/pixelfed:<tag>` plus `pull_policy: never` on every PHP service
- every path is `/var/www/html/...`, not `/var/www/...` — this image serves
  from `html/`. See [Lessons](#lessons).
- three bind mounts on each PHP service: `storage`, `.env`, and
  `public/storage` → `storage/app/public`. **Not** `bootstrap/cache`.
- `"8095:8080"` — FrankenPHP listens on 8080
- the worker runs `php artisan horizon` directly; there is no `gosu` in this
  image
- no top-level `name:` key — TrueNAS supplies the project name

```yaml
services:
  db:
    image: mariadb:11.4
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: pixelfed
      MARIADB_USER: pixelfed
      MARIADB_PASSWORD: <hex>            # == DB_PASSWORD in .env
      MARIADB_ROOT_PASSWORD: <hex>
    volumes:
      - <SSD>/PixelFed/db:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 3
      start_period: 30s

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: redis-server --appendonly yes
    volumes:
      - <SSD>/PixelFed/redis:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 3

  app:
    image: local/pixelfed:0.12.10-fork.1
    pull_policy: never
    restart: unless-stopped
    ports:
      - "8095:8080"
    environment:
      SSL_MODE: "off"                    # TLS terminates at the proxy
      PHP_POST_MAX_SIZE: "500M"
      PHP_UPLOAD_MAX_FILE_SIZE: "500M"
      PHP_OPCACHE_ENABLE: "1"
      # serversideup/php startup automation. Rebuilds the caches into the
      # container layer on every start, which is why bootstrap/cache is not
      # mounted. Migration is off so upgrades are run by hand and watched;
      # flip it (and ISOLATION) to "true" for hands-off upgrades.
      AUTORUN_ENABLED: "true"
      AUTORUN_LARAVEL_MIGRATION: "false"
      AUTORUN_LARAVEL_MIGRATION_ISOLATION: "true"
      AUTORUN_LARAVEL_STORAGE_LINK: "true"
      AUTORUN_LARAVEL_EVENT_CACHE: "true"
      AUTORUN_LARAVEL_ROUTE_CACHE: "true"
      AUTORUN_LARAVEL_VIEW_CACHE: "true"
      AUTORUN_LARAVEL_CONFIG_CACHE: "true"
    volumes:
      - <HDD>/PixelFed/storage:/var/www/html/storage
      - <HDD>/PixelFed/storage/app/public:/var/www/html/public/storage
      - <SSD>/PixelFed/.env:/var/www/html/.env
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy

  worker:
    image: local/pixelfed:0.12.10-fork.1
    pull_policy: never
    restart: unless-stopped
    command: ["php", "artisan", "horizon"]
    environment:
      PHP_POST_MAX_SIZE: "500M"
      PHP_UPLOAD_MAX_FILE_SIZE: "500M"
      PHP_OPCACHE_ENABLE: "1"
      AUTORUN_ENABLED: "false"           # the app container owns the caches
      AUTORUN_LARAVEL_MIGRATION: "false"
      AUTORUN_LARAVEL_MIGRATION_ISOLATION: "false"
      AUTORUN_LARAVEL_STORAGE_LINK: "true"
      AUTORUN_LARAVEL_EVENT_CACHE: "false"
      AUTORUN_LARAVEL_ROUTE_CACHE: "false"
      AUTORUN_LARAVEL_VIEW_CACHE: "false"
      AUTORUN_LARAVEL_CONFIG_CACHE: "true"
    volumes:
      - <HDD>/PixelFed/storage:/var/www/html/storage
      - <HDD>/PixelFed/storage/app/public:/var/www/html/public/storage
      - <SSD>/PixelFed/.env:/var/www/html/.env
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:                         # the inherited one curls the web port
      test: ["CMD", "php", "artisan", "horizon:status"]
      interval: 10s
      timeout: 5s
      retries: 3

  scheduler:
    image: local/pixelfed:0.12.10-fork.1
    pull_policy: never
    restart: unless-stopped
    command: ["php", "artisan", "schedule:work"]
    stop_signal: SIGTERM
    environment:
      PHP_OPCACHE_ENABLE: "1"
      AUTORUN_ENABLED: "false"
      AUTORUN_LARAVEL_MIGRATION: "false"
      AUTORUN_LARAVEL_MIGRATION_ISOLATION: "false"
      AUTORUN_LARAVEL_STORAGE_LINK: "true"
      AUTORUN_LARAVEL_EVENT_CACHE: "false"
      AUTORUN_LARAVEL_ROUTE_CACHE: "false"
      AUTORUN_LARAVEL_VIEW_CACHE: "false"
      AUTORUN_LARAVEL_CONFIG_CACHE: "true"
    volumes:
      - <HDD>/PixelFed/storage:/var/www/html/storage
      - <HDD>/PixelFed/storage/app/public:/var/www/html/public/storage
      - <SSD>/PixelFed/.env:/var/www/html/.env
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "healthcheck-schedule"]
      start_period: 10s
```

The `scheduler` service is what runs Pixelfed's scheduled maintenance —
`media:gc`, `story:gc`, `horizon:snapshot`, `storage:maintenance`, the
notification and stat updaters (`bootstrap/scheduledtasks.php`). A deploy
without it accumulates garbage silently. It was missing from this deploy
until the move to 0.12.10.

`AUTORUN_LARAVEL_STORAGE_LINK` is a no-op against the `public/storage` bind
mount (`storage:link` sees the path exists and skips), so the mount stays: the
symlink lives in the image layer and disappears on every redeploy, and the
mount is what makes media survive that.

## First install

Fresh instance only. The image has no first-run automation for these; run them
once in the app container after it starts:

```bash
php artisan key:generate
php artisan migrate --force
php artisan instance:actor
php artisan passport:keys
php artisan import:cities
php artisan user:create
chmod 600 storage/oauth-private.key      # Passport rejects a group-readable key
```

`config:cache` and `storage:link` are no longer part of this list — the
startup automation does both on every boot.

## Upgrading a running instance

This is the TrueNAS half of the release loop. It was first done for
`v0.12.9` (upstream) → `v0.12.10-fork.1`.

1. **Build the new image** on the host from the new fork tag, as above. Keep
   the old one.

2. **Snapshot both datasets together.** Migrations are not reversible, and the
   database, `APP_KEY` and the OAuth keys must stay mutually consistent.
   Rollback means restoring the snapshot, not just swapping the image tag.

3. **Edit the YAML**: change the image tag on `app`, `worker` and `scheduler`.
   If the upgrade also changes how the image is run (as 0.12.10 did — no
   `bootstrap/cache` mount, `AUTORUN_*` env), diff the YAML against the
   reference above. Upstream's `docker-compose.yml` is the source of truth for
   what the image expects.

4. **Deploy.** All three PHP containers come up on the new image; the app's
   entrypoint rebuilds the caches.

5. **Migrate**, watching the output:

   ```bash
   docker exec -it <app> php artisan migrate --force
   ```

   A migration that only dispatches a job (0.12.10's
   `backfill_user_storage_used` does) returns instantly and needs the worker
   to finish the work.

6. **Verify from the host**, not the container: `ls` on the datasets is the
   only check that proves a file survives redeploy. Then:

   ```bash
   docker exec -it <app> php artisan route:list --path=api/geo
   ```

   Four routes, plus `discover/map`. Log in, open the map, post a photo with
   GPS.

7. **Geo, after the first geo-enabled deploy only:**

   ```bash
   docker exec -it <app> php artisan geo:backfill --places --dry-run
   docker exec -it <app> php artisan geo:backfill --places
   ```

   `--places` pins every historical post that already has a location, at city
   precision — fast and safe, and it's what populates the map on day one.
   `--media` re-reads EXIF from stored uploads, but existing files have already
   been stripped by the resize pipeline, so the hit rate is low. New uploads
   are captured automatically.

### What changed at 0.12.10 specifically

Recorded because the next upgrade may look similar.

- **`bootstrap/cache` must not be bind-mounted.** 0.12.10 replaced Laravel's
  application skeleton; four service providers that 0.12.9's cached manifests
  reference (`RouteServiceProvider`, `EventServiceProvider`,
  `AuthServiceProvider`, `BroadcastServiceProvider`) no longer exist. Upstream
  removed the mount from their compose three days after that change
  (`f5d166a93`). A stale cache mounted over the new image fails at boot.
- **The image does have entrypoint automation.** It's `serversideup/php`'s,
  off by default, enabled with `AUTORUN_ENABLED`. Upstream's compose turns it
  on; it's what makes the cache mount unnecessary.
- **Auth middleware moved.** `validemail` and `twofactor` are no longer
  middleware aliases; both checks happen in the login flow. Existing sessions
  are unaffected. (This is the one that would have broken the geo routes —
  GEO_FEED.md.)
- Nine migrations: five upstream, three geo, one Sanctum table.

### Doing it from the shell instead of the UI

Everything in the upgrade except pasting YAML has a `midclt` equivalent
(the TrueNAS CLI, one of the three supported ways to change config). This is
what the 0.12.10-fork.1 upgrade actually used. The custom app's stored
compose lives at
`/mnt/.ix-apps/app_configs/pixelfed/versions/<ver>/user_config.yaml`, which
is the cleanest thing to edit programmatically — it holds the DB passwords,
so edit it in place on the host rather than copying it anywhere.

```bash
midclt call -j app.stop pixelfed
zfs snapshot HDDs/Applications/PixelFed@pre-<tag>
zfs snapshot SSD/ApplicationsDataset/PixelFed@pre-<tag>
midclt call -j app.update pixelfed '{"custom_compose_config_string": "<yaml>"}'
midclt call -j app.start pixelfed
docker exec ix-pixelfed-app-1 php artisan migrate --force
```

Two things that cost time the first time:

- The wait-for-job flag is `-j`. `-job` parses as `-j` plus garbage and the
  command silently does nothing; check `app.query` state after every call
  rather than trusting the exit status.
- `app.update` on a stopped app leaves it stopped. `app.start` is a separate
  step. (Saving in the UI starts it; the API doesn't.)
- Build the JSON payload with Python and call `midclt` via `subprocess` —
  the YAML has quotes and colons that shell quoting mangles.

Snapshot **after** the stop. A snapshot of a running MariaDB is
crash-consistent, which InnoDB recovers from, but there is no reason to
rely on that when the app is about to be stopped anyway.

## Rollback

1. Stop the app.
2. Restore both dataset snapshots.
3. Set the image tag back on all three PHP services.
4. Start.

Image alone is not enough once migrations have run: the old code will be
looking at a schema it doesn't know.

## Reverse proxy

Nginx Proxy Manager: websockets on, `client_max_body_size 500M`,
`X-Forwarded-Proto` passed through. TLS via DNS-01. The `500M` matches
`PHP_POST_MAX_SIZE` / `PHP_UPLOAD_MAX_FILE_SIZE` in the YAML; raise both
together.

## Lessons

**The single biggest one.** Nearly every failure on the first deploy traced to
one cause: the image serves from `/var/www/html` while the config was written
for `/var/www`. That one wrong prefix produced four unrelated-looking symptoms —
a missing `.env` (Redis fell back to `127.0.0.1`), an unusable cache path (every
artisan command failed at boot), OAuth keys written to the ephemeral container
layer (500s on all API calls, blank timeline), and non-rendering media. When
several things break at once in a container, suspect a mount path before
suspecting four bugs.

**On the container**

- Never run `cache:clear` on Pixelfed; restart the container instead.
  Clearing it broke Passport.
- `public/storage` is a symlink inside the image layer and disappears on
  every redeploy. The bind mount of its target is what fixes that.
- Verify durable state from the host, not inside the container.
- Snapshot both datasets together before any image change.

**On the instance**

- Signup review depends on working email at two points and is unusable
  without SMTP.
- `APP_DOMAIN` cannot be changed after federating.

**On the fork**

- Compiled assets are part of the source. A frontend change that isn't
  rebuilt and committed does not exist as far as the image is concerned.
- `npm install` needs `--legacy-peer-deps`. Always has; upstream just never
  wrote it down.
- Upstream's `docker-compose.yml` is the reference for how the image expects
  to be run. When an upgrade misbehaves, diff it against the previous tag
  before touching anything else.
