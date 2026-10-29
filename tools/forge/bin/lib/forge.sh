#!/bin/bash
# forge.sh — the forge dispatcher's state: execution slots, directory pools and
# the run queue. Source, don't execute. A forge run is an autofix ledger with
# mode "forge" in autofix's runs directory, so autofix's same-issue guard sees
# forge runs and the other way round.
# shellcheck disable=SC2034
FORGE_HOME="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
. "$FORGE_HOME/../autofix/bin/lib/common.sh"
require jq

: "${FORGE_STATE:=${XDG_STATE_HOME:-$HOME/.local/state}/newspack/forge}"
: "${FORGE_POOL_ROOT:=$FORGE_STATE/dirs}"
: "${FORGE_SLOTS:=1}"
: "${FORGE_POOL_SIZE:=3}"
FORGE_LEDGER="$AUTOFIX_BIN_DIR/ledger.sh"

# forge_ledgers — every forge run's ledger path, one per line. Slot and pool
# accounting must not guess, so an unreadable forge ledger stops it here.
forge_ledgers() {
  local lf
  for lf in "$RUNS_DIR"/forge-*/ledger.json; do
    [ -f "$lf" ] || continue
    jq -e 'type == "object"' "$lf" >/dev/null 2>&1 \
      || die "unreadable forge ledger: $lf (fix or move it; the forge launches nothing until then)"
    printf '%s\n' "$lf"
  done
}

# _each_ledger <jq-program> [jq args...] — run the program over every forge
# ledger. Returns 1 when the ledgers cannot be read.
_each_ledger() {
  local lfs lf
  lfs="$(forge_ledgers)" || return 1
  [ -n "$lfs" ] || return 0
  while IFS= read -r lf; do jq -r "$@" "$lf"; done <<EOF
$lfs
EOF
}

# A run holds a slot from launch until it is terminal or has delivered.
slot_holders() { _each_ledger 'select(.terminal == null and .delivery == null) | .run_id'; }

slot_free() { # 0 free, 1 busy, 2 run state unreadable
  local h n
  h="$(slot_holders)" || return 2
  n="$(printf '%s' "$h" | grep -c . || true)"
  [ "$n" -lt "$FORGE_SLOTS" ]
}

# A run holds its pool dir until the operator's decision retires it. A run whose
# launch failed never used the dir.
_POOL_HELD='select(.retired_at == null and ((.terminal // "") | test("^bailed-(launch|untrusted)$") | not))'

pool_holder() { _each_ledger "$_POOL_HELD"' | select(.pool_dir == $d) | .run_id' --arg d "$1"; }

pool_holders() { # key
  _each_ledger "$_POOL_HELD"' | select((.pool_dir // "") | startswith($p)) | .run_id' \
    --arg p "$FORGE_POOL_ROOT/$1/"
}

pool_take() { # key -> a free pool dir, created; 1 when every dir is held
  local key="$1" n=1 d h
  case "$key" in ""|.|..|*/*) die "not a pool key: $key" ;; esac
  while [ "$n" -le "$FORGE_POOL_SIZE" ]; do
    d="$FORGE_POOL_ROOT/$key/$n"
    h="$(pool_holder "$d")" || return 1
    if [ -z "$h" ]; then mkdir -p "$d"; printf '%s\n' "$d"; return 0; fi
    n=$((n + 1))
  done
  return 1
}

# The queue is one entry per line: "issue <ISSUE> <profile>" or "rework <run id>".
# One dispatcher session owns it, so there is no lock.
queue_add() {
  local q="$FORGE_STATE/queue"
  mkdir -p "$FORGE_STATE"; touch "$q"
  grep -Fxq -- "$*" "$q" || printf '%s\n' "$*" >> "$q"
}
queue_head() { [ -s "$FORGE_STATE/queue" ] && head -1 "$FORGE_STATE/queue"; }
queue_pop() {
  local q="$FORGE_STATE/queue"
  [ -s "$q" ] || return 1
  tail -n +2 "$q" > "$q.tmp" && mv "$q.tmp" "$q"
}

# Security-labeled issues wait here for a supervised autofix-secure session.
secure_queue_add() { # issue
  mkdir -p "$FORGE_STATE"
  printf '%s %s\n' "$1" "$(now_utc)" >> "$FORGE_STATE/secure-queue"
}
