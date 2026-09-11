#!/usr/bin/env bash
# Rebuild the compiled frontend (public/js, public/css, mix-manifest.json) in a
# throwaway node container, then verify the output. No local Node needed.
#
# Usage: scripts/build-assets.sh          (from anywhere inside the repo)
#
# Why a container: upstream commits compiled assets and the Dockerfile has no
# npm step, so the fork must commit them too. Building on Linux inside Docker
# matches how upstream's own bundles are produced.
#
# Why these flags:
#   --legacy-peer-deps  upstream pins blurhash@^2 against vue-blurhash's ^1
#                       peer; a plain `npm install` refuses. Undocumented
#                       upstream, inferred from their lockfile.
#   npm install (not ci) the lockfile must be allowed to pick up fork-added
#                       packages such as leaflet.
#   named volume        node_modules on the Linux side; installing it across a
#                       Windows bind mount takes ages.
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
cd "$root"

# --- Docker engine ---------------------------------------------------------
if ! docker info >/dev/null 2>&1; then
  case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*)
      echo "Docker engine not reachable; starting Docker Desktop (usually ~20s)..."
      powershell.exe -NoProfile -Command \
        "Start-Process -FilePath 'C:\Program Files\Docker\Docker\Docker Desktop.exe'" >/dev/null 2>&1 || true
      for _ in $(seq 1 36); do
        sleep 5
        docker info >/dev/null 2>&1 && break
      done
      ;;
  esac
  docker info >/dev/null 2>&1 || { echo "Docker engine still not reachable. Start Docker Desktop and retry."; exit 1; }
fi

# --- host path for the bind mount -------------------------------------------
host_path="$root"
case "$(uname -s)" in
  MINGW*|MSYS*|CYGWIN*)
    host_path="$(cygpath -m "$root")"   # C:/... form; docker on Windows wants this
    export MSYS_NO_PATHCONV=1           # stop Git Bash rewriting /app into a Windows path
    ;;
esac

# --- build ------------------------------------------------------------------
echo "building in node:20-bookworm from $host_path ..."
docker run --rm \
  -v "$host_path:/app" \
  -v pixelfed_node_modules:/app/node_modules \
  -w /app \
  -e NODE_OPTIONS=--max-old-space-size=6144 \
  -e CI=true \
  node:20-bookworm \
  sh -c "npm install --legacy-peer-deps --no-audit --no-fund --loglevel=error && npm run production" \
  2>&1 | grep -vE "legacy-js-api|Deprecation|More info|^<w>|^\s*$|^LOG from" || true

# --- verify -----------------------------------------------------------------
echo
echo "== verification"
fail=0
check() { # label, condition-result
  if [ "$2" -eq 0 ]; then echo "  ok    $1"; else echo "  FAIL  $1"; fail=1; fi
}
[ -s public/js/geo.js ];   check "public/js/geo.js exists" $?
[ -s public/css/geo.css ]; check "public/css/geo.css exists" $?
grep -q '"/js/geo.js"' public/mix-manifest.json;   check "manifest has /js/geo.js" $?
grep -q '"/css/geo.css"' public/mix-manifest.json; check "manifest has /css/geo.css" $?
grep -lq 'geo-suggest' public/js/compose*.js;      check "compose bundle contains geo-suggest" $?
grep -lq 'Photo Map' public/js/*.js;               check "some bundle contains the Photo Map link" $?
[ ! -e public/hot ];                               check "no public/hot left behind" $?
[ "$(find public/js public/css -name '*.map' | wc -l)" -eq 0 ]; check "no .map files produced" $?

echo
echo "== what git sees (only real content changes survive line-ending normalisation)"
git status --short -- public package-lock.json | awk '{print $1}' | sort | uniq -c | sed 's/^/  /'
echo "  content diff: $(git diff --stat -- public package-lock.json | tail -1)"
echo
if [ "$fail" -eq 0 ]; then
  echo "build OK. Commit public/js public/css public/mix-manifest.json package-lock.json as \"Update compiled assets\"."
else
  echo "build produced output but a check failed; do not commit until it is understood."
  exit 1
fi
