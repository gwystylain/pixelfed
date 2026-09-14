#!/usr/bin/env bash
# Read-only health check of the live Pixelfed instance on TrueNAS, run from
# this PC over ssh. Prints one PASS/FAIL line per check and exits non-zero if
# anything failed. Safe to run at any time; it changes nothing.
#
# Usage: scripts/truenas-verify.sh [expected-image-tag]
#   e.g. scripts/truenas-verify.sh 0.12.10-fork.1
#
# Needs: the `truenas` ssh alias (see references/environment.md) and
# passwordless sudo on the host for the docker/midclt checks. If sudo needs a
# password the script says so and stops rather than half-checking.
set -uo pipefail

expected_tag="${1:-}"

ssh -o BatchMode=yes -o ConnectTimeout=10 truenas "bash -s" -- "$expected_tag" <<'REMOTE'
set -uo pipefail
expected_tag="${1:-}"
fail=0
pass() { echo "  PASS  $1"; }
failx() { echo "  FAIL  $1"; fail=1; }
warn() { echo "  WARN  $1"; }

if ! sudo -n true 2>/dev/null; then
  echo "sudo needs a password on this host; the docker/midclt checks can't run non-interactively."
  echo "Either enable 'Allow all sudo commands with no password' for truenas_admin temporarily,"
  echo "or run the checks from docs/fork/DEPLOY_TRUENAS.md by hand."
  exit 2
fi

app=ix-pixelfed-app-1
worker=ix-pixelfed-worker-1
sched=ix-pixelfed-scheduler-1
envfile=/mnt/SSD/ApplicationsDataset/PixelFed/pixelfed.env
storage=/mnt/HDDs/Applications/PixelFed/storage

echo "== app state"
state=$(sudo midclt call app.query '[["name","=","pixelfed"]]' 2>/dev/null | python3 -c 'import json,sys; a=json.load(sys.stdin); print(a[0]["state"] if a else "MISSING")')
[ "$state" = "RUNNING" ] && pass "app state RUNNING" || failx "app state is $state"

echo "== containers"
for c in $app $worker $sched ix-pixelfed-db-1 ix-pixelfed-redis-1; do
  st=$(sudo docker inspect -f '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' "$c" 2>/dev/null || echo missing)
  case "$st" in
    "running healthy"|"running ") pass "$c ($st)";;
    running*) warn "$c ($st)";;
    *) failx "$c ($st)";;
  esac
done

echo "== image"
for c in $app $worker $sched; do
  img=$(sudo docker inspect -f '{{.Config.Image}}' "$c" 2>/dev/null || echo "?")
  if [ -n "$expected_tag" ]; then
    [ "$img" = "local/pixelfed:$expected_tag" ] && pass "$c runs $img" || failx "$c runs $img, expected local/pixelfed:$expected_tag"
  else
    echo "  info  $c runs $img"
  fi
done

echo "== database schema"
pending=$(sudo docker exec "$app" php artisan migrate:status 2>/dev/null | grep -ci pending || true)
[ "${pending:-0}" -eq 0 ] && pass "no pending migrations" || failx "$pending pending migration(s) - run: php artisan migrate --force"

echo "== routes"
n_api=$(sudo docker exec "$app" php artisan route:list --path=api/geo 2>/dev/null | grep -c "api/geo/v1" || true)
n_map=$(sudo docker exec "$app" php artisan route:list --path=discover/map 2>/dev/null | grep -c "discover/map" || true)
[ "${n_api:-0}" -eq 4 ] && pass "4 geo API routes registered" || failx "geo API routes: $n_api (expected 4)"
[ "${n_map:-0}" -eq 1 ] && pass "discover/map registered" || failx "discover/map routes: $n_map (expected 1)"

echo "== config as the app sees it"
cfg=$(sudo docker exec "$app" php artisan tinker --execute='echo json_encode(["geo"=>config("geo.enabled"),"cache"=>config("cache.default")]);' 2>/dev/null | tail -1)
case "$cfg" in *'"geo":true'*) pass "geo.enabled = true";; *) failx "geo.enabled not true ($cfg)";; esac
case "$cfg" in *'"cache":"redis"'*) pass "cache store = redis";; *) warn "cache store: $cfg";; esac

echo "== workers"
hz=$(sudo docker exec "$worker" php artisan horizon:status 2>/dev/null | tr -d '\n' | sed 's/\x1b\[[0-9;]*m//g' | xargs || true)
case "$hz" in *running*) pass "horizon: $hz";; *) failx "horizon: ${hz:-no output}";; esac
last_sched=$(sudo docker logs --tail 200 "$sched" 2>&1 | grep -E "Running \[" | tail -1 | sed 's/^\s*//')
[ -n "$last_sched" ] && pass "scheduler last ran: ${last_sched:0:70}" || warn "scheduler has not logged a run yet"

echo "== http (host port 8095, unauthenticated)"
domain=$(sudo grep -oE '^APP_DOMAIN=.*' "$envfile" | cut -d= -f2- | tr -d '"'"'"' ')
code() { curl -s -o /dev/null -w '%{http_code}' -H "Host: $domain" "http://127.0.0.1:8095$1"; }
c=$(code /discover/map);    [ "$c" = "302" ] && pass "/discover/map -> 302 (login redirect)" || failx "/discover/map -> $c (expected 302)"

# Asset list comes from the manifest, not from names written here. The map
# page gained spa.css and a hash-named chunk at 0.12.10-fork.2, and a check
# that spells its files out by hand keeps passing while a new one 404s.
# Requesting the unversioned key is right: the ?id= is only a cache buster.
assets=$(sudo docker exec "$app" cat public/mix-manifest.json 2>/dev/null | python3 -c '
import json, sys
try:
    manifest = json.load(sys.stdin)
except Exception:
    sys.exit(1)
# Everything the feature ships, however many chunks that turns into...
want = [k for k in manifest if k.startswith("/js/geo") or k.startswith("/css/geo")]
# ...plus the bundles every page needs and the one the map page borrows.
for k in ("/css/spa.css", "/js/manifest.js", "/js/vendor.js", "/js/app.js", "/js/components.js"):
    if k in manifest:
        want.append(k)
print("\n".join(sorted(set(want))))
' 2>/dev/null)

if [ -z "$assets" ]; then
  failx "could not read public/mix-manifest.json from $app - asset checks skipped"
else
  for a in $assets; do
    c=$(code "$a")
    [ "$c" = "200" ] && pass "$a -> 200" || failx "$a -> $c"
  done
fi

c=$(code /api/geo/v1/feed); [ "$c" = "401" ] && pass "/api/geo/v1/feed -> 401 (auth required)" || failx "/api/geo/v1/feed -> $c (expected 401)"

echo "== durable state on the datasets"
for f in "$storage/oauth-private.key" "$storage/oauth-public.key" "$envfile"; do
  [ -f "$f" ] && pass "$(basename "$f") present" || failx "$f MISSING"
done

echo "== app log"
errs=$(sudo docker logs --tail 300 "$app" 2>&1 | grep -iE "error|exception|fatal" | grep -viE "error_log|error-handler|tls\.|caddy" | wc -l)
[ "$errs" -eq 0 ] && pass "no errors in the last 300 log lines" || failx "$errs error line(s) in the app log - read: docker logs --tail 300 $app"

echo
[ "$fail" -eq 0 ] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit "$fail"
REMOTE
