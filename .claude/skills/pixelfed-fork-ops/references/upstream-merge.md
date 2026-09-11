# Merging an upstream release into the fork

Goal: `dev` becomes *new upstream tag + fork commits*, with the fork's
features proven to still work, the frontend rebuilt, and a fork tag ready to
deploy. Expect the merge itself to be the easy part; the work is in step 4.

## 1. Preflight (read-only)

```bash
bash .claude/skills/pixelfed-fork-ops/scripts/preflight.sh
```

It fetches upstream tags, names the newest upstream release and the fork's
current base, and - if a merge is due - lists incoming migrations, which of
the feature's dependency files changed, whether the Dockerfile or compose
changed (the deploy runbook may need updating), and a dry-run of textual
conflicts. Read the dependency-file list carefully: each entry is a place
the feature can break without any conflict.

If it says the fork is already on the newest release, there is nothing to
merge; say so. Don't merge `upstream/dev` because "there are newer commits".

## 2. Merge the tag

```bash
git checkout dev && git status --short          # must be clean and on dev
git merge --no-ff --no-commit vX.Y.Z
```

`--no-commit` so the semantic fixups from step 4 land in the merge commit,
where they belong: the merge commit is "the state where both sides work".

## 3. Resolve textual conflicts

They will be in the upstream files the feature edits (the inventory table in
`docs/fork/GEO_FEED.md` lists all eight, each insertion marked `pf-geo:`).
Take upstream's version of the surrounding code and re-apply the marked
block. Two special cases:

- **Compiled assets** (`public/js/*`, `public/css/*`, `public/mix-manifest.json`,
  `package-lock.json`): never resolve by hand. Take upstream's side
  (`git checkout vX.Y.Z -- public/js public/css public/mix-manifest.json package-lock.json`);
  they get rebuilt in step 6.
- **A block upstream deleted** (this happened: the `providers` array in
  `config/app.php` vanished). Don't re-add the block. Find where upstream
  moved that responsibility (`bootstrap/providers.php`) and register there.

## 4. Find the breakage git can't see

This is the step that matters. Work down the dependency checklist in
`docs/fork/GEO_FEED.md` ("Rebase procedure"): for every file `preflight.sh`
listed as changed, ask what the feature assumed about it.

Past examples, so you know what the shape of the problem is:

| Upstream change | What broke | Fix |
|---|---|---|
| Models moved `App\*` -> `App\Models\*` | 13 imports in 9 `app/Geo` files; `Media::observe()` in the provider would 500 every request | rename imports, re-sort them (Pint `ordered_imports`) |
| `providers` array removed from `config/app.php` | provider never registered | `bootstrap/providers.php` |
| Middleware aliases `validemail`/`twofactor` removed (`1d96c9405`) | every geo route threw on an unresolvable alias | `routes/geo.php` -> `['web', 'localization']`, mirroring upstream's own routes |

Concretely:

```bash
grep -rh '^use App' app/Geo routes/geo.php | sort -u          # every one must exist
grep -oE "middleware\(\[[^]]+\]" routes/geo.php               # every alias must be in bootstrap/app.php
grep -n "GeoServiceProvider" bootstrap/providers.php           # still registered
git grep -c 'pf-geo:' -- <the 7 marked files>                  # 12 expected
```

Then prove it rather than reason about it (see `environment.md` for the PATH
prefix and Composer flags):

```bash
composer install ...        # only if composer.lock changed
php vendor/bin/pest tests/Unit/Geo
php vendor/bin/pint --test <geo paths>
php artisan route:list --path=api/geo       # 4 routes; this boots the whole app
php artisan route:cache && php artisan view:cache && php artisan optimize:clear
```

`route:list` booting cleanly is the strongest single check: it runs every
service provider, loads every route file, resolves every controller.

## 5. Commit the merge

Stage the fixups with the merge and write the message so the next merge can
learn from it: what upstream changed, what broke, how it was fixed, what was
verified and what wasn't. Precedent: `git log -1 e70e47c1f` and `2a9b2551b`.

```
Merge tag 'vX.Y.Z' into dev

<what moved upstream and what needed changing in the fork, per item>
<what was verified: tests, pint, route:list, cache commands>
```

## 6. Rebuild the frontend

Always after an upstream merge - upstream's bundles came in without the
fork's UI. `references/frontend-build.md`. Commit as "Update compiled assets".

## 7. Update the docs, then tag

If anything in this run wasn't in the docs (a moved dependency, a changed
Dockerfile, a new env var), add it to `GEO_FEED.md` or `DEPLOY_TRUENAS.md`
now. Then `references/release-tag.md`, then `references/truenas-deploy.md`.

## If the merge goes badly

`git merge --abort` returns `dev` to where it was, as long as nothing was
committed. If the fixups are large, do them on a branch off `dev`
(`git switch -c merge-vX.Y.Z`) and fast-forward `dev` when green; the tag and
the deploy still come from `dev`.
