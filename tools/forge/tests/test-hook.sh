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
TD="$(mktemp -d)"; mkdir -p "$TD/tools/forge/hooks"; cp "$HOOK" "$TD/tools/forge/hooks/"
bash "$L" init forge-s NPPM-1 forge >/dev/null
f="$(write_run_settings forge-s "$TD")"
assert_eq "$RUNS_DIR/forge-s/settings.json" "$f" "settings are written beside the ledger"
assert_eq forge-s "$(jq -r .env.FORGE_RUN_ID "$f")" "settings carry the run id"
assert_eq "$WORKSPACE_ROOT" "$(jq -r .env.AUTOFIX_WORKSPACE_ROOT "$f")" "and the workspace root"
assert_eq "$RUNS_DIR" "$(jq -r .env.AUTOFIX_RUNS_DIR "$f")" "and the runs directory"
assert_eq 'mcp__.*' "$(jq -r '.hooks.PreToolUse[0].matcher' "$f")" "the hook matches every MCP tool"
assert_contains "$(jq -r '.hooks.PreToolUse[0].hooks[0].command' "$f")" "$TD/tools/forge/hooks/mcp-read-only.sh" "the hook runs from the pinned tooling"
out="$( (write_run_settings forge-s "$(mktemp -d)") 2>&1)"; rc=$?
assert_eq 1 "$rc" "settings refuse tooling without the hook"
assert_contains "$out" "no MCP hook" "and say so"

finish
