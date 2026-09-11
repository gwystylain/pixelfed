# Building the compiled frontend

## When

- Anything under `resources/assets` changed (a `.vue`, `.js`, `.scss`).
- After every upstream merge: upstream's committed bundles don't contain the
  fork's components, and taking upstream's side in the conflict (as
  `upstream-merge.md` says to) guarantees they don't.
- `package.json` gained or bumped a dependency.

If none of those happened, don't rebuild: the build is deterministic, so a
no-op rebuild produces no diff, but it costs a couple of minutes.

## How

```bash
bash .claude/skills/pixelfed-fork-ops/scripts/build-assets.sh
```

It starts Docker Desktop if needed, runs `npm install --legacy-peer-deps`
and `npm run production` in a `node:20-bookworm` container with
`node_modules` on a named Linux volume, then checks the output. Read its
verification block; every line should be `ok`. Then:

```bash
git add -A public/js public/css public/mix-manifest.json package-lock.json
git commit -m "Update compiled assets"
```

"Update compiled assets" is upstream's own message for exactly this; keep it
so the history reads the same way theirs does. A short body noting *why*
(which feature, or which upstream merge) is welcome.

## What the output should look like

- `public/js/geo.js` (~10 KB) and `public/css/geo.css` (~13 KB) exist and are
  in `mix-manifest.json`.
- Content-hashed chunk filenames rotate: dozens of `D`eleted and `??` new
  files in `git status` is normal, not a problem. Every chunk that bundles
  the sidebar changes when the sidebar does.
- Leaflet lands in `public/js/vendor.js` (+~150 KB) via `mix.extract()`,
  not in `geo.js`. The map page reaches it through the layout's `vendor.js`
  script tag - `vendor.js` registers itself as the chunk id that `geo.js`'s
  dynamic `import('leaflet')` requests. No separate chunk is fetched. That
  bundle is served to every page; excluding Leaflet from `extract()` so only
  the map loads it is an open optimisation, not done.

## Why the flags (so you can fix it when npm changes)

- `--legacy-peer-deps`: upstream's `package.json` pins `blurhash@^2` while
  `vue-blurhash@0.1.4` declares a `blurhash@^1` peer. Their lockfile resolves
  it by ignoring the peer - visible as no nested `blurhash` under
  `node_modules/vue-blurhash` in `package-lock.json` - which is what this
  flag does. Without it npm 7+ refuses with `ERESOLVE`. Not documented
  upstream anywhere.
- `npm install`, not `npm ci`: `ci` requires the lockfile to already match
  `package.json`, and fork-added packages (leaflet) mean it doesn't until
  the first install writes it.
- A root-level `css-loader@7` entry marked `"peer": true` gets pruned from
  the lockfile by this flag; Laravel Mix uses its own nested copy, nothing
  references the root one.
- Sass prints hundreds of `legacy-js-api` deprecation warnings from
  upstream's components. The script filters them; they are not errors.
