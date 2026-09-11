#!/usr/bin/env bash
# Read-only. Reports where the fork stands relative to upstream and whether an
# upstream merge is due. Safe to run any time; it only fetches tags.
#
# Usage: scripts/preflight.sh            (from anywhere inside the repo)
#
# The "dependency files" list mirrors the post-merge checklist in
# docs/fork/GEO_FEED.md. When a fork feature grows a new upstream dependency,
# add it in both places.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if ! git remote get-url upstream >/dev/null 2>&1; then
  echo "no 'upstream' remote; add it: git remote add upstream https://github.com/pixelfed/pixelfed.git"
  exit 1
fi

git fetch upstream --tags --quiet

newest_up=$(git tag --sort=-v:refname | grep -v -- -fork | head -1)
newest_fork=$(git tag --sort=-v:refname | grep -- -fork | head -1 || true)

# The fork's base is the newest upstream tag that is already an ancestor of dev.
base=""
for t in $(git tag --sort=-v:refname | grep -v -- -fork); do
  if git merge-base --is-ancestor "$t" dev 2>/dev/null; then base="$t"; break; fi
done

echo "fork base (newest upstream tag in dev):  ${base:-unknown}"
echo "newest upstream tag:                     $newest_up"
echo "newest fork tag:                         ${newest_fork:-none}"
echo "dev vs origin/dev:                       $(git rev-list --left-right --count dev...origin/dev 2>/dev/null | awk '{print "ahead "$1", behind "$2}')"
echo

if [ "$base" = "$newest_up" ]; then
  echo "STATUS: fork is on the newest upstream release. No merge due."
else
  echo "STATUS: upstream $newest_up is newer than the fork base $base. A merge is due."
  echo
  echo "== incoming commits: $(git rev-list --count "$base".."$newest_up")"
  echo
  echo "== incoming migrations:"
  git diff --name-only --diff-filter=A "$base" "$newest_up" -- database/migrations/ | sed 's|.*/|  |' || true
  echo
  echo "== dependency files changed (each one can break the geo feature without a conflict):"
  git diff --stat "$base" "$newest_up" -- \
    bootstrap/app.php bootstrap/providers.php config/app.php \
    app/Models/Media.php app/Models/Status.php app/Models/Place.php \
    app/Services/PlaceService.php app/Services/StatusService.php app/Services/UserFilterService.php \
    app/Http/Controllers/Controller.php \
    Dockerfile docker-compose.yml .env.example composer.json package.json \
    | sed 's/^/  /' || true
  echo
  echo "== textual conflicts if merged now (dry run, nothing is changed):"
  mt=$(mktemp)
  if git merge-tree --write-tree --name-only dev "$newest_up" >"$mt" 2>&1; then
    echo "  none"
  else
    grep -E "CONFLICT" "$mt" | sed 's/^/  /' || true
  fi
  rm -f "$mt"
  echo
  echo "== upstream Dockerfile / compose changed? (the runbook's YAML may need updating)"
  if git diff --quiet "$base" "$newest_up" -- Dockerfile docker-compose.yml; then
    echo "  no"
  else
    echo "  YES - diff them and compare against docs/fork/DEPLOY_TRUENAS.md before deploying"
  fi
fi

echo
echo "== fork health:"
markers=$(git grep -c 'pf-geo:' -- .env.example bootstrap/providers.php webpack.mix.js app/Util/Site/Config.php \
  resources/views/layouts/partial/nav.blade.php resources/assets/components/partials/sidebar.vue \
  resources/assets/js/components/ComposeModal.vue 2>/dev/null | awk -F: '{s+=$2} END {print s+0}')
echo "  pf-geo markers in upstream files: $markers / 12 expected"
echo "  compiled geo assets tracked:      $(git ls-files public/js/geo.js public/css/geo.css | wc -l | tr -d ' ')/2"
echo "  leaflet in package.json:          $(grep -c '"leaflet"' package.json)"
echo "  working tree changed files:       $(git status --porcelain | wc -l | tr -d ' ')"
