#!/bin/bash
# PreToolUse hook for forge run sessions. An MCP tool runs only when its full
# name is a line of ../mcp-read-tools.txt, or it is browser navigation within
# the run's env; every other MCP tool is denied, from any server. Matching by server would let through dispatcher-style tools that
# write under a name naming neither Linear nor GitHub. The list's location is
# fixed relative to this file, so nothing a run sets can widen it, and anything
# that goes wrong here denies.
set -uo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
list="$here/../mcp-read-tools.txt"

deny() {
  if command -v jq >/dev/null 2>&1; then
    jq -n --arg r "$1" '{hookSpecificOutput: {hookEventName: "PreToolUse",
      permissionDecision: "deny", permissionDecisionReason: $r}}'
    exit 0
  fi
  printf '%s\n' "$1" >&2
  exit 2
}

input="$(cat)"
name="$(printf '%s' "$input" | jq -er '.tool_name' 2>/dev/null)" \
  || deny "forge: could not read the tool name, so the call is denied"
case "$name" in mcp__*) ;; *) exit 0 ;; esac
[ -r "$list" ] || deny "forge: the MCP read list is missing, so every MCP call is denied"
grep -Fxq -- "$name" "$list" && exit 0
case "$name" in
  mcp__playwright__browser_navigate|mcp__plugin_playwright_playwright__browser_navigate)
    # A review's live tier drives the run's env. Navigation is not on the list
    # because a URL can carry content to any host, so only that env's host
    # passes. The ledger is the only place the env's name exists, and a run can
    # write to it, so the name must also be a plain label: at worst a run
    # reaches another .test host, which never resolves publicly.
    url="$(printf '%s' "$input" | jq -r '.tool_input.url // empty' 2>/dev/null)"
    env_name="$(jq -r '.env.name // empty' "${AUTOFIX_RUNS_DIR:-}/${FORGE_RUN_ID:-}/ledger.json" 2>/dev/null)"
    plain_label='^[a-z0-9-]+$'
    [[ $env_name =~ $plain_label ]] || deny "forge: this run has no env, so the browser may not navigate"
    same_host='^https?://([a-z0-9-]+\.test)(:[0-9]+)?(/[^[:space:]]*)?$'
    [[ $url =~ $same_host ]] && [ "${BASH_REMATCH[1]}" = "$env_name.test" ] && exit 0
    deny "forge: a run's browser may only navigate within $env_name.test"
    ;;
esac
deny "forge: $name is not on the forge's MCP read list. Run sessions only read through MCP; every write waits for the dispatcher at delivery."
