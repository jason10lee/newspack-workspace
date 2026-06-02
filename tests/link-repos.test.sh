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

echo "== link_or_repoint =="

# new link created
reset_wp; mkdir -p "$PLUGINS_PATH/foo"
( set -e; link_or_repoint "$PLUGINS_PATH/foo" "$WP/plugins/foo" ) >/dev/null 2>&1
ok_link "creates new symlink" "$WP/plugins/foo" "$PLUGINS_PATH/foo"

# idempotent: existing == src, unchanged
reset_wp; mkdir -p "$PLUGINS_PATH/foo"; ln -s "$PLUGINS_PATH/foo" "$WP/plugins/foo"
( set -e; link_or_repoint "$PLUGINS_PATH/foo" "$WP/plugins/foo" ) >/dev/null 2>&1
ok_link "idempotent re-run" "$WP/plugins/foo" "$PLUGINS_PATH/foo"

# repoint stale /newspack-repos mirror
reset_wp; mkdir -p "$PLUGINS_PATH/foo"; ln -s "$REPOS_PATH/foo" "$WP/plugins/foo"
( set -e; link_or_repoint "$PLUGINS_PATH/foo" "$WP/plugins/foo" ) >/dev/null 2>&1
ok_link "repoints stale REPOS_PATH mirror" "$WP/plugins/foo" "$PLUGINS_PATH/foo"

# foreign target -> slug collision, unchanged
reset_wp; mkdir -p "$PLUGINS_PATH/foo" "$FIX/foreign"; ln -s "$FIX/foreign" "$WP/plugins/foo"
( set -e; link_or_repoint "$PLUGINS_PATH/foo" "$WP/plugins/foo" ) >/dev/null 2>&1 || true
ok_link "collision left unchanged" "$WP/plugins/foo" "$FIX/foreign"

# real (non-symlink) file -> not clobbered
reset_wp; mkdir -p "$PLUGINS_PATH/foo"; echo real > "$WP/plugins/foo"
( set -e; link_or_repoint "$PLUGINS_PATH/foo" "$WP/plugins/foo" ) >/dev/null 2>&1 || true
ok "non-symlink not clobbered" "$( [ -L "$WP/plugins/foo" ] && echo symlink || echo file )" "file"

echo "== is_empty_dir =="
mkdir -p "$FIX/empty"
mkdir -p "$FIX/nonempty"; touch "$FIX/nonempty/x"
is_empty_dir "$FIX/empty";    ok "empty dir -> 0"     "$?" "0"
is_empty_dir "$FIX/nonempty"; ok "non-empty dir -> 1" "$?" "1"

echo "== skip_empty_stub =="
# link points into the stub (== src) -> removed
reset_wp; mkdir -p "$PLUGINS_PATH/stub"; ln -s "$PLUGINS_PATH/stub" "$WP/plugins/stub"
( set -e; skip_empty_stub "$PLUGINS_PATH/stub" "$WP/plugins/stub" ) >/dev/null 2>&1
ok "stub self-link removed" "$( [ -L "$WP/plugins/stub" ] && echo present || echo gone )" "gone"

# link points to a /newspack-repos mirror -> left intact
reset_wp; mkdir -p "$PLUGINS_PATH/stub"; ln -s "$REPOS_PATH/stub" "$WP/plugins/stub"
( set -e; skip_empty_stub "$PLUGINS_PATH/stub" "$WP/plugins/stub" ) >/dev/null 2>&1
ok_link "repos mirror left intact" "$WP/plugins/stub" "$REPOS_PATH/stub"

# ---- (subsequent tasks append their test sections here) ----

echo
echo "Results: $pass passed, $fail failed."
[ "$fail" -eq 0 ]
