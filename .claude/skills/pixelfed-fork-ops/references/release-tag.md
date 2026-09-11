# Cutting a fork release tag

A fork tag is what the host clones and what the image is named after. It is
the only durable answer to "what is running"; `dev` moves.

## Scheme

`vX.Y.Z-fork.N` - the upstream release the fork is currently based on, plus
a counter that resets when the base changes.

| Situation | Previous tag | New tag |
|---|---|---|
| merged upstream `v0.12.11` | `v0.12.10-fork.3` | `v0.12.11-fork.1` |
| shipped a fork feature, same base | `v0.12.10-fork.1` | `v0.12.10-fork.2` |
| fixed a fork bug, same base | `v0.12.10-fork.2` | `v0.12.10-fork.3` |

Never reuse or move a tag that has been pushed, even seconds later: the
runbook's rule is that history is append-only, and a moved tag breaks
"what is running" for anyone who cloned it. If a tag is wrong, cut the next
one.

**Sort gotcha.** `git tag --sort=-v:refname` puts `v0.12.10-fork.1` *above*
`v0.12.10` - git's version sort doesn't know semver pre-release rules. Filter
when looking for one kind or the other:

```bash
git tag --sort=-v:refname | grep -v -- -fork | head -1     # newest upstream release
git tag --sort=-v:refname | grep -- -fork | head -1        # newest fork release
```

## Before tagging - the tree must be deployable as-is

- `dev` is what you're tagging; `git status` clean; `git log origin/dev..dev`
  is what's about to be published.
- Compiled assets rebuilt and committed if anything under `resources/assets`
  changed or an upstream merge happened (`frontend-build.md`).
- `php vendor/bin/pest tests/Unit/Geo` green and `pint --test` clean.
- `docs/fork/*.md` updated with anything learned; the tag carries its own
  runbook to the host, so a doc fix after the tag doesn't reach it.

## Tag and push

Annotated, with a message that says what the release contains relative to
the previous one and anything the deploy needs to know:

```bash
git tag -a v0.12.10-fork.2 -F - <<'EOF'
v0.12.10-fork.2

Upstream v0.12.10 plus:
- geo feed (unchanged from fork.1)
- <the new feature or fix, one line each>

Deploy per docs/fork/DEPLOY_TRUENAS.md. <migrations? YAML changes? none?>
EOF
git push origin dev v0.12.10-fork.2
```

Push the branch and the tag in one command so `origin/dev` and the tag are
never out of step. The GitHub "create a pull request" hint after a push is
irrelevant: no PRs, ever, in either direction.
