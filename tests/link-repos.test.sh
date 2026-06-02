#!/bin/bash
#
# Unit tests for bin/link-repos.sh decision logic + loop orchestration.
# Pure shell + synthetic fixtures under a temp dir; no Docker.
#
# Usage: tests/link-repos.test.sh   (exits non-zero on failure)
set -u

BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"
trap 'rm -rf "$FIX"' EXIT

pass=0; fail=0
ok() {
	if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass + 1));
	else echo "  FAIL  $1"; echo "        expected: [$3]"; echo "        got:      [$2]"; fail=$((fail + 1)); fi
}
# Compare a symlink's target to an expected path, ignoring a cosmetic trailing
# slash (loop globs yield "…/name/"; direct calls may not).
ok_link() { ok "$1" "$(readlink "$2" 2>/dev/null | sed 's:/$::')" "${3%/}"; }

# Pre-set path vars so sourcing skips the container-only sources and main()
# stays guarded. These are the fixture roots every test builds under.
PLUGINS_PATH="$FIX/newspack-plugins"
THEMES_PATH="$FIX/newspack-themes"
REPOS_PATH="$FIX/newspack-repos"
mkdir -p "$PLUGINS_PATH" "$THEMES_PATH" "$REPOS_PATH"

source "$BIN/link-repos.sh"

WP="$FIX/wp-content"
reset_wp() { rm -rf "$WP"; mkdir -p "$WP/plugins" "$WP/themes"; }

echo "== sourceability =="
ok "main is defined"           "$(type -t main)"           "function"
ok "link_standalone defined"   "$(type -t link_standalone)" "function"
reset_wp
# Sourcing must not have created any links (main() guarded).
ok "no links created on source" "$(find "$WP" -type l | wc -l | tr -d ' ')" "0"

# ---- (subsequent tasks append their test sections here) ----

echo
echo "Results: $pass passed, $fail failed."
[ "$fail" -eq 0 ]
