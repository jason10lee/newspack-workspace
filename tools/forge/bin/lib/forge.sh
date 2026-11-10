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
# derive both from the export's location); the hooks are the pinned copies.
write_run_settings() { # rid tooling_dir -> path of the settings file
  local rid="$1" tdir="$2" hook guard f
  hook="$tdir/tools/forge/hooks/mcp-read-only.sh"
  guard="$tdir/tools/forge/hooks/test-suite-guard.sh"
  [ -f "$hook" ] || die "pinned tooling has no MCP hook: $hook"
  # A run pinned before the guard existed keeps the settings it launched with,
  # so its rework still starts; every pin taken since carries the guard.
  if [ ! -f "$guard" ]; then
    printf 'forge: pinned tooling predates the suite guard, so this run goes without it: %s\n' "$tdir" >&2
    guard=""
  fi
  case "$tdir" in *'"'*|*"'"*|*'$'*|*'`'*) die "unsafe characters in the tooling path: $tdir" ;; esac
  f="$RUNS_DIR/$rid/settings.json"
  jq -n --arg hook "$hook" --arg guard "$guard" --arg rid "$rid" --arg ws "$WORKSPACE_ROOT" --arg runs "$RUNS_DIR" \
    --arg state "$FORGE_STATE" --arg wait "$FORGE_LAUNCH_WAIT" --arg self "$f" '{
    env: {FORGE_RUN_ID: $rid, AUTOFIX_WORKSPACE_ROOT: $ws, AUTOFIX_RUNS_DIR: $runs,
          FORGE_STATE: $state, FORGE_LAUNCH_WAIT: $wait, REPRISE_SETTINGS: $self},
    hooks: {PreToolUse: ([
      {matcher: "mcp__.*", hooks: [{type: "command", command: ("bash \"" + $hook + "\"")}]}]
      + (if $guard == "" then [] else
      [{matcher: "Bash", hooks: [{type: "command", command: ("bash \"" + $guard + "\"")}]}] end))}
  }' > "$f"
  printf '%s\n' "$f"
}

# --- sessions ----------------------------------------------------------------
# Runs are background sessions: `claude stop` works only on those, and their
# rows in `claude agents --json --all` carry a pid while the process lives.
# Plugins installed for the workspace path do not load in a pool dir, so the
# launcher passes them as plugin dirs.
: "${FORGE_PLUGINS:=newspack@newspack-devkit team-os@newspack-team-os linear@claude-plugins-official codex@openai-codex}"
: "${FORGE_PLUGINS_JSON:=$HOME/.claude/plugins/installed_plugins.json}"
: "${FORGE_LAUNCH_TIMEOUT:=90}"
# A run's Stage 0 waits longer than the dispatcher does, so a session that
# starts before the dispatcher has seen it does not give up on a good launch.
: "${FORGE_LAUNCH_MARGIN:=60}"
: "${FORGE_LAUNCH_WAIT:=$((FORGE_LAUNCH_TIMEOUT + FORGE_LAUNCH_MARGIN))}"
: "${FORGE_POLL_INTERVAL:=5}"

plugin_dirs() { # one install path per configured plugin installed for the workspace
  local k p
  for k in $FORGE_PLUGINS; do
    p="$(jq -r --arg k "$k" --arg w "$WORKSPACE_ROOT" \
      '[.plugins[$k][]? | select(.projectPath == $w) | .installPath][0] // empty' \
      "$FORGE_PLUGINS_JSON" 2>/dev/null)"
    if [ -n "$p" ] && [ -d "$p" ]; then printf '%s\n' "$p"
    else log "plugin $k is not installed for $WORKSPACE_ROOT; the run starts without it"; fi
  done
}

launch_session() { # rid dir settings prompt -> session id; 3 untrusted, 1 other failure
  local rid="$1" dir="$2" settings="$3" prompt="$4" out id p scrub=() v
  # --add-dir: a run cds into its worktree to load impact-review, whose setup
  # lines run in the shell's directory, and a cd outside the session's own
  # directories is reset. Monorepo worktrees live under <workspace>/worktrees.
  set -- --bg -n "$rid" --permission-mode auto --settings "$settings"
  [ ! -d "$WORKSPACE_ROOT/worktrees" ] || set -- "$@" --add-dir "$WORKSPACE_ROOT/worktrees"
  while IFS= read -r p; do [ -n "$p" ] && set -- "$@" --plugin-dir "$p"; done <<EOP
$(plugin_dirs)
EOP
  # A run takes its environment from its settings file, never from the
  # dispatcher's shell: a dispatcher's AUTOFIX_LINEAR_MOCK_DIR would point the
  # run at mocks, and its CLAUDE_CODE_SESSION_ID names another session.
  for v in $(compgen -e); do
    case "$v" in AUTOFIX_*|FORGE_*|CLAUDE_CODE_SESSION_ID) scrub+=(-u "$v") ;; esac
  done
  local rc=0
  out="$(cd "$dir" && env ${scrub[@]+"${scrub[@]}"} claude "$@" "$prompt" 2>&1)" || rc=$?
  # --bg refuses an untrusted directory. Some versions exit 0 when they do and
  # others do not, so the message decides, before the exit status.
  case "$out" in *"Workspace not trusted"*) return 3 ;; esac
  [ "$rc" -eq 0 ] || { log "claude --bg failed: $out"; return 1; }
  id="$(printf '%s\n' "$out" | LC_ALL=C sed -n 's/^backgrounded · \([0-9a-f]\{8\}\) · .*/\1/p' | head -1)"
  [ -n "$id" ] || { log "no session id in: $out"; return 1; }
  printf '%s\n' "$id"
}

# session_state <id> — alive, waiting, gone or unknown. A session waiting on
# a question shows state "blocked", at first with its process and later
# without one, and `claude attach` resumes it either way, so it is waiting for
# the operator: neither alive (the stall rule would stop it) nor dead. Any other
# row with a pid is alive. Only "done" and "stopped" without a pid are gone. A failing
# `claude agents`, output that is not a JSON array, a missing row and a state
# this does not know are all unknown: none of them shows the session ended, and
# reading them as gone would bail a healthy run and restore its claim.
session_state() {
  local out
  out="$(claude agents --json --all 2>/dev/null)" || { echo unknown; return 0; }
  printf '%s' "$out" | jq -r --arg i "$1" '
    if type != "array" then "unknown"
    else [.[] | select(.id == $i)] | if length == 0 then "unknown"
      elif .[0].state == "blocked" then "waiting"
      elif (.[0] | has("pid")) then "alive"
      elif .[0].state == "done" or .[0].state == "stopped" then "gone"
      else "unknown" end
    end' 2>/dev/null || echo unknown
}
session_alive() { [ "$(session_state "$1")" = alive ]; }
# A launch is confirmed by a running session or one already waiting on a prompt:
# both started, and abandoning the second would stop a healthy run.
session_started() { case "$(session_state "$1")" in alive|waiting) return 0 ;; *) return 1 ;; esac; }

# session_seen_state <id> — the state field of the session's row, or a note
# saying why there is none, for messages that name what was seen.
session_seen_state() {
  local out
  out="$(claude agents --json --all 2>/dev/null)" || { echo "(claude agents failed)"; return 0; }
  printf '%s' "$out" | jq -r --arg i "$1" '
    if type != "array" then "(unreadable output)"
    else [.[] | select(.id == $i)] | if length == 0 then "(no row)"
      else (.[0].state // "(no state)") + (if .[0] | has("pid") then " with a process" else " without a process" end) end
    end' 2>/dev/null || echo "(unreadable output)"
}

# confirm_launch — the run's Stage 0 claims only after this writes "confirmed",
# and waits longer than FORGE_LAUNCH_TIMEOUT for it, so a session that appears
# late finds "abandoned" and exits without claiming.
confirm_launch() { # rid id -> 0 confirmed, 1 abandoned
  local rid="$1" id="$2" cur deadline
  cur="$(bash "$FORGE_LEDGER" get "$rid" '.launch // ""')"
  [ -z "$cur" ] || die "launch of $rid is already '$cur'"
  deadline=$(( $(date +%s) + FORGE_LAUNCH_TIMEOUT ))
  while :; do
    if session_started "$id"; then
      bash "$FORGE_LEDGER" set "$rid" '.launch = "confirmed"'
      bash "$FORGE_LEDGER" history "$rid" launch confirmed "session $id"
      return 0
    fi
    [ "$(date +%s)" -lt "$deadline" ] || break
    sleep "$FORGE_POLL_INTERVAL"
  done
  claude stop "$id" >/dev/null 2>&1 || true
  bash "$FORGE_LEDGER" set "$rid" '.launch = "abandoned" | .terminal = "bailed-launch"'
  bash "$FORGE_LEDGER" history "$rid" launch abandoned "session $id not seen within ${FORGE_LAUNCH_TIMEOUT}s"
  return 1
}

# --- liveness ----------------------------------------------------------------
: "${FORGE_STAGE_BOUND:=7200}"    # autofix's two-hour wall-clock bound
# A stale reading only marks a run; a check this many seconds later stops it if
# the run has still recorded nothing. Wall-clock age includes the machine's sleep.
: "${FORGE_STALE_RECHECK:=2700}"
: "${FORGE_STOP_TIMEOUT:=180}"
# How long past FORGE_LAUNCH_TIMEOUT a launch may sit unconfirmed before
# `forge check` decides the dispatcher died during it.
: "${FORGE_STALE_MARGIN:=600}"

# later_run <rid> <issue> <since> — the id of another run, in any mode, on the
# same issue that is still open (as issue_busy reads it), or that reached its
# Linear write at or after <since>. Its claim, live or ended, carries the same
# assignee and state as this run's, so the restore cannot tell them apart.
# Reaching the write shows as a "claimed" event, or as the
# linear_prior.claimed_state_id that claim.sh records just before its claim
# write: a run that lost its own race has no "claimed" event but may have
# written. A run that marked "claiming" and stopped short (claim.sh's
# same-issue guard, a failed read) never touched the issue, and neither did a
# run retired before this one launched. A rework starts linear_prior over, but
# claim.sh keeps issue_written once any launch has written, so a reworked run
# that once wrote still counts once a later launch of it records any intake
# event, even one that stops short; that errs toward leaving the claim alone.
later_run() {
  local lf
  for lf in "$RUNS_DIR"/*/ledger.json; do
    [ -f "$lf" ] || continue
    jq -r --arg r "$1" --arg i "$(printf '%s' "$2" | tr '[:lower:]' '[:upper:]')" --arg t "$3" \
      '(.issue_written == true or .linear_prior.claimed_state_id != null) as $wrote
      | select(.run_id != $r and (.issue | ascii_upcase) == $i
        and ((.terminal == null and .retired_at == null)
          or any(.stage_history[]; .stage == "intake" and .at >= $t
            and (.outcome == "claimed" or $wrote)))) | .run_id' \
      "$lf" 2>/dev/null || true
  done | head -1
}
