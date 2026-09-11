---
name: pixelfed-fork-ops
description: Operate Edward's Pixelfed fork (gwystylain/pixelfed) and its live TrueNAS/HexOS instance end to end - check for and merge upstream release tags, resolve the fork's conflicts and the silent breakages a clean merge hides, rebuild and commit the compiled frontend, cut vX.Y.Z-fork.N release tags, build the Docker image on the host, and deploy, upgrade, verify or roll back the running instance. Use this whenever the user mentions updating, rebasing, merging or syncing the fork, a new Pixelfed release, shipping or deploying a feature, upgrading or rolling back pixelfed on TrueNAS, rebuilding assets, tagging a release, the pixelfed docker image, or anything broken on the live instance - even if they don't say "fork", "upstream" or "deploy".
---

# Pixelfed fork operations

This fork is `dev` = **newest upstream release tag + fork commits**, carrying
fork-only features (currently the geo feed / photo map) that are never sent
upstream. The live instance at `https://pixelfed.noobatron.duckdns.org` runs
on a TrueNAS SCALE box and is built only from **fork tags** (`vX.Y.Z-fork.N`).
The repo's own docs are authoritative for detail; this skill is the
operator's checklist that keeps you from re-deriving it:

- `docs/fork/GEO_FEED.md` - what the feature touches, the post-merge dependency checklist
- `docs/fork/DEPLOY_TRUENAS.md` - the deploy runbook, reference YAML, rollback, lessons

Read `references/environment.md` first in any session that will run
commands: this PC and the host both have quirks that silently no-op or
mangle commands (a PATH colon, a heredoc backslash, a `-job` flag).

## Which situation is this?

| The user wants... | Do this | Read |
|---|---|---|
| to know if upstream has a new release / where the fork stands | `scripts/preflight.sh` (read-only) | - |
| to update / rebase / sync / merge upstream | preflight -> merge the tag -> verify -> rebuild assets -> tag -> deploy | `references/upstream-merge.md` then the two below |
| to ship a finished feature (no new upstream) | rebuild assets if `resources/assets` changed -> tests -> tag `-fork.N+1` -> deploy | `references/frontend-build.md`, `references/release-tag.md`, `references/truenas-deploy.md` |
| to deploy / upgrade the TrueNAS instance | build image from the tag -> snapshot -> **confirm** -> stop, apply, start, migrate -> verify | `references/truenas-deploy.md` |
| to check the live instance / something looks broken | `scripts/truenas-verify.sh <tag>` (read-only), then read the failures | `references/truenas-deploy.md` (Verify, Rollback) |
| to roll back | restore both snapshots, retag to the previous image, start | `references/truenas-deploy.md` (Rollback) |
| just to rebuild the frontend | `scripts/build-assets.sh`, commit as "Update compiled assets" | `references/frontend-build.md` |

## Invariants - the reasoning, so you can apply them to cases not listed

**Merge upstream tags; never `upstream/dev`, never rebase.** Upstream's `dev`
is their integration line - `staging` merges into it continuously and
releases are tagged from it - so a mid-cycle fetch is an untested snapshot
with no changelog. Tags make the conflict work happen once per release at a
known point. And it's a *merge* because a rebase rewrites the fork's commits,
which detaches every fork tag from `dev` and means the commit the instance is
running no longer exists in its own branch's history. History stays
append-only; nothing is ever force-pushed. The user may say "rebase onto the
new release" - they mean "move to the new base", and the mechanism is a merge.

**A clean merge is not a working merge.** The fork's own files never conflict
because upstream doesn't have them, so git cannot warn when upstream changes
something the feature depends on. Both merges so far were textually clean and
broken: 0.12.10 moved `App\Media` to `App\Models\Media` (every request would
have 500'd), deleted the `providers` array the feature registered in, and
removed two middleware aliases the geo routes named. After every merge, diff
the incoming range against the feature's *dependencies* (`preflight.sh` does
this) and run the checklist in GEO_FEED.md, then prove it with tests,
`route:list` and the cache commands before calling it done.

**Compiled assets are source.** Upstream commits `public/js`, `public/css`
and `mix-manifest.json`, and the Dockerfile has no npm step - it copies the
tree. A frontend change that isn't rebuilt and committed does not exist as
far as the image is concerned; the map page would 500 on `mix('js/geo.js')`.
Rebuild after any `resources/assets` change and after every upstream merge.

**Deploy only from a fork tag, never from `dev`.** The tag is what the host
clones and what the image is named after; it's the only thing that makes
"what is running" answerable. Tag = `vX.Y.Z-fork.N`, image =
`local/pixelfed:X.Y.Z-fork.N`.

**Snapshot before migrate; confirm before downtime.** Migrations are not
reversible, so rollback is a snapshot restore, not an image swap. The one
point to stop and ask is right before `app.stop` - everything before it
(clone, build, generate and validate the config, snapshot) is safe to do
unasked, and everything after it should follow without pause so the outage
stays short. That is the autonomy level Edward chose; keep it.

## Hard limits

- Never open a pull request against `pixelfed/pixelfed`, never push to the
  `upstream` remote, never force-push `origin`. The fork is private work.
- Never copy `user_config.yaml` or `pixelfed.env` off the host or print their
  contents: they hold the database passwords. Edit them in place, redact diffs.
- Never run `php artisan cache:clear` on the instance (it broke Passport once);
  restart the container instead. `config:clear`/`route:clear` are fine.
- Never `docker rmi` the previous image or destroy the pre-upgrade snapshots
  in the same session as an upgrade; they are the rollback.
- Don't handle the user's passwords. If `sudo -n true` fails on the host, ask
  the user either to tick "Allow all sudo commands with no password" on
  `truenas_admin` temporarily, or to run the root steps themselves from the
  commands you give them - and remind them to untick it afterwards.

## What "done" means

Say a stage is done only when its proof exists:

| Stage | Proof |
|---|---|
| Merge | `preflight.sh` shows the new base; GEO_FEED.md checklist run; `php vendor/bin/pest tests/Unit/Geo` green; `pint --test` on the geo paths clean; `php artisan route:list --path=api/geo` shows 4 routes; `route:cache`/`view:cache` succeed (then `optimize:clear`) |
| Assets | `build-assets.sh` verification all `ok`; committed as "Update compiled assets" |
| Tag | annotated `vX.Y.Z-fork.N` pushed with `dev`; `git tag --sort=-v:refname \| grep -- -fork \| head -1` shows it |
| Deploy | `truenas-verify.sh <tag>` prints `ALL CHECKS PASSED`; the user has opened the map in a browser |

Report what was verified and what wasn't, plainly. Something you could not
check (no browser, no test data) is not something that passed.

## When something new is learned

Every run so far taught something the docs didn't have. When that happens,
put it where the next person will look: `docs/fork/DEPLOY_TRUENAS.md` for
anything about the host or the image, `docs/fork/GEO_FEED.md` for anything
about the feature's dependencies, this skill's `references/` for anything
about the tooling on this PC. Commit it with the work, not later.
