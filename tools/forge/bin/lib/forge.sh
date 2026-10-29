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

# --- pinned tooling ----------------------------------------------------------
# A run invokes autofix's and the forge's scripts from an export of local main
# taken at launch, never from a checkout: its own edits to tools/ and other
# sessions' uncommitted edits in the shared root checkout cannot reach it. An
# export rather than a worktree, because a monorepo worktree per main SHA is a
# full checkout each time, and a sparse one writes to the shared repo config.
: "${FORGE_TOOLING_REPO:=$WORKSPACE_ROOT}"
FORGE_TOOLING_PATHS="tools .claude/skills"

pin_tooling() { # -> "<sha> <dir>"
  local sha dir tmp p have=""
  sha="$(git -C "$FORGE_TOOLING_REPO" rev-parse -q --verify 'refs/heads/main^{commit}')" \
    || die "cannot resolve main in $FORGE_TOOLING_REPO"
  dir="$FORGE_STATE/tooling/$sha"
  if [ ! -d "$dir" ]; then
    mkdir -p "$FORGE_STATE/tooling"
    for p in $FORGE_TOOLING_PATHS; do
      git -C "$FORGE_TOOLING_REPO" cat-file -e "$sha:$p" 2>/dev/null && have="$have $p"
    done
    [ -n "$have" ] || die "main at $sha has none of: $FORGE_TOOLING_PATHS"
    tmp="$(mktemp -d "$FORGE_STATE/tooling/.pin.XXXXXX")"
    # shellcheck disable=SC2086  # $have is a list of paths
    if ! git -C "$FORGE_TOOLING_REPO" archive --format=tar "$sha" $have | tar -x -C "$tmp"; then
      rm -rf "$tmp"; die "could not export tooling at $sha"
    fi
    printf '%s\n' "$sha" > "$tmp/.forge-tooling-sha"
    # Contents first: on macOS, renaming a directory needs write access to it.
    find "$tmp" -mindepth 1 -exec chmod a-w {} +
    if ! mv "$tmp" "$dir" 2>/dev/null; then
      chmod -R u+w "$tmp"; rm -rf "$tmp"
      [ -d "$dir" ] || die "could not place the tooling export at $dir"
    fi
    chmod a-w "$dir"
  fi
  [ "$(cat "$dir/.forge-tooling-sha" 2>/dev/null)" = "$sha" ] \
    || die "tooling export at $dir does not match $sha"
  printf '%s %s\n' "$sha" "$dir"
}

prune_tooling() { # remove exports that no unretired forge run references
  local keep d
  keep="$(_each_ledger 'select(.retired_at == null) | .tooling.dir // empty')" || return 1
  for d in "$FORGE_STATE"/tooling/*/; do
    d="${d%/}"
    [ -d "$d" ] || continue
    printf '%s\n' "$keep" | grep -Fxq -- "$d" && continue
    chmod -R u+w "$d" && rm -rf "$d" && log "pruned tooling export $d"
  done
}

# --- run settings ------------------------------------------------------------
# Passed to the run session with --settings. The environment makes the pinned
# scripts act on the real workspace and runs directory (they would otherwise
# derive both from the export's location); the hook is the pinned copy.
write_run_settings() { # rid tooling_dir -> path of the settings file
  local rid="$1" tdir="$2" hook f
  hook="$tdir/tools/forge/hooks/mcp-read-only.sh"
  [ -f "$hook" ] || die "pinned tooling has no MCP hook: $hook"
  case "$hook" in *'"'*|*"'"*|*'$'*|*'`'*) die "unsafe characters in the tooling path: $hook" ;; esac
  f="$RUNS_DIR/$rid/settings.json"
  jq -n --arg hook "$hook" --arg rid "$rid" --arg ws "$WORKSPACE_ROOT" --arg runs "$RUNS_DIR" '{
    env: {FORGE_RUN_ID: $rid, AUTOFIX_WORKSPACE_ROOT: $ws, AUTOFIX_RUNS_DIR: $runs},
    hooks: {PreToolUse: [{matcher: "mcp__.*",
      hooks: [{type: "command", command: ("bash \"" + $hook + "\"")}]}]}
  }' > "$f"
  printf '%s\n' "$f"
}
