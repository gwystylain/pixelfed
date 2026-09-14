# Environment: this PC and the TrueNAS host

Facts that are not derivable from the repo and that have each cost real time
when guessed. Verify anything marked *check* at the start of a session;
paths and flags are stable.

## This PC (Windows 11, commands run through Git Bash)

**PHP 8.4** is installed by winget but the tool's shells were spawned before
it went on PATH. Prefix every PHP/Composer call:

```bash
export PATH="/c/Users/Edward/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe:$PATH"
```

The POSIX `/c/...` form is not optional: the `C:/...` form contains a colon,
bash splits PATH on it, and `php` silently stays unresolved.

**Composer** is `php "C:/ProgramData/ComposerSetup/bin/composer.phar"`. The
`.bat` shim is awkward from bash. Installing needs three platform overrides -
`redis` is a PECL DLL not bundled on Windows, `pcntl` and `posix` are
POSIX-only and cannot exist here (Horizon wants them):

```bash
php "C:/ProgramData/ComposerSetup/bin/composer.phar" install \
  --ignore-platform-req=ext-redis --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix \
  --no-interaction --prefer-dist --no-progress
```

Re-run it whenever an upstream merge changes `composer.lock`. Enabled PHP
extensions live in `php.ini` next to `php.exe` (backup:
`php.ini.bak-composer-setup`); `gd` is on because intervention/image checks
for it at boot, which `artisan` needs.

**Tests and lint** (no DB or Redis needed - the geo tests extend plain
PHPUnit `TestCase`):

```bash
php vendor/bin/pest tests/Unit/Geo
php vendor/bin/pint --test app/Geo routes/geo.php config/geo.php tests/Unit/Geo bootstrap/providers.php database/migrations/2026_09_09_100000_add_geo_columns_to_media_table.php database/migrations/2026_09_09_100100_add_geo_columns_to_statuses_table.php database/migrations/2026_09_09_100200_add_coordinate_index_to_places_table.php
php artisan route:list --path=api/geo        # boots the app on framework defaults; 4 routes
php artisan route:cache && php artisan view:cache && php artisan optimize:clear   # proves the entrypoint automation will succeed
```

**Docker Desktop** is installed but usually not running. `build-assets.sh`
starts it and waits; by hand: `docker info` to check, then
`powershell.exe -Command "Start-Process 'C:\Program Files\Docker\Docker\Docker Desktop.exe'"`
and poll ~20 s. For any `docker run -v ...:/app`, set `MSYS_NO_PATHCONV=1`
and pass the host path in `C:/...` form (`cygpath -m`), or Git Bash rewrites
`/app` into a Windows path.

**Line endings.** `core.autocrlf=true`. After any checkout or merge the
working copy has CRLF while every committed blob is LF, so:

- Pint reports `line_ending` (and often `!`-spacing) on files git re-checked
  out. That is noise; `git diff` shows the real change, usually far smaller.
- When patching a doc with Python, read with `newline=''`, normalise CRLF to
  LF for matching, and write back with the file's original EOL. Run it as
  `python -X utf8 script.py` - a script fed on stdin decodes as cp1252 on
  Windows and an em-dash in the match string will never match.
- The Bash tool's heredocs collapse `\\` to `\`. Anything with backslashes
  (sed patterns, regexes) goes in a file via the Write tool, not a heredoc.

## The TrueNAS host

- **ssh:** `ssh truenas` (alias in `~/.ssh/config` -> `truenas_admin@192.168.1.82`,
  key `~/.ssh/id_ed25519_truenas`, no passphrase). Always
  `-o BatchMode=yes` so a missing key fails instead of prompting.
- **Root:** everything useful needs `sudo` (docker, midclt, zfs, the stored
  config). *Check* `ssh truenas 'sudo -n true && echo ok'`. Passwordless sudo
  is a checkbox on the `truenas_admin` user that Edward enables for a deploy
  and is meant to switch off after; if it's off, ask rather than work around.
- **App:** custom app `pixelfed`; containers `ix-pixelfed-{app,worker,scheduler,db,redis}-1`;
  web on host port `8095` (FrankenPHP on 8080 inside). Domain-scoped routes
  need a `Host:` header matching `APP_DOMAIN` when curling the port directly.
- **Datasets:** `HDDs/Applications/PixelFed` (media/storage) and
  `SSD/ApplicationsDataset/PixelFed` (db, redis, `pixelfed.env`). Each is its
  own ZFS dataset; two snapshots cover everything.
- **Stored compose:** `/mnt/.ix-apps/app_configs/pixelfed/versions/<ver>/user_config.yaml`
  (root-only, contains DB passwords). `scripts/truenas-bump-image.py` edits
  it in place.
- **Source clones** live in `~/src-<full tag>` on the host - `~/src-v0.12.10-fork.2`,
  matching the `--branch` argument. (The first two deploys used `~/src-fork1`
  and `~/src-fork2`, which collide across upstream bases.) The old ones can go
  after a successful deploy.

**midclt** (the TrueNAS CLI; one of the three supported ways to change
config):

- Wait-for-job flag is `-j`. `-job` is parsed as `-j` plus junk and the call
  **silently does nothing** - always confirm with
  `midclt call app.query '[["name","=","pixelfed"]]'` after a call.
- `app.update` on a stopped app leaves it stopped; `app.start` is separate.
- Pass JSON payloads by building them in Python and calling `midclt` via
  `subprocess` - shell quoting mangles the YAML inside.
- `app.stop`, `app.start`, `app.update` are all jobs.

**Snapshots:** `sudo zfs snapshot <dataset>@<name>` on both datasets, after
`app.stop` so the DB is clean rather than crash-consistent. Roll back with
`zfs rollback`. Daily `auto-*` snapshots also exist on the HDD dataset.
