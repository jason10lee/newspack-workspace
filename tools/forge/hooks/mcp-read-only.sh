#!/bin/bash
# PreToolUse hook for forge run sessions. An MCP tool runs only when its full
# name is a line of ../mcp-read-tools.txt; every other MCP tool is denied, from
# any server. Matching by server would let through dispatcher-style tools that
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
deny "forge: $name is not on the forge's MCP read list. Run sessions only read through MCP; every write waits for the dispatcher at delivery."
