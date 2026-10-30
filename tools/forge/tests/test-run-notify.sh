#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
FX=../../autofix/tests/fixtures
R=../bin/forge-run
fr() { bash "$R" "$@" 2>/dev/null; }
mock() { # rid issue-json-file
  M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
  cp "$FX/viewer.json" "$FX/states.json" "$FX/issueUpdate.json" "$M/"
  cp "$2" "$M/issue.json"
  sed "s/RUNID/$1/" "$FX/issue_postclaim_ok.json" > "$M/issue_postclaim.json"
  sed "s/RUNID/$1/" "$FX/issue_release_unchanged.json" > "$M/issue_release.json"
}
fresh() { AUTOFIX_ROOT="$(mktemp -d)"; export AUTOFIX_ROOT; }
claimed() { fresh; bash "$L" init "$1" NPPM-2993 forge >/dev/null; bash "$L" set "$1" '.launch = "confirmed"'
  mock "$1" "$FX/issue_ok.json"; bash ../../autofix/bin/claim.sh claim NPPM-2993 "$1" >/dev/null 2>&1; }
packet() { local p; p="$(dirname "$(bash "$L" path "$1")")/packet.md"; shift
  { echo "# Forge run"; for h in "$@"; do printf '%s\nx\n' "$h"; done; } > "$p"; printf '%s' "$p"; }
calls() { grep -c '^osascript|' "$STUB_NOTIFY_LOG" || true; }

# the notify command
fresh; bash "$L" init f-n NPPM-2993 forge >/dev/null
: > "$STUB_NOTIFY_LOG"
out="$(fr notify f-n waiting on a permission prompt in session ab12cd34)"; rc=$?
assert_eq 0 "$rc" "notify succeeds"
assert_contains "$out" "NOTIFIED f-n" "and says so"
assert_contains "$(cat "$STUB_NOTIFY_LOG")" "osascript|- forge NPPM-2993 waiting on a permission prompt in session ab12cd34" "the title names the issue and the message follows"
assert_contains "$(cat "$STUB_NOTIFY_LOG")" "display notification (item 2 of argv) with title (item 1 of argv)" "the text is passed as arguments, not spliced into the script"
assert_eq "waiting on a permission prompt in session ab12cd34" "$(bash "$L" get f-n '.stage_history[-1].notes')" "and the ledger records it"
: > "$STUB_NOTIFY_LOG"
fr notify f-n 'say "hi" $(touch /tmp/forge-notify-injected) `id`' >/dev/null
assert_contains "$(cat "$STUB_NOTIFY_LOG")" 'say "hi" $(touch /tmp/forge-notify-injected) `id`' "a message with quotes and a command substitution arrives intact"
assert_eq no "$([ -e /tmp/forge-notify-injected ] && echo yes || echo no)" "and nothing in it ran"
fr notify f-n >/dev/null; assert_eq 1 $? "notify needs a message"

# each ending notifies once
claimed f-s; : > "$STUB_NOTIFY_LOG"
fr stop f-s "$(packet f-s "## Why it stopped" "## Friction")" scope grew >/dev/null
assert_eq 1 "$(calls)" "a stop notifies once"
assert_contains "$(cat "$STUB_NOTIFY_LOG")" "stopped" "and says the run stopped"

claimed f-r; : > "$STUB_NOTIFY_LOG"
fr route-secure f-r "$(packet f-r "## Friction")" >/dev/null
assert_eq 1 "$(calls)" "routing to autofix-secure notifies once"
assert_contains "$(cat "$STUB_NOTIFY_LOG")" "autofix-secure" "and names where it went"

W="$(mktemp -d)"; git -C "$W" init -q; git -C "$W" -c user.name=t -c user.email=t@example.com commit -q --allow-empty -m one
claimed f-d; bash "$L" set f-d '.worktree = $w | .prepared = {head: $h}' --arg w "$W" --arg h "$(git -C "$W" rev-parse HEAD)"
: > "$STUB_NOTIFY_LOG"
out="$(STUB_OSA_FAIL=1 bash "$R" deliver f-d "$(packet f-d "## Contested decisions" "## Friction" "## Choices")" 2>/dev/null)"; rc=$?
assert_eq 0 "$rc" "a failing osascript does not fail the delivery"
assert_contains "$out" "DELIVERED f-d" "the run is delivered"
assert_eq 1 "$(calls)" "after one notification attempt"
assert_contains "$(cat "$STUB_NOTIFY_LOG")" "delivered" "which says the run delivered"

finish
