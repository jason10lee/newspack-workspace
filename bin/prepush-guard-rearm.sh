#!/bin/sh
# bin/prepush-guard-rearm.sh
#
# Re-arms the newspack-workspace pre-push guard after husky's own
# `prepare` step resets core.hooksPath (husky always re-sets it on every
# `pnpm install`, which is what disarms the guard in the first place).
#
# Silent and safe to run anywhere, on any checkout, on any machine:
#   - If the guard package isn't present on this machine, this is a no-op.
#     That is the expected common case everywhere except this operator's
#     machine (upstream checkouts, CI, other contributors' machines never
#     have it, and never should).
#   - If this checkout's `origin` remote doesn't point at the gated
#     upstream URL, this is also a no-op (e.g. a checkout of a fork, or
#     some other repo entirely).
#   - Only when both are true does it re-apply core.hooksPath.
#
# This script lives only on this machine's fork-trunk `package.json`
# `prepare` chain -- upstream's `package.json` never carries this call, so
# upstream checkouts never even invoke it. See
# ~/.agent-knowledge/git-guards/newspack-workspace/README.md for the full
# guard semantics; this script only knows how to re-point core.hooksPath,
# nothing about push gating itself.
#
# No output on any path -- see prepare's tolerant chaining (`|| true`) in
# package.json, which this script's own always-0 exit status backs up.
set -eu

GUARD_DIR="${NEWSPACK_PREPUSH_GUARD_DIR:-$HOME/.agent-knowledge/git-guards/newspack-workspace/hooks}"
UPSTREAM_URL_RE='github\.com[:/]Automattic/newspack-workspace(\.git)?$'

[ -d "$GUARD_DIR" ] || exit 0

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P 2>/dev/null) || exit 0
REPO_TOP=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P 2>/dev/null) || exit 0

ORIGIN_URL=$(git -C "$REPO_TOP" remote get-url origin 2>/dev/null) || exit 0
[ -n "$ORIGIN_URL" ] || exit 0

printf '%s\n' "$ORIGIN_URL" | grep -Eq "$UPSTREAM_URL_RE" 2>/dev/null || exit 0

git -C "$REPO_TOP" config core.hooksPath "$GUARD_DIR" 2>/dev/null || exit 0

exit 0
