#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
export FORGE_PLUGINS="" AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
export FORGE_LAUNCH_TIMEOUT=90
. ../bin/lib/forge.sh

T="$(mktemp -d)"; mkdir -p "$T/tools/forge/hooks"; : > "$T/tools/forge/hooks/mcp-read-only.sh"; : > "$T/tools/forge/hooks/test-suite-guard.sh"
bash "$L" init forge-e NPPM-1 forge >/dev/null
s="$(write_run_settings forge-e "$T")"
assert_eq "$FORGE_STATE" "$(jq -r .env.FORGE_STATE "$s")" "the run's settings carry the forge state dir"
assert_eq 150 "$(jq -r .env.FORGE_LAUNCH_WAIT "$s")" "and a launch wait longer than the dispatcher's timeout"
assert_eq "$s" "$(jq -r .env.REPRISE_SETTINGS "$s")" "the settings name themselves for sessions the run starts through reprise"

E="$(mktemp)"
AUTOFIX_LINEAR_MOCK_DIR=/tmp/mock FORGE_SOMETHING=1 CLAUDE_CODE_SESSION_ID=abc KEEP_ME=1 STUB_ENV_LOG="$E" \
  launch_session forge-e "$(mktemp -d)" "$s" "Read the brief." >/dev/null 2>&1
env_seen="$(cat "$E")"
assert_eq "" "$(grep -E '^(AUTOFIX_|FORGE_)' <<<"$env_seen" || true)" "no AUTOFIX_ or FORGE_ variable reaches the run"
assert_eq "" "$(grep '^CLAUDE_CODE_SESSION_ID=' <<<"$env_seen" || true)" "nor the dispatcher's session id"
assert_contains "$env_seen" "KEEP_ME=1" "other variables pass through"
assert_contains "$(tail -1 "$STUB_LOG")" "--bg -n forge-e" "the session still starts"

r="$(FORGE_LAUNCH_WAIT='' bash -c '. ../bin/lib/forge.sh; printf %s "$FORGE_LAUNCH_WAIT"' 2>/dev/null)"
assert_eq 150 "$r" "an empty FORGE_LAUNCH_WAIT takes the default"

finish
