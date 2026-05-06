#!/usr/bin/env bash
#
# transplant-prs-batch.sh — fan transplant-pr.sh out across every open PR
# from a JSON snapshot produced by transplant-prs-report.sh. Cutover-day
# orchestration script.
#
# Why a separate script vs a `for` loop in the report's output: the batch
# step has its own progress, partial-failure, and parallelism concerns that
# don't belong in either the report (read-only) or the per-PR transplant
# (which is intentionally focused on one PR's correctness, not on dozens
# running concurrently).
#
# Parallelism note: the filter step in transplant-pr.sh is CPU-bound and
# parallelizable. The git push + gh pr create step needs serialization
# only insofar as both target the same origin remote — concurrent gh pr
# creates against newspack-workspace are fine in practice (GitHub
# tolerates ~10 concurrent writes from one user without issues). Default
# concurrency is 4; bump higher with --concurrency.
#
# On failure of one PR: the rest continue. A summary at the end lists what
# succeeded, what conflicted (parked on pr-import/conflicts/...), and what
# errored (script bug, network issue, GH 5xx).
#
# Usage:
#   bin/migration/transplant-prs-batch.sh [options] [--input <json>]
#
# Options:
#   --input <file>            JSON snapshot from transplant-prs-report.sh.
#                             If omitted, runs the report inline (excluding
#                             bots, including drafts).
#   --concurrency N           Max parallel transplants (default 4).
#   --dry-run                 Pass --dry-run through to each transplant-pr.sh.
#   --close-source            Pass --close-source through. ONLY use this on
#                             cutover day — closes legacy PRs in the source
#                             repos.
#   --target-base <ref>       Pass --target-base through (default origin/trunk).
#   --scratch <dir>           Shared scratch dir (default /tmp/transplant-pr).
#   --filter <jq-expr>        Extra jq filter applied to the input. Useful
#                             for sub-batches: --filter '.[] | select(.repo == "newspack-plugin")'

# -e and pipefail; not -u because the per-flag arrays in the dispatcher loop
# are intentionally empty when the corresponding flag wasn't passed, and
# expanding [*] of an empty array under set -u is fatal.
set -eo pipefail

INPUT=""
CONCURRENCY=4
PASSTHROUGH_FLAGS=""
EXTRA_FILTER='.'

# Build a single space-separated string of passthrough flags. Word-splitting
# is intentional when this string is later expanded inside the xargs body.
while [ $# -gt 0 ]; do
  case "$1" in
    --input)         INPUT="$2"; shift 2 ;;
    --concurrency)   CONCURRENCY="$2"; shift 2 ;;
    --dry-run)       PASSTHROUGH_FLAGS="$PASSTHROUGH_FLAGS --dry-run"; shift ;;
    --close-source)  PASSTHROUGH_FLAGS="$PASSTHROUGH_FLAGS --close-source"; shift ;;
    --target-base)   PASSTHROUGH_FLAGS="$PASSTHROUGH_FLAGS --target-base $2"; shift 2 ;;
    --scratch)       PASSTHROUGH_FLAGS="$PASSTHROUGH_FLAGS --scratch $2"; shift 2 ;;
    --filter)        EXTRA_FILTER="$2"; shift 2 ;;
    -h|--help)       sed -n '2,/^# Usage/p;/^# Options/,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'; exit 2 ;;
    *) echo "ERROR: unknown arg $1" >&2; exit 2 ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Generate the input snapshot inline if none was given. Default policy
# matches the cutover plan: humans only, drafts included, JSON format.
# We only register $INPUT for trap-cleanup if WE created it. A user-supplied
# --input file must survive the run untouched.
INPUT_IS_TEMP=0
if [ -z "$INPUT" ]; then
  INPUT=$(mktemp -t transplant-prs-batch-input-XXXXXX.json)
  INPUT_IS_TEMP=1
  echo "==> No --input given; generating snapshot via transplant-prs-report.sh" >&2
  "$SCRIPT_DIR/transplant-prs-report.sh" --format json --output "$INPUT"
fi

# Render one "<repo> <number>" line per PR after applying the extra filter.
WORKLIST=$(mktemp -t transplant-prs-batch-list-XXXXXX)
cleanup_temps() {
  rm -f "$WORKLIST"
  [ "$INPUT_IS_TEMP" = "1" ] && rm -f "$INPUT"
}
trap cleanup_temps EXIT
jq -r "$EXTRA_FILTER"' | .[] | "\(.repo) \(.number)"' "$INPUT" > "$WORKLIST"
TOTAL=$(wc -l < "$WORKLIST" | tr -d ' ')
echo "==> Transplanting $TOTAL PRs at concurrency=$CONCURRENCY" >&2

# Per-PR results land in this dir. We aggregate at the end.
RESULTS_DIR=$(mktemp -d -t transplant-prs-batch-results-XXXXXX)
echo "==> Logs and per-PR exit codes: $RESULTS_DIR" >&2

# xargs -P drives the parallelism. Each spawned shell calls transplant-pr.sh
# with the passthrough flags and writes its log + exit code to RESULTS_DIR.
# We can't trap-cleanup RESULTS_DIR because the caller may want to inspect
# it after the run.
# Pass shared state (results dir, transplant script path, passthrough flags)
# through the environment so the bash -c body stays readable. The body
# intentionally word-splits $PASSTHROUGH_FLAGS to expand it as separate args.
export RESULTS_DIR SCRIPT_DIR PASSTHROUGH_FLAGS
cat "$WORKLIST" | xargs -n2 -P"$CONCURRENCY" \
  bash -c '
    repo="$1"; pr="$2"
    log="$RESULTS_DIR/$repo-$pr.log"
    # shellcheck disable=SC2086 # word-splitting is intentional.
    if "$SCRIPT_DIR/transplant-pr.sh" "$repo" "$pr" $PASSTHROUGH_FLAGS \
         > "$log" 2>&1; then
      echo "    OK   $repo#$pr"
      echo 0 > "$RESULTS_DIR/$repo-$pr.rc"
    else
      rc=$?
      echo "    FAIL $repo#$pr (rc=$rc; see $log)"
      echo "$rc" > "$RESULTS_DIR/$repo-$pr.rc"
    fi
  ' _ \
  || true

# ---------------------------------------------------------------------------
# Summary. Exit codes from transplant-pr.sh have specific meanings — match
# the script source for the canonical list:
#   0   transplanted cleanly
#   2   usage / argument error
#   3   no trunk-like ref in legacy repo (shouldnt happen in practice)
#   4   commit-map lookup failure
#   5   PR is empty after filtering
#   6   target base not found in workspace clone
#   7   rebase conflict — parked on pr-import/conflicts/<repo>-<N>
#   8   newspack-plugin extracted-packages conflict — manual merge needed
#   9   noop-after-filter — every commit only touched legacy-only paths
# Anything else: unexpected failure, look at the log.
# ---------------------------------------------------------------------------
ok=0; conflict=0; extracted=0; empty=0; noop=0; other=0
for rc_file in "$RESULTS_DIR"/*.rc; do
  [ -f "$rc_file" ] || continue
  rc=$(cat "$rc_file")
  case "$rc" in
    0) ok=$((ok+1)) ;;
    5) empty=$((empty+1)) ;;
    7) conflict=$((conflict+1)) ;;
    8) extracted=$((extracted+1)) ;;
    9) noop=$((noop+1)) ;;
    *) other=$((other+1)) ;;
  esac
done

echo
echo "==> Batch summary"
echo "    OK                 : $ok"
echo "    Conflicts          : $conflict (parked on pr-import/conflicts/...)"
echo "    Extracted-pkg      : $extracted (newspack-plugin manual merges)"
echo "    Noop-after-filter  : $noop (legacy-only paths; needs manual port)"
echo "    Empty-after-filter : $empty"
echo "    Other failures     : $other"
echo "    Total processed    : $((ok + conflict + extracted + empty + noop + other)) / $TOTAL"
echo
echo "    Per-PR logs in: $RESULTS_DIR"

# Exit non-zero if any PR didn't transplant cleanly so callers (CI, Slack
# webhooks) can fan that out as a notification.
[ "$conflict" = "0" ] && [ "$extracted" = "0" ] && [ "$other" = "0" ]
