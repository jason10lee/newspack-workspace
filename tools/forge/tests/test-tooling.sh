#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
REPO="$(mktemp -d)"; export FORGE_TOOLING_REPO="$REPO"
g() { git -C "$REPO" -c user.name=t -c user.email=t@example.com "$@"; }
g init -q -b main
mkdir -p "$REPO/tools/autofix" "$REPO/.claude/skills/forge" "$REPO/plugins/x"
echo v1 > "$REPO/tools/autofix/x.sh"; echo skill > "$REPO/.claude/skills/forge/SKILL.md"
echo product > "$REPO/plugins/x/file.php"
g add -A -f; g commit -qm one  # -f: a global gitignore may cover .claude/
. ../bin/lib/forge.sh

pin="$(pin_tooling)"; sha="${pin%% *}"; dir="${pin#* }"
assert_eq "$(g rev-parse main)" "$sha" "pin_tooling pins local main's SHA"
assert_eq "$FORGE_STATE/tooling/$sha" "$dir" "the export is named by its SHA"
assert_eq v1 "$(cat "$dir/tools/autofix/x.sh")" "tools/ is exported"
assert_eq skill "$(cat "$dir/.claude/skills/forge/SKILL.md")" ".claude/skills/ is exported"
assert_eq no "$([ -e "$dir/plugins" ] && echo yes || echo no)" "nothing else is exported"
assert_eq no "$([ -w "$dir/tools/autofix/x.sh" ] && echo yes || echo no)" "exported files are read-only"
assert_eq "$pin" "$(pin_tooling)" "pinning again at the same SHA reuses the export"

echo dirty > "$REPO/tools/autofix/x.sh"
echo v2 > "$REPO/tools/autofix/x.sh.new"
pin2="$(pin_tooling)"
assert_eq "$pin" "$pin2" "uncommitted edits in the checkout change nothing"
assert_eq v1 "$(cat "${pin2#* }/tools/autofix/x.sh")" "and do not reach the export"

g checkout -q -- tools; rm -f "$REPO/tools/autofix/x.sh.new"
echo v2 > "$REPO/tools/autofix/x.sh"; g commit -qam two
pin3="$(pin_tooling)"
assert_eq "$(g rev-parse main)" "${pin3%% *}" "a new main SHA gets a new export"
assert_eq v2 "$(cat "${pin3#* }/tools/autofix/x.sh")" "with the new content"

# prune keeps what an unretired run references and removes the rest
bash "$L" init forge-p NPPM-1 forge >/dev/null
bash "$L" set forge-p '.tooling = {sha:$s, dir:$d}' --arg s "$sha" --arg d "$dir"
prune_tooling 2>/dev/null
assert_eq yes "$([ -d "$dir" ] && echo yes || echo no)" "prune keeps a referenced export"
assert_eq no "$([ -d "${pin3#* }" ] && echo yes || echo no)" "prune removes an unreferenced one"
bash "$L" set forge-p '.retired_at = "2026-10-29T00:00:00Z"'
prune_tooling 2>/dev/null
assert_eq no "$([ -d "$dir" ] && echo yes || echo no)" "prune removes a retired run's export"

FORGE_TOOLING_REPO="$(mktemp -d)"
out="$( (pin_tooling) 2>&1)"; rc=$?
assert_eq 1 "$rc" "pin_tooling dies without a main branch"
assert_contains "$out" "cannot resolve main" "and says so"

finish
