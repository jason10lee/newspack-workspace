#!/bin/bash
set -euo pipefail
BIN="$(dirname "${BASH_SOURCE[0]}")"
. "$BIN/lib/common.sh"
. "$BIN/lib/profile.sh"
require jq
require git
LEDGER="$BIN/ledger.sh"

cmd="${1:?usage: env.sh create <run_id> <repo> [-- <setup flags>] | destroy <run_id> [--waive-push-check]}"
run_id="${2:?run id}"; shift 2

hex4() { printf '%s' "$run_id" | awk -F- '{print $NF}'; }

# branch_on_remote <wt> <branch> — is the branch tip safe on GitHub? The branch
# ref is the usual answer, but a squash merge deletes it, which made every merged
# run read as "never pushed" and blocked its cleanup. GitHub keeps
# refs/pull/<n>/head after the branch goes, so a tip contained in the run's PR
# head is preserved too. A tip with commits the PR never saw still fails.
branch_on_remote() { # wt branch
  local wt="$1" branch="$2" num tip
  [ -n "$(git -C "$wt" ls-remote origin "refs/heads/$branch" 2>/dev/null)" ] && return 0
  num="$("$LEDGER" get "$run_id" '.pr.number // empty')"
  [ -n "$num" ] || return 1
  tip="$(git -C "$wt" rev-parse -q --verify HEAD)" || return 1
  git -C "$wt" fetch -q origin "refs/pull/$num/head" 2>/dev/null || return 1
  git -C "$wt" merge-base --is-ancestor "$tip" FETCH_HEAD
}

case "$cmd" in
  create)
    repo="${1:?repo}"; shift
    [ "${1:-}" = "--" ] && shift
    issue="$("$LEDGER" get "$run_id" .issue | tr '[:upper:]' '[:lower:]')"
    stem="$(ledger_decision "$run_id" branch_stem)"
    [ -n "$stem" ] || die "no branch_stem decision in ledger (Stage 1 must set it from Linear branchName)"
    [ "$(profile_get "$run_id" '.env')" = true ] \
      || die "profile $(profile_name "$run_id") has no env — env.sh create does not apply"
    attempts="$("$LEDGER" get "$run_id" '.attempts.provisioning')"
    if [ "$attempts" -ge "$AUTOFIX_MAX_ATTEMPTS" ]; then
      "$LEDGER" set "$run_id" '.terminal = "escalated"'
      die "provisioning attempts exhausted ($attempts) — escalating"
    fi
    "$LEDGER" set "$run_id" '.attempts.provisioning += 1'
    name="autofix-$issue-$(hex4)"
    branch="$stem-$(hex4)"
    # Base-ref discipline: cut the run branch from freshly fetched upstream
    # origin/main, never this machine's local trunk `main` (a local
    # fork-trunk aggregate — see the pr.sh PR-scope guard for the incident
    # this closes, PR #723). `n worktree add` (invoked inside `n env create
    # --worktree`) reuses an existing local branch as-is if one already
    # exists (falls back to branching from local HEAD only when it doesn't),
    # so pre-creating the branch here from origin/main is sufficient to steer
    # it — no change needed to `n` tooling itself.
    # The base now comes from the run's profile; the default profile is
    # origin/main, fetched as before.
    base="$(profile_base "$run_id" "$WORKSPACE_ROOT")"
    git -C "$WORKSPACE_ROOT" rev-parse -q --verify "refs/heads/$branch" >/dev/null 2>&1 \
      || git -C "$WORKSPACE_ROOT" branch "$branch" "$base"
    n env create "$name" --worktree "$repo:$branch" || die "n env create failed (attempt $((attempts+1)))"
    [ -d "$(wt_dir "$branch")" ] || log "warning: expected worktree dir not found: $(wt_dir "$branch")"
    # Record the env as soon as it exists, so a failure in any later step
    # leaves an env that `destroy` can find.
    "$LEDGER" set "$run_id" '.env = {name:$n, worktrees:[$w]} | .branch = $b | .worktree = $p' \
      --arg n "$name" --arg w "$repo:$branch" --arg b "$branch" --arg p "$(wt_dir "$branch")"
    # Start the env with the run's unit built from the branch. The build runs in
    # the container without installing, so the shared root install is never
    # rewritten, and a branch that needs a dependency the root lacks fails here.
    up_log="$(mktemp)"
    if ! n env up "$name" --build-worktrees 2>&1 | tee "$up_log"; then
      # A cause env up can name won't change on a retry, and its remedy (an
      # install, or updating the root) rewrites the root checkout every env and
      # the host share. Escalate so the operator decides; never act on it here.
      # bin/env.sh tags a named cause "(cause: …)". Match the tag and say the
      # cause in our own words: the hint around the tag ends in its remedy,
      # which is the very thing a run must not do.
      cause=""
      case "$(grep -Eo '\(cause: (missing-module|root-behind|branch-export)\)' "$up_log" | head -1 || true)" in
        *missing-module*) cause="the branch imports a module the root has not installed" ;;
        *root-behind*) cause="the root checkout is behind the branch (a workspace package lacks an export it imports)" ;;
        *branch-export*) cause="the branch's own workspace packages lack an export a unit imports" ;;
      esac
      rm -f "$up_log"
      if [ -n "$cause" ]; then
        "$LEDGER" set "$run_id" '.terminal = "escalated"'
        "$LEDGER" history "$run_id" reproduce env-build-blocked "$cause"
        die "the build can't pass on a retry: $cause. Escalating: do not install packages, update the root checkout or rebase; report the cause to the operator"
      fi
      die "n env up --build-worktrees failed (attempt $((attempts+1)))"
    fi
    rm -f "$up_log"
    # n setup activates a theme run's theme, which fatals the site without a
    # stylesheet. The classic theme builds its style.css; the block theme
    # tracks its own.
    theme="$(wt_dir "$branch")/themes/$repo"
    if [ -d "$theme" ] && [ ! -f "$theme/style.css" ] && [ ! -f "$theme/$repo/style.css" ]; then
      die "theme $repo is not built (no style.css) (attempt $((attempts+1)))"
    fi
    n setup --env "$name" --yes "$@" || die "n setup failed (attempt $((attempts+1)))"
    # Env up gives the worktree's unit a runtime-only vendor/, so the suite's
    # PHPUnit run would stop on a missing bootstrap. Install the dev
    # dependencies, judged by the same check `n test-php` refuses on, from the
    # unit's own directory because `n composer` resolves its project from the
    # cwd. This runs after the env is recorded, so a failed install leaves an
    # env that `destroy` can still find.
    for unit in "$(wt_dir "$branch")/plugins/$repo" "$(wt_dir "$branch")/themes/$repo"; do
      if [ -f "$unit/composer.json" ] && ! (. "$WORKSPACE_ROOT/bin/_common.sh" && project_has_dev_deps "$unit"); then
        (cd "$unit" && n composer install) || die "composer install failed in $unit (attempt $((attempts+1)))"
      fi
    done
    "$LEDGER" history "$run_id" reproduce env-created "$name"
    echo "$name" ;;
  destroy)
    waive="${1:-}"
    name="$("$LEDGER" get "$run_id" '.env.name // empty')"
    [ -n "$name" ] || { log "no env recorded for $run_id"; exit 0; }
    branch="$("$LEDGER" get "$run_id" '.branch // empty')"
    wt="$(run_wt "$run_id")"
    if [ -n "$branch" ]; then
      if [ -d "$wt" ]; then
        if ! git -C "$wt" tag -a "autofix-anchor-$run_id" -m "pre-destroy anchor for $run_id" 2>/dev/null; then
          # A retried destroy is fine if the anchor already exists; anything else fails closed.
          git -C "$wt" rev-parse -q --verify "refs/tags/autofix-anchor-$run_id" >/dev/null 2>&1 \
            || die "failed to create anchor tag autofix-anchor-$run_id in $wt"
        fi
        if [ "$waive" != "--waive-push-check" ] && ! branch_on_remote "$wt" "$branch"; then
          die "branch $branch not pushed (no remote branch, and its tip is not in the run's PR head); push it or pass --waive-push-check (n env destroy deletes the branch)"
        fi
      elif [ "$waive" != "--waive-push-check" ]; then
        die "worktree $wt missing for recorded branch $branch; verify the branch is pushed to origin, then re-run with --waive-push-check (n env destroy deletes the bound branch)"
      else
        log "worktree $wt missing; proceeding without anchor tag (--waive-push-check)"
      fi
    fi
    n env destroy "$name"
    # Clear the env record so future sweeps don't retry a destroy that already
    # happened (run autofix-nppm-305: a stale .env made every sweep re-attempt
    # the destroy and error on the missing worktree).
    "$LEDGER" set "$run_id" '.env = null'
    "$LEDGER" history "$run_id" cleanup env-destroyed "$name" ;;
  *) die "unknown subcommand: $cmd" ;;
esac
