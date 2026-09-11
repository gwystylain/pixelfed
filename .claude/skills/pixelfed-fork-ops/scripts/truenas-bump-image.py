#!/usr/bin/env python3
"""Retag the Pixelfed app's stored compose config to a new local image.

Runs ON the TrueNAS host as root. From this PC:

    ssh truenas 'sudo python3 - 0.12.10-fork.2'          < scripts/truenas-bump-image.py   # dry run
    ssh truenas 'sudo python3 - 0.12.10-fork.2 --apply'  < scripts/truenas-bump-image.py   # apply

Dry run: writes /tmp/pixelfed-compose-<tag>.yaml, validates it with
`docker compose config`, prints a redacted diff. Changes nothing else.

--apply: additionally pushes the config with `midclt call -j app.update`.
That does NOT start a stopped app; `app.start` is a separate step, on
purpose, so the caller controls when downtime ends.

Why this exists: the stored config holds the DB passwords, so it should be
edited in place on the host rather than copied around; and the only change a
routine upgrade needs is the image tag on the three PHP services. Anything
more (mounts, env, new services) is a runbook change, not a script job.
"""
import copy
import difflib
import glob
import json
import os
import re
import subprocess
import sys

import yaml

APP = "pixelfed"
IMAGE_PREFIX = "local/pixelfed:"
CONFIG_GLOB = "/mnt/.ix-apps/app_configs/%s/versions/*/user_config.yaml" % APP


def die(msg, code=1):
    print("ERROR:", msg)
    sys.exit(code)


def run(cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, **kw)


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    apply = "--apply" in sys.argv
    if len(args) != 1:
        die("usage: truenas-bump-image.py <image-tag> [--apply]", 2)
    tag = args[0].lstrip("v")
    image = IMAGE_PREFIX + tag

    if os.geteuid() != 0:
        die("run as root (sudo); the stored config is root-only")

    # The image must already be built on this host.
    if run(["docker", "image", "inspect", image]).returncode != 0:
        die("image %s does not exist on this host. Build it first:\n"
            "  git clone --branch v%s --depth 1 https://github.com/gwystylain/pixelfed.git ~/src-%s\n"
            "  cd ~/src-%s && docker build -t %s ." % (image, tag, tag, tag, image))

    paths = sorted(glob.glob(CONFIG_GLOB), key=os.path.getmtime)
    if not paths:
        die("no stored config found at " + CONFIG_GLOB)
    src = paths[-1]

    with open(src) as f:
        cfg = yaml.safe_load(f)
    old = copy.deepcopy(cfg)

    changed = []
    for name, svc in cfg.get("services", {}).items():
        img = svc.get("image", "")
        if img.startswith(IMAGE_PREFIX) and img != image:
            svc["image"] = image
            changed.append("%s: %s -> %s" % (name, img, image))

    out = "/tmp/pixelfed-compose-%s.yaml" % tag
    with open(out, "w") as f:
        yaml.safe_dump(cfg, f, default_flow_style=False, sort_keys=False)
    os.chmod(out, 0o600)

    print("source:", src)
    print("output:", out)
    print("changes:", len(changed))
    for c in changed:
        print("  " + c)
    if not changed:
        print("nothing to do: every local/pixelfed service already runs", image)

    v = run(["docker", "compose", "-f", out, "config", "--quiet"])
    if v.returncode != 0:
        die("docker compose rejected the new config:\n" + v.stderr)
    print("compose validation: OK")

    redact = re.compile(r"(PASSWORD:\s*).*")
    a = yaml.safe_dump(old, default_flow_style=False, sort_keys=False).splitlines()
    b = yaml.safe_dump(cfg, default_flow_style=False, sort_keys=False).splitlines()
    diff = [redact.sub(r"\1<redacted>", l) for l in difflib.unified_diff(a, b, "stored", "new", lineterm="", n=1)]
    if diff:
        print("diff (redacted):")
        for l in diff[2:]:
            print("  " + l)

    if not apply:
        print("dry run only. Re-run with --apply to push it with app.update.")
        return
    if not changed:
        print("no changes; not applying.")
        return

    payload = json.dumps({"custom_compose_config_string": open(out).read()})
    r = run(["midclt", "call", "-j", "app.update", APP, payload])
    status = [l for l in (r.stdout + r.stderr).replace("\r", "\n").splitlines() if l.startswith("Status:")]
    for l in status[-3:]:
        print("  " + l)
    if r.returncode != 0:
        die("app.update failed (exit %d)" % r.returncode)

    q = run(["midclt", "call", "app.query", json.dumps([["name", "=", APP]])])
    state = json.loads(q.stdout)[0]["state"] if q.returncode == 0 else "?"
    print("applied. app state:", state)
    if state != "RUNNING":
        print("app.update does not start a stopped app. Next: midclt call -j app.start", APP)


if __name__ == "__main__":
    main()
