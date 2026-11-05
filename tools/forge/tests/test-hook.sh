#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
HOOK=../hooks/mcp-read-only.sh
call() { printf '{"tool_name":"%s","tool_input":{}}' "$1" | bash "$2"; }
decision() { [ -n "$1" ] || { echo allow; return; }; jq -r '.hookSpecificOutput.permissionDecision // "allow"' <<<"$1"; }

# against the real list
assert_eq "" "$(call Bash "$HOOK")" "a non-MCP tool passes untouched"
assert_eq "" "$(call mcp__plugin_linear_linear__get_issue "$HOOK")" "a listed Linear read is allowed"
assert_eq deny "$(decision "$(call mcp__plugin_linear_linear__save_comment "$HOOK")")" "a Linear write is denied"
assert_eq deny "$(decision "$(call mcp__plugin_linear_linear__save_issue "$HOOK")")" "a Linear issue edit is denied"
assert_eq deny "$(decision "$(call mcp__github__create_pull_request "$HOOK")")" "a GitHub write is denied"
assert_eq deny "$(decision "$(call mcp__context-a8c__context-a8c-execute-tool "$HOOK")")" "a dispatcher-style tool is denied"
assert_eq deny "$(decision "$(call mcp__plugin_linear_linear__get_issue_x "$HOOK")")" "a name extending a listed one is denied"
assert_contains "$(call mcp__github__create_pull_request "$HOOK")" "read list" "the deny reason names the read list"
out="$(printf 'not json' | bash "$HOOK")"
assert_eq deny "$(decision "$out")" "unparseable input is denied"

# the browser reaches this run's env and nothing else
PW=mcp__plugin_playwright_playwright
assert_eq "" "$(call ${PW}__browser_click "$HOOK")" "a listed browser tool is allowed"
assert_eq "" "$(call mcp__playwright__browser_snapshot "$HOOK")" "under either server name"
assert_eq deny "$(decision "$(call ${PW}__browser_evaluate "$HOOK")")" "page scripts are denied: they could leave the env"
assert_eq deny "$(decision "$(call ${PW}__browser_run_code "$HOOK")")" "and so is running code"
N="$(mktemp -d)"; mkdir -p "$N/run"
printf '{"env":{"name":"autofix-nppm-1-ab12"}}' > "$N/run/ledger.json"
nav() { # url [tool]
  jq -n --arg t "${2:-${PW}__browser_navigate}" --arg u "$1" '{tool_name: $t, tool_input: {url: $u}}' \
    | AUTOFIX_RUNS_DIR="$N" FORGE_RUN_ID=run bash "$HOOK"
}
assert_eq "" "$(nav https://autofix-nppm-1-ab12.test/wp-admin/?a=1)" "navigating to the run's env is allowed"
assert_eq "" "$(nav http://autofix-nppm-1-ab12.test)" "with no path too"
assert_eq "" "$(nav https://autofix-nppm-1-ab12.test:8443/ mcp__playwright__browser_navigate)" "under either server name, with a port"
for u in https://example.com/ https://other.test/ https://autofix-nppm-1-ab12.test.example.com/ \
  https://autofix-nppm-1-ab12.test@example.com/ 'https://autofix-nppm-1-ab12.test:1@example.com/' \
  'https://autofix-nppm-1-ab12.test\@example.com/' 'https://example.com/https://autofix-nppm-1-ab12.test/' \
  file:///etc/passwd ''; do
  assert_eq deny "$(decision "$(nav "$u")")" "navigation to '$u' is denied"
done
assert_contains "$(nav https://example.com/)" "autofix-nppm-1-ab12.test" "the deny names the host a run may reach"
printf '{"env":{"name":"example.com/x"}}' > "$N/run/ledger.json"
assert_eq deny "$(decision "$(nav https://example.com/x.test/)")" "a ledger name that is not a plain label widens nothing"
printf '{"env":null}' > "$N/run/ledger.json"
assert_eq deny "$(decision "$(nav https://autofix-nppm-1-ab12.test/)")" "a run with no env may not navigate"
printf '{"env":{"name":"autofix-nppm-1-ab12"}}' > "$N/run/ledger.json"
out="$(printf '{"tool_name":"%s__browser_navigate","tool_input":{"url":"https://autofix-nppm-1-ab12.test/"}}' "$PW" | env -u FORGE_RUN_ID -u AUTOFIX_RUNS_DIR bash "$HOOK")"
assert_eq deny "$(decision "$out")" "outside a run, navigation is denied"

# a copy with no list beside it denies everything MCP
T="$(mktemp -d)"; mkdir -p "$T/hooks"; cp "$HOOK" "$T/hooks/"
assert_eq deny "$(decision "$(call mcp__plugin_linear_linear__get_issue "$T/hooks/mcp-read-only.sh")")" "a missing list denies (fail closed)"
assert_eq "" "$(call Read "$T/hooks/mcp-read-only.sh")" "a missing list leaves non-MCP tools alone"

# the list cannot be swapped through the environment
printf 'mcp__plugin_linear_linear__save_comment\n' > "$T/list.txt"
out="$(printf '{"tool_name":"mcp__plugin_linear_linear__save_comment"}' | FORGE_MCP_READ_LIST="$T/list.txt" bash "$HOOK")"
assert_eq deny "$(decision "$out")" "an environment variable does not widen the list"

# run settings
. ../bin/lib/forge.sh
TD="$(mktemp -d)"; mkdir -p "$TD/tools/forge/hooks"; cp "$HOOK" ../hooks/test-suite-guard.sh "$TD/tools/forge/hooks/"
bash "$L" init forge-s NPPM-1 forge >/dev/null
f="$(write_run_settings forge-s "$TD")"
assert_eq "$RUNS_DIR/forge-s/settings.json" "$f" "settings are written beside the ledger"
assert_eq forge-s "$(jq -r .env.FORGE_RUN_ID "$f")" "settings carry the run id"
assert_eq "$WORKSPACE_ROOT" "$(jq -r .env.AUTOFIX_WORKSPACE_ROOT "$f")" "and the workspace root"
assert_eq "$RUNS_DIR" "$(jq -r .env.AUTOFIX_RUNS_DIR "$f")" "and the runs directory"
assert_eq 'mcp__.*' "$(jq -r '.hooks.PreToolUse[0].matcher' "$f")" "the hook matches every MCP tool"
assert_contains "$(jq -r '.hooks.PreToolUse[0].hooks[0].command' "$f")" "$TD/tools/forge/hooks/mcp-read-only.sh" "the hook runs from the pinned tooling"
assert_eq Bash "$(jq -r '.hooks.PreToolUse[1].matcher' "$f")" "the suite guard matches Bash"
assert_contains "$(jq -r '.hooks.PreToolUse[1].hooks[0].command' "$f")" "$TD/tools/forge/hooks/test-suite-guard.sh" "and runs from the pinned tooling"
TG="$(mktemp -d)"; mkdir -p "$TG/tools/forge/hooks"; cp "$HOOK" "$TG/tools/forge/hooks/"
err="$(mktemp)"; f="$(write_run_settings forge-s "$TG" 2>"$err")"; rc=$?
assert_eq 0 "$rc" "tooling pinned before the suite guard still gets settings, so its run can be reworked"
assert_eq 1 "$(jq '.hooks.PreToolUse | length' "$f")" "with only the MCP hook"
assert_contains "$(cat "$err")" "predates the suite guard" "and says the run goes without it"
out="$( (write_run_settings forge-s "$(mktemp -d)") 2>&1)"; rc=$?
assert_eq 1 "$rc" "settings refuse tooling without the hook"
assert_contains "$out" "no MCP hook" "and say so"

finish
