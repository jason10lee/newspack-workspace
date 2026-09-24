#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ./helpers.sh
A=../bin/autofix; L=../bin/ledger.sh

# Each stat probe is validated rather than chained on exit status: GNU stat
# reads `-f` as --file-system and prints a block while exiting non-zero.
perms() {
  local p
  if p="$(stat -c '%a' "$1" 2>/dev/null)" && [[ "$p" =~ ^[0-7]{3,4}$ ]]; then echo "$p"; return; fi
  if p="$(stat -f '%Lp' "$1" 2>/dev/null)" && [[ "$p" =~ ^[0-7]{3,4}$ ]]; then echo "$p"; return; fi
  echo no-mode
}

# --- where run state lives --------------------------------------------------
# Resolve RUNS_DIR the way the scripts do, in a clean environment.
# Arguments: HOME, then optional XDG_STATE_HOME, AUTOFIX_RUNS_DIR, AUTOFIX_ROOT.
runs_dir() {
  local vars=(HOME="$1" PATH="$PATH")
  [ -n "${2:-}" ] && vars+=(XDG_STATE_HOME="$2")
  [ -n "${3:-}" ] && vars+=(AUTOFIX_RUNS_DIR="$3")
  [ -n "${4:-}" ] && vars+=(AUTOFIX_ROOT="$4")
  env -i "${vars[@]}" bash -c '. ../bin/lib/common.sh; printf "%s" "$RUNS_DIR"'
}
H="$(mktemp -d)"; X="$(mktemp -d)"; O="$(mktemp -d)"; R="$(mktemp -d)"
assert_eq "$H/.local/state/newspack/autofix/runs" "$(runs_dir "$H")" \
  "run state defaults to ~/.local/state, outside every repository"
assert_eq "$X/newspack/autofix/runs" "$(runs_dir "$H" "$X")" \
  "XDG_STATE_HOME moves run state"
assert_eq "$O" "$(runs_dir "$H" "$X" "$O")" \
  "AUTOFIX_RUNS_DIR overrides the location"
assert_eq "$R/runs" "$(runs_dir "$H" "" "" "$R")" \
  "a given AUTOFIX_ROOT keeps run state under it"

# --- run directories are private --------------------------------------------
export AUTOFIX_RUNS_DIR; AUTOFIX_RUNS_DIR="$(mktemp -d)/state/runs"
bash "$L" init plainrun NPPM-1 operator-named >/dev/null
bash "$L" init secrun NPPM-2 secure >/dev/null
assert_eq 700 "$(perms "$AUTOFIX_RUNS_DIR")" "the runs directory is created private"
assert_eq 700 "$(perms "$AUTOFIX_RUNS_DIR/secrun")" "a run directory is created private"

# --- report -----------------------------------------------------------------
p="$(bash "$A" report plainrun)"
assert_eq "$AUTOFIX_RUNS_DIR/plainrun/report.md" "$p" "report prints the path beside the ledger"
assert_eq no "$([ -e "$p" ] && echo yes || echo no)" "report without --init creates nothing"
p="$(bash "$A" report plainrun --init)"
assert_eq yes "$([ -f "$p" ] && echo yes || echo no)" "--init creates the report"
assert_eq "" "$(head -1 "$p")" "an ordinary run's report carries no frontmatter"

s="$(bash "$A" report secrun --init)"
assert_eq "---" "$(sed -n 1p "$s")" "a secure run's report opens with frontmatter"
assert_eq "internal: true" "$(sed -n 2p "$s")" "a secure run's report is marked internal"
printf 'kept\n' >> "$s"
bash "$A" report secrun --init >/dev/null
assert_contains "$(cat "$s")" kept "--init never overwrites an existing report"

bash "$A" report nosuchrun >/dev/null 2>&1; assert_eq 1 "$?" "report refuses an unknown run"
bash "$A" report ../plainrun >/dev/null 2>&1; assert_eq 1 "$?" "report refuses a run id holding a path"
# Stage 7 usually writes the whole file; the next call restores the stamp.
printf '# Report\nbody\n' > "$s"
bash "$A" report secrun >/dev/null 2>&1
assert_eq "internal: true" "$(sed -n 2p "$s")" "a stamp dropped by a whole-file write is restored"
assert_contains "$(cat "$s")" "# Report" "restoring the stamp keeps the report"
printf -- '---\ntitle: x\n---\nbody\n' > "$s"
bash "$A" report secrun >/dev/null 2>&1
assert_eq "$(printf -- '---\ninternal: true\ntitle: x\n---\nbody')" "$(cat "$s")" "the stamp joins existing frontmatter"
printf '# Plain\n' > "$p"
bash "$A" report plainrun >/dev/null 2>&1
assert_eq "# Plain" "$(cat "$p")" "an ordinary run's report is left alone"

bash "$A" report plainrun --bogus >/dev/null 2>&1; assert_eq 1 "$?" "report refuses an unknown flag"
bash "$A" report plainrun --init extra >/dev/null 2>&1; assert_eq 1 "$?" "report refuses a stray argument"

# is_secure fails closed, so a run whose ledger cannot be read is stamped.
mkdir -p "$AUTOFIX_RUNS_DIR/corruptrun"; printf 'not json' > "$AUTOFIX_RUNS_DIR/corruptrun/ledger.json"
c="$(bash "$A" report corruptrun --init)"
assert_eq "internal: true" "$(sed -n 2p "$c")" "a run with an unreadable ledger is stamped internal"

# --- runs -------------------------------------------------------------------
out="$(bash "$A" runs)"
assert_contains "$out" "$(printf 'plainrun\tNPPM-1\t-\tintake')" "runs lists an ordinary run"
assert_contains "$out" "$(printf 'secrun\tNPPM-2\tsecure\tintake')" "runs marks a secure run"
case "$out" in *corruptrun*) assert_eq absent present "runs skips an unreadable ledger" ;;
  *) assert_eq absent absent "runs skips an unreadable ledger" ;; esac

# A preview for a run with no ledger still lands in a private directory.
( . ../bin/lib/common.sh; f="$(mktemp)"; echo body > "$f"; secure_gate noledger claim "$f"; rm -f "$f" ) >/dev/null 2>&1
assert_eq 700 "$(perms "$AUTOFIX_RUNS_DIR/noledger")" "a preview-only run directory is created private"

# A run directory that predates the umask is tightened when a ledger is written.
mkdir -p "$AUTOFIX_RUNS_DIR/oldrun"; chmod 755 "$AUTOFIX_RUNS_DIR/oldrun"
bash "$L" init oldrun NPPM-3 operator-named >/dev/null
assert_eq 700 "$(perms "$AUTOFIX_RUNS_DIR/oldrun")" "an existing run directory is tightened"

# Runs left at the old in-checkout location are named by the sweep.
LR="$(mktemp -d)"; mkdir -p "$LR/runs/legacy"; echo '{}' > "$LR/runs/legacy/ledger.json"
out="$(AUTOFIX_ROOT="$LR" bash "$A" cleanup 2>&1)"
assert_contains "$out" "are not swept" "the sweep names runs left at the old location"
finish
