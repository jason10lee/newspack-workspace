#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
P="$(mktemp -d)"; mkdir -p "$P/a" "$P/b"
export AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
export FORGE_PLUGINS_JSON="$P/installed.json" FORGE_PLUGINS="a@m b@m c@m"
jq -n --arg w "$AUTOFIX_WORKSPACE_ROOT" --arg a "$P/a" --arg b "$P/b" '{plugins: {
  "a@m": [{scope: "local", projectPath: "/elsewhere", installPath: "/nope"},
          {scope: "local", projectPath: $w, installPath: $a}],
  "b@m": [{scope: "project", projectPath: $w, installPath: $b}]}}' > "$FORGE_PLUGINS_JSON"
. ../bin/lib/forge.sh

out="$(plugin_dirs 2>&1)"
assert_eq "$P/a" "$(plugin_dirs 2>/dev/null | head -1)" "plugin_dirs picks the workspace's install"
assert_eq 2 "$(plugin_dirs 2>/dev/null | grep -c .)" "one dir per installed plugin"
assert_contains "$out" "c@m is not installed" "a missing plugin is reported"

D="$(mktemp -d)"; S="$D/settings.json"; echo '{}' > "$S"
id="$(launch_session forge-l "$D" "$S" "Read the brief." 2>/dev/null)"
assert_eq 1a2b3c4d "$id" "launch_session reads the session id"
line="$(tail -1 "$STUB_LOG")"
assert_eq "$D" "${line%%|*}" "the session starts in the pool dir"
assert_contains "$line" "--bg -n forge-l --permission-mode auto --settings $S" "with the run's name, mode and settings"
assert_contains "$line" "--plugin-dir $P/a --plugin-dir $P/b" "and the workspace plugins"
assert_contains "$line" "Read the brief." "and the prompt"

STUB_BG_OUT='Workspace not trusted. Run `claude` in /x once and accept the trust prompt, then retry.' \
  launch_session forge-l "$D" "$S" p >/dev/null 2>&1; rc=$?
assert_eq 3 "$rc" "an untrusted directory returns 3"
STUB_BG_OUT='something else' launch_session forge-l "$D" "$S" p >/dev/null 2>&1; rc=$?
assert_eq 1 "$rc" "output without an id returns 1"

alive; assert_eq yes "$(session_alive 1a2b3c4d && echo yes || echo no)" "a row with a pid is alive"
gone;  assert_eq no "$(session_alive 1a2b3c4d && echo yes || echo no)" "a row without a pid is gone"
echo '[]' > "$STUB_AGENTS"
assert_eq no "$(session_alive 1a2b3c4d && echo yes || echo no)" "no row is gone"

bash "$L" init forge-c1 NPPM-1 forge >/dev/null
alive; confirm_launch forge-c1 1a2b3c4d; rc=$?
assert_eq 0 "$rc" "a live session is confirmed"
assert_eq confirmed "$(bash "$L" get forge-c1 .launch)" "launch=confirmed is recorded"
assert_eq confirmed "$(bash "$L" get forge-c1 '.stage_history[-1].outcome')" "and logged"

bash "$L" init forge-c2 NPPM-2 forge >/dev/null
: > "$STUB_LOG"; echo '[]' > "$STUB_AGENTS"
confirm_launch forge-c2 1a2b3c4d; rc=$?
assert_eq 1 "$rc" "an unseen session is abandoned"
assert_eq abandoned "$(bash "$L" get forge-c2 .launch)" "launch=abandoned is recorded"
assert_eq bailed-launch "$(bash "$L" get forge-c2 .terminal)" "and the run is terminal"
assert_contains "$(cat "$STUB_LOG")" "stop 1a2b3c4d" "a late session is told to stop"

out="$( (confirm_launch forge-c1 1a2b3c4d) 2>&1)"; rc=$?
assert_eq 1 "$rc" "confirming twice is refused"
assert_contains "$out" "already 'confirmed'" "and says so"

assert_eq unknown "$(STUB_AGENTS_FAIL=1 session_state 1a2b3c4d)" "a failing claude agents is unknown"
echo 'not json' > "$STUB_AGENTS"
assert_eq unknown "$(session_state 1a2b3c4d)" "output that is not a JSON array is unknown"
echo '[]' > "$STUB_AGENTS"
assert_eq unknown "$(session_state 1a2b3c4d)" "a session with no row is unknown"
gone;  assert_eq gone "$(session_state 1a2b3c4d)" "a row without a pid is gone"
alive; assert_eq alive "$(session_state 1a2b3c4d)" "a row with a pid is alive"

finish
