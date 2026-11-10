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

mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees"
D="$(mktemp -d)"; S="$D/settings.json"; echo '{}' > "$S"
id="$(launch_session forge-l "$D" "$S" "Read the brief." 2>/dev/null)"
assert_eq 1a2b3c4d "$id" "launch_session reads the session id"
line="$(tail -1 "$STUB_LOG")"
assert_eq "$D" "${line%%|*}" "the session starts in the pool dir"
assert_contains "$line" "--bg -n forge-l --permission-mode auto --settings $S" "with the run's name, mode and settings"
assert_contains "$line" "--plugin-dir $P/a --plugin-dir $P/b" "and the workspace plugins"
assert_contains "$line" "--add-dir $AUTOFIX_WORKSPACE_ROOT/worktrees" "and the workspace's worktrees, so a run can cd into its own"
assert_contains "$line" "Read the brief." "and the prompt"

STUB_BG_OUT='Workspace not trusted. Run `claude` in /x once and accept the trust prompt, then retry.' \
  launch_session forge-l "$D" "$S" p >/dev/null 2>&1; rc=$?
assert_eq 3 "$rc" "an untrusted directory returns 3"
STUB_BG_RC=1 STUB_BG_OUT='Workspace not trusted. Run `claude` in /x once and accept the trust prompt, then retry.' \
  launch_session forge-l "$D" "$S" p >/dev/null 2>&1; rc=$?
assert_eq 3 "$rc" "an untrusted directory returns 3 when claude exits non-zero too"
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

bash "$L" init forge-c3 NPPM-3 forge >/dev/null
waiting_live; confirm_launch forge-c3 1a2b3c4d; rc=$?
assert_eq 0 "$rc" "a new session already waiting on a prompt is confirmed, not abandoned"
assert_eq confirmed "$(bash "$L" get forge-c3 .launch)" "launch=confirmed is recorded for it"

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
waiting; assert_eq waiting "$(session_state 1a2b3c4d)" "a blocked row without a pid is waiting, not gone"
waiting_live; assert_eq waiting "$(session_state 1a2b3c4d)" "a blocked row that still has its pid is waiting, not alive"
printf '[{"id":"1a2b3c4d","kind":"background","state":"stopped"}]\n' > "$STUB_AGENTS"
assert_eq gone "$(session_state 1a2b3c4d)" "a stopped row without a pid is gone"
printf '[{"id":"1a2b3c4d","kind":"background","state":"somethingnew"}]\n' > "$STUB_AGENTS"
assert_eq unknown "$(session_state 1a2b3c4d)" "a state it does not know, without a pid, is unknown"
printf '[{"id":"1a2b3c4d","kind":"background"}]\n' > "$STUB_AGENTS"
assert_eq unknown "$(session_state 1a2b3c4d)" "no state and no pid is unknown"

finish
