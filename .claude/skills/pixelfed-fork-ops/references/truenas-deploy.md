# Deploying a fork tag to the TrueNAS instance

The sequence below is what deployed `v0.12.10-fork.1`, with the checks that
caught the two things that went wrong. Every host command runs over
`ssh -o BatchMode=yes truenas '...'`; see `environment.md` for names and the
`sudo` situation. `docs/fork/DEPLOY_TRUENAS.md` is the full runbook and the
reference YAML - read its "Upgrading a running instance" and "What changed
at 0.12.10" sections before a deploy that follows an upstream merge.

Phases A-B are safe to run without asking. Stop at the marked point.

## A. Build the image (instance keeps serving)

```bash
ssh -o BatchMode=yes truenas 'git clone --branch vX.Y.Z-fork.N --depth 1 https://github.com/gwystylain/pixelfed.git ~/src-X.Y.Z-fork.N'
ssh -o BatchMode=yes truenas 'cd ~/src-X.Y.Z-fork.N && sudo docker build -t local/pixelfed:X.Y.Z-fork.N . 2>&1 | tail -5'
ssh -o BatchMode=yes truenas 'sudo docker images local/pixelfed'
```

Several minutes (`composer install` runs inside). Both the new and the
previous image should be listed afterwards; the previous one is the rollback.
No `chown` on the clone - the Dockerfile chowns on `COPY`.

Verify the clone is the tag you think: `git -C ~/src-... describe --tags`,
and that `public/js/geo.js` exists in it (if it doesn't, the assets were
never committed and the map will 500 - fix on the PC, retag).

## B. Prepare the config

Routine upgrade (only the image tag changes):

```bash
ssh -o BatchMode=yes truenas 'sudo python3 - X.Y.Z-fork.N' < .claude/skills/pixelfed-fork-ops/scripts/truenas-bump-image.py
```

Dry run: writes `/tmp/pixelfed-compose-X.Y.Z-fork.N.yaml`, validates it with
`docker compose config`, prints a redacted diff. It refuses if the image
isn't built. Read the diff: exactly three `image:` lines (app, worker,
scheduler) should change.

If `preflight.sh` said the Dockerfile or upstream's compose changed, the
run contract may have changed too (0.12.10 dropped a bind mount and added
`AUTORUN_*`). Then the change is more than a retag: diff upstream's
`docker-compose.yml` between the two tags, update the reference YAML in
`DEPLOY_TRUENAS.md`, and generate the new config with a one-off script the
way the first deploy did (read the runbook's "Doing it from the shell").

## >>> Confirm with the user here <<<

Say what's staged (image built, config validated, which snapshots will be
taken) and that downtime starts at the stop. Wait for a clear go.

## C. Stop, snapshot, apply, start

```bash
ssh -o BatchMode=yes truenas 'sudo midclt call -j app.stop pixelfed 2>&1 | tail -1; sleep 3; sudo midclt call app.query "[[\"name\",\"=\",\"pixelfed\"]]" | python3 -c "import json,sys; print(\"state:\", json.load(sys.stdin)[0][\"state\"])"; sudo docker ps --format "{{.Names}}" | grep -c ix-pixelfed || true'
```

**Check the printed state is `STOPPED` and the count is `0` before going
on.** The first deploy's stop silently did nothing because of a flag typo,
and the snapshots that followed were taken under a live database.

```bash
ssh -o BatchMode=yes truenas 'sudo zfs snapshot HDDs/Applications/PixelFed@pre-X.Y.Z-fork.N && sudo zfs snapshot SSD/ApplicationsDataset/PixelFed@pre-X.Y.Z-fork.N && sudo zfs list -t snapshot -o name,creation | grep pre-X.Y.Z-fork.N'
ssh -o BatchMode=yes truenas 'sudo python3 - X.Y.Z-fork.N --apply' < .claude/skills/pixelfed-fork-ops/scripts/truenas-bump-image.py
ssh -o BatchMode=yes truenas 'sudo midclt call -j app.start pixelfed 2>&1 | tail -1; for i in 1 2 3 4 5 6; do sleep 5; sudo docker ps --format "  {{.Names}}\t{{.Status}}" | grep ix-pixelfed; echo; done | tail -7'
```

`app.update` leaves a stopped app stopped; `app.start` is deliberate and
separate. Five containers should be up within ~10 s; the app's entrypoint
rebuilds `bootstrap/cache` (config, routes, views, events) before serving.

## D. Migrate - immediately

The app is now serving new code on the old schema; keep this window short.

```bash
ssh -o BatchMode=yes truenas 'sudo docker exec ix-pixelfed-app-1 php artisan migrate --force 2>&1 | sed "s/\x1b\[[0-9;]*m//g"'
```

Count the `DONE` lines against `preflight.sh`'s incoming-migrations list.
A migration that only dispatches a queued job returns in milliseconds and
needs the worker to finish it. Never `cache:clear`.

## E. Verify

```bash
bash .claude/skills/pixelfed-fork-ops/scripts/truenas-verify.sh X.Y.Z-fork.N
```

`ALL CHECKS PASSED` covers: app state, five containers healthy on the right
image, no pending migrations, 4 + 1 geo routes, `geo.enabled`, Horizon
running, the scheduler having run, HTTP 302/200/200/401 on the map page, both
assets and the API, OAuth keys and `.env` still on the datasets, no errors in
the app log. Then hand over: the user opens the map and posts a photo with
GPS - that's the one thing not checkable from here.

**Geo, after the first geo-enabled deploy or a fresh `import:cities`:**

```bash
ssh -o BatchMode=yes truenas 'sudo docker exec ix-pixelfed-app-1 php artisan geo:backfill --places --dry-run'
ssh -o BatchMode=yes truenas 'sudo docker exec ix-pixelfed-app-1 php artisan geo:backfill --places'
```

`--places` pins posts that already have a location; `--media` re-reads EXIF
but existing uploads are already stripped, so expect little.

## Rollback

Only needed if D or E fail in a way that can't be fixed forward. Image alone
is not enough once migrations ran.

```bash
ssh -o BatchMode=yes truenas 'sudo midclt call -j app.stop pixelfed'
ssh -o BatchMode=yes truenas 'sudo zfs rollback HDDs/Applications/PixelFed@pre-X.Y.Z-fork.N && sudo zfs rollback SSD/ApplicationsDataset/PixelFed@pre-X.Y.Z-fork.N'
ssh -o BatchMode=yes truenas 'sudo python3 - <previous-tag> --apply' < .claude/skills/pixelfed-fork-ops/scripts/truenas-bump-image.py
ssh -o BatchMode=yes truenas 'sudo midclt call -j app.start pixelfed'
```

If the previous deploy's run contract differed (mounts, env), the retag is
not enough - restore the previous YAML from the runbook's history.

## Afterwards

- Remind the user to untick passwordless sudo if they enabled it.
- Once stable for a few days: `docker rmi` the previous image, delete the old
  `~/src-*` clone, destroy the pre-upgrade snapshots.
- Record anything this deploy taught in `DEPLOY_TRUENAS.md` and commit it.
  The tag already cut won't carry it; the next one will.
