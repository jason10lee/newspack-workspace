#!/bin/bash
# Unit tests for bin/worktree-mounts.sh (pure mount helpers). Host-runnable.
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }
nlines(){ printf '%s' "$1" | grep -c '.'; }

source "$BIN/worktree-mounts.sh"

# --- worktree_volume_lines ---
out=$(worktree_volume_lines "./worktrees/feat-x/plugins/newspack-newsletters" "/newspack-plugins/newspack-newsletters" "plugins/newspack-newsletters")
ok "tier1 plugin emits 2 lines" "$(nlines "$out")" "2"
ok "tier1 plugin serving line" "$(printf '%s\n' "$out" | grep -c -- '- ./worktrees/feat-x/plugins/newspack-newsletters:/newspack-plugins/newspack-newsletters$')" "1"
ok "tier1 plugin member line" "$(printf '%s\n' "$out" | grep -c -- '- ./worktrees/feat-x/plugins/newspack-newsletters:/newspack-monorepo/plugins/newspack-newsletters$')" "1"

out=$(worktree_volume_lines "./worktrees/feat-y/themes/newspack-theme" "/newspack-themes/newspack-theme" "themes/newspack-theme")
ok "tier1 theme member line at /newspack-monorepo/themes" "$(printf '%s\n' "$out" | grep -c -- '- ./worktrees/feat-y/themes/newspack-theme:/newspack-monorepo/themes/newspack-theme$')" "1"

out=$(worktree_volume_lines "./worktrees/standalone/newspack-community/jl-foo" "/newspack-plugins/newspack-community" "repos/plugins/newspack-community")
ok "tier2 emits 1 line" "$(nlines "$out")" "1"
ok "tier2 has no monorepo member" "$(printf '%s\n' "$out" | grep -c 'newspack-monorepo')" "0"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
