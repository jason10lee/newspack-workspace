#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
. ../bin/lib/forge.sh
mk() { bash "$L" init "$1" "$2" forge >/dev/null; }
state() { "$@" && echo yes || echo "no:$?"; }

# --- slots -------------------------------------------------------------------
assert_eq "" "$(slot_holders)" "no runs → no slot holders"
assert_eq yes "$(state slot_free)" "no runs → slot free"
mk forge-a NPPM-1
assert_eq forge-a "$(slot_holders)" "a working run holds the slot"
assert_eq no:1 "$(state slot_free)" "one working run fills the one slot"
bash "$L" set forge-a '.delivery = {kind:"delivered", at:"2026-10-29T00:00:00Z"}'
assert_eq yes "$(state slot_free)" "a delivered run frees the slot"
mk forge-b NPPM-2; bash "$L" set forge-b '.terminal = "bailed-dead"'
assert_eq "" "$(slot_holders)" "a terminal run holds no slot"
mk forge-c NPPM-3
FORGE_SLOTS=2; assert_eq yes "$(state slot_free)" "the slot count is a setting"; FORGE_SLOTS=1
bash "$L" init autofix-x NPPM-4 operator-named >/dev/null
assert_eq forge-c "$(slot_holders)" "autofix runs hold no forge slot"

mkdir -p "$RUNS_DIR/forge-bad"; echo '{oops' > "$RUNS_DIR/forge-bad/ledger.json"
out="$(slot_free 2>&1)"; rc=$?
assert_eq 2 "$rc" "an unreadable forge ledger makes slot_free return 2"
assert_contains "$out" "unreadable forge ledger" "and names the problem"
rm -rf "$RUNS_DIR/forge-bad"

# --- pools -------------------------------------------------------------------
K=newspack-workspace-tooling
d1="$FORGE_POOL_ROOT/$K/1"; d2="$FORGE_POOL_ROOT/$K/2"
assert_eq "$d1" "$(pool_take "$K")" "the first free pool dir is 1"
assert_eq yes "$(state test -d "$d1")" "pool_take creates the dir"
bash "$L" set forge-c '.pool_dir = $d' --arg d "$d1"
assert_eq "$d2" "$(pool_take "$K")" "a held dir is skipped"
bash "$L" set forge-c '.delivery = {kind:"delivered", at:"2026-10-29T00:00:00Z"}'
assert_eq "$d2" "$(pool_take "$K")" "a delivered run keeps its dir until retired"
bash "$L" set forge-c '.retired_at = "2026-10-29T00:00:00Z"'
assert_eq "$d1" "$(pool_take "$K")" "a retired run frees its dir"
FORGE_POOL_SIZE=1; mk forge-d NPPM-5; bash "$L" set forge-d '.pool_dir = $d' --arg d "$d1"
assert_eq no:1 "$(state pool_take "$K")" "a full pool yields nothing"
assert_eq forge-d "$(pool_holders "$K")" "pool_holders names who holds a full pool"
bash "$L" set forge-d '.terminal = "bailed-untrusted"'
assert_eq "$d1" "$(pool_take "$K")" "a run that never launched does not hold its dir"
FORGE_POOL_SIZE=3
out="$( (pool_take ../x) 2>&1)"; rc=$?
assert_eq 1 "$rc" "a pool key with a slash is refused"
assert_contains "$out" "not a pool key" "and says why"

# --- queue -------------------------------------------------------------------
assert_eq no:1 "$(state queue_head)" "empty queue → queue_head fails"
queue_add issue NPPM-9 "$K"; queue_add issue NPPM-9 "$K"; queue_add rework forge-c
assert_eq "issue NPPM-9 $K" "$(queue_head)" "queue_head is the oldest entry"
assert_eq 2 "$(wc -l < "$FORGE_STATE/queue" | tr -d ' ')" "a duplicate entry is not added"
queue_pop
assert_eq "rework forge-c" "$(queue_head)" "queue_pop drops the head"
secure_queue_add NPPM-7
assert_contains "$(cat "$FORGE_STATE/secure-queue")" NPPM-7 "secure_queue_add records the issue"

finish
