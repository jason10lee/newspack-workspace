# shellcheck shell=bash disable=SC2034  # sourced: the tests use these variables
# setup.sh — shared fixture for forge tests. Source after autofix's helpers.sh,
# from the tests directory.
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
RUNS_DIR="$AUTOFIX_ROOT/runs"   # what common.sh derives when AUTOFIX_ROOT is given
export FORGE_STATE; FORGE_STATE="$(mktemp -d)"
export FORGE_POOL_ROOT="$FORGE_STATE/dirs"
export FORGE_POLL_INTERVAL=0 FORGE_LAUNCH_TIMEOUT=0 FORGE_STOP_TIMEOUT=0
export STUB_LOG; STUB_LOG="$(mktemp)"
export STUB_AGENTS; STUB_AGENTS="$(mktemp)"; echo '[]' > "$STUB_AGENTS"
export STUB_NOTIFY_LOG; STUB_NOTIFY_LOG="$(mktemp)"
PATH="$(pwd)/stubs:$PATH"; export PATH
L=../../autofix/bin/ledger.sh
F=../bin/forge
alive() { printf '[{"id":"%s","kind":"background","pid":4242,"state":"blocked"}]\n' "${1:-1a2b3c4d}" > "$STUB_AGENTS"; }
gone()  { printf '[{"id":"%s","kind":"background","state":"done"}]\n' "${1:-1a2b3c4d}" > "$STUB_AGENTS"; }
