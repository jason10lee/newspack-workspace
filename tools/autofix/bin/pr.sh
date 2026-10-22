#!/bin/bash
set -euo pipefail
BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$BIN/lib/common.sh"
. "$BIN/lib/profile.sh"
require jq
require git
require gh
LEDGER="$BIN/ledger.sh"

cmd="${1:-}"
case "$cmd" in
  create|comment|prepare) ;;
  *) die "usage: pr.sh create <run_id> --title <t> --body-file <f> [--confirmed=<digest>] [--no-copilot]
       pr.sh comment <run_id> --body-file <f> [--confirmed=<digest>]
       pr.sh prepare <run_id> --body-file <f> [--summary-file <f>] [--closeout-file <f>]" ;;
esac
run_id="${2:?}"; shift 2

# A forge run publishes only through the delivery batch the operator approves:
# `prepare` checks it, then raw git/gh commands that publish-guard sees carry it
# out. publish-guard does not match a bare pr.sh call, so without this refusal
# a forge run could push and open a PR with no prompt. is_forge fails closed.
case "$cmd" in create|comment)
  if is_forge "$run_id"; then
    die "pr.sh $cmd refuses forge runs (and unreadable ledgers) — forge runs publish through pr.sh prepare and the delivery batch"
  fi
esac

# check_summary_body <file> — the self-review summary as the team posts it.
check_summary_body() { # file
  [ -s "$1" ] || die "comment body is empty: $1"
  bash "$BIN/redact.sh" scan "$1" || die "redaction findings in comment body — fix and retry"
  # pr-ready and self-review read the pass from this marker on the PR. A body
  # edited for disclosure hygiene can lose it and still post cleanly.
  grep -Eq '<!-- newspack-self-review passed=[0-9a-f]{40} rounds=[0-9]+ -->' "$1" \
    || die "comment body has no newspack-self-review marker line — keep the one self-review wrote"
  # Unattended, nobody reads the body before it posts, and a declined or deferred
  # finding can describe a weakness the run left unfixed. A secure run's operator
  # previews the body, so a section they chose to keep is theirs to keep.
  if ! is_secure "$run_id" && grep -Eq '^Declined:|<summary>Suggestions deferred' "$1"; then
    die "comment body still has a section Stage 6 cuts (Declined or Suggestions deferred) — move it to the Linear closeout"
  fi
}

# scope_guard <wt> — print the base ref after checking that the branch, measured
# against the profile's base, touches only the profile's path scope and is not
# suspiciously long. Real incident: an autofix run branched from this machine's
# local fork-trunk `main` (a 153-commit local tooling aggregate) and this
# script pushed the WHOLE delta upstream as PR #723 (closed within minutes).
# Fail closed throughout: never guess at the base ref. The git calls run from
# inside the worktree, not with -C, as create always ran them.
scope_guard() { # wt
  local base affected offending commit_count
  base="$(profile_base "$run_id" "$1")" || exit 1
  affected="$(ledger_decision "$run_id" affected_repo)"
  # A unit name only: it becomes part of the path scope below. Checked with an
  # explicit ASCII class under LC_ALL=C, since bash 3.2 case ranges admit
  # other letters under a UTF-8 locale.
  if [ -n "$affected" ] && { [ "$affected" = . ] || [ "$affected" = .. ] \
      || ! printf '%s' "$affected" | LC_ALL=C grep -Eq '^[A-Za-z0-9._-]+$'; }; then
    die "affected_repo '$affected' is not a plain unit name — refusing (fork-trunk leak guard)"
  fi
  if profile_get "$run_id" '.path_scope[]' | grep -q '{affected_repo}'; then
    [ -n "$affected" ] || die "no affected_repo decision in ledger for $run_id"
  fi
  offending="$( (cd "$1" && git diff --name-only "$base...HEAD") | profile_offending_paths "$run_id" "$affected")" || exit 1
  if [ -n "$offending" ]; then
    die "branch carries changes beyond the profile's path scope — fork-trunk leak guard (see PR #723 incident); offending paths (first 10):
$(printf '%s\n' "$offending" | head -10)"
  fi
  commit_count="$(cd "$1" && git rev-list --count "$base..HEAD")" \
    || die "could not read the commit count against $base — refusing (fork-trunk leak guard)"
  printf '%s' "$commit_count" | LC_ALL=C grep -Eq '^[0-9]+$' \
    || die "unreadable commit count '$commit_count' against $base — refusing (fork-trunk leak guard)"
  if [ "$commit_count" -gt "$AUTOFIX_MAX_BRANCH_COMMITS" ]; then
    die "branch carries $commit_count commits ahead of $base (max \$AUTOFIX_MAX_BRANCH_COMMITS=$AUTOFIX_MAX_BRANCH_COMMITS) — fork-trunk leak guard (see PR #723 incident)"
  fi
  printf '%s' "$base"
}

# comment — post the self-review summary on the run's PR, or edit it when this
# run already posted one: the team keeps one summary comment per PR, updated by
# later rounds. Prints "<comment-id> <comment-url>" so the caller can hand the id
# to self-review's own state (set-comment).
if [ "$cmd" = comment ]; then
  body_file=""; confirmed=""
  while [ $# -gt 0 ]; do case "$1" in
    --body-file) body_file="$2"; shift 2 ;;
    --confirmed) confirmed="$2"; shift 2 ;;
    --confirmed=*) confirmed="${1#*=}"; shift ;;
    *) die "unknown flag: $1" ;;
  esac; done
  [ -n "$body_file" ] || die "--body-file required"
  check_summary_body "$body_file"

  url="$("$LEDGER" get "$run_id" '.pr.url // empty')"
  [ -n "$url" ] || die "no PR recorded in ledger for $run_id — run pr.sh create first"
  # Taken from the PR URL rather than the worktree's remote, so a comment can
  # still be posted or edited after the run's env has been swept.
  repo="$(printf '%s' "$url" | sed -nE 's#^https://github\.com/([^/]+/[^/]+)/pull/[0-9]+$#\1#p')"
  [ -n "$repo" ] || die "cannot read owner/repo from PR url: $url"
  cid="$("$LEDGER" get "$run_id" '.pr.summary_comment.id // empty')"

  # The artifact names the action as well as the bytes, so approving a new
  # comment never covers an edit, and once the posted comment is recorded below,
  # replaying that approval is refused instead of posting twice. A post whose id
  # never reaches the ledger is not covered; the die after the post says so.
  if is_secure "$run_id"; then
    art="$(mktemp)"
    {
      if [ -n "$cid" ]; then printf '### Target\nedit comment %s on %s\n\n' "$cid" "$url"
      else printf '### Target\nnew comment on %s\n\n' "$url"; fi
      printf '### Comment body\n'
      cat "$body_file"
    } > "$art"
    secure_gate "$run_id" pr-comment "$art" "$confirmed"
  fi

  if [ -n "$cid" ]; then
    gh api -X PATCH "repos/$repo/issues/comments/$cid" -F "body=@$body_file" >/dev/null
    curl="$("$LEDGER" get "$run_id" '.pr.summary_comment.url // empty')"
    "$LEDGER" history "$run_id" pr-comment updated "$curl"
  else
    out="$(gh pr comment "$url" --body-file "$body_file")"
    curl="$(printf '%s\n' "$out" | grep -Eo 'https://[^[:space:]]+#issuecomment-[0-9]+' | tail -1)"
    [ -n "$curl" ] || die "could not read a comment URL from gh pr comment output: $out — it may have posted; check $url before retrying"
    cid="${curl##*#issuecomment-}"
    "$LEDGER" set "$run_id" '.pr.summary_comment = {id:($i|tonumber), url:$u}' --arg i "$cid" --arg u "$curl"
    "$LEDGER" history "$run_id" pr-comment posted "$curl"
  fi
  printf '%s %s\n' "$cid" "$curl"
  exit 0
fi

# prepare — the forge's publish check. Runs everything create checks before it
# writes, over every body the delivery batch will carry, and records the head
# and the bodies' digests. Pushes nothing and posts nothing.
if [ "$cmd" = prepare ]; then
  [ "$("$LEDGER" get "$run_id" '.mode' 2>/dev/null)" = forge ] \
    || die "pr.sh prepare is the forge's publish check; this run is not a forge run"
  body_file=""; summary_file=""; closeout_file=""
  while [ $# -gt 0 ]; do case "$1" in
    --body-file) body_file="$2"; shift 2 ;;
    --summary-file) summary_file="$2"; shift 2 ;;
    --closeout-file) closeout_file="$2"; shift 2 ;;
    *) die "unknown flag: $1" ;;
  esac; done
  [ -n "$body_file" ] || die "--body-file required"
  plain=("$body_file"); [ -z "$closeout_file" ] || plain+=("$closeout_file")
  all=("${plain[@]}"); [ -z "$summary_file" ] || all+=("$summary_file")
  # Redaction before anything else, over every body the batch will carry.
  for f in "${plain[@]}"; do
    [ -s "$f" ] || die "body is empty or missing: $f"
    bash "$BIN/redact.sh" scan "$f" || die "redaction findings in $f — fix and retry"
  done
  [ -z "$summary_file" ] || check_summary_body "$summary_file"
  branch="$("$LEDGER" get "$run_id" '.branch // empty')"
  [ -n "$branch" ] || die "no branch recorded in ledger for $run_id"
  wt="$(run_wt "$run_id")"; [ -d "$wt" ] || die "worktree missing: $wt"
  cd "$wt"
  base="$(scope_guard "$wt")" || exit 1
  head="$(git rev-parse HEAD)"; remote="$(profile_get "$run_id" .push_remote)"
  files='[]'
  for f in "${all[@]}"; do
    files="$(printf '%s' "$files" | jq --arg p "$f" --arg d "$(secure_digest "$f")" '. + [{path:$p, sha256:$d}]')"
  done
  # The batch the operator approves is built from these bytes; the digests let
  # the dispatcher refuse a batch whose bodies changed after prepare.
  "$LEDGER" set "$run_id" '.prepared = {head:$h, base:$b, remote:$r, branch:$br, at:$t, files:$f}' \
    --arg h "$head" --arg b "$base" --arg r "$remote" --arg br "$branch" --arg t "$(now_utc)" --argjson f "$files"
  printf 'HEAD=%s\nBRANCH=%s\nBASE=%s\nREMOTE=%s\n' "$head" "$branch" "$base" "$remote"
  for f in "${all[@]}"; do printf 'FILE=%s\n' "$f"; done
  exit 0
fi

title=""; body_file=""; confirmed=""; no_copilot=""
while [ $# -gt 0 ]; do case "$1" in
  --title) title="$2"; shift 2 ;;
  --body-file) body_file="$2"; shift 2 ;;
  --confirmed) confirmed="$2"; shift 2 ;;
  --confirmed=*) confirmed="${1#*=}"; shift ;;
  --no-copilot) no_copilot=1; shift ;;
  *) die "unknown flag: $1" ;;
esac; done
[ -n "$title" ] && [ -n "$body_file" ] || die "--title and --body-file required"

# 1. redaction gate BEFORE any disclosure
bash "$BIN/redact.sh" scan "$body_file" || die "redaction findings in PR body — fix and retry"

branch="$("$LEDGER" get "$run_id" '.branch // empty')"
[ -n "$branch" ] || die "no branch recorded in ledger for $run_id"
wt="$(run_wt "$run_id")"
[ -d "$wt" ] || die "worktree missing: $wt"
cd "$wt"

# 2. PR-scope guard (see scope_guard above). Runs BEFORE the attempt cap below:
# a scope violation must not burn an attempt.
base="$(scope_guard "$wt")" || exit 1

# 3. disclosing-write gate (secure runs only). The artifact the operator approves
# is the RESOLVED PR body PLUS the commit messages and the commit diff that would be pushed to a
# public-capable surface — for a Security fix, that diff IS the disclosure. A
# preview writes it to the run dir; without a matching --confirmed=<digest> this
# exits 7 with no push, no PR, no Copilot. Non-secure runs skip straight past.
if is_secure "$run_id"; then
  art="$(mktemp)"
  {
    printf '### PR title\n%s\n\n### PR body\n' "$title"
    cat "$body_file"
    # Diff against $base, the base the scope guard above checks. Local
    # `main` is the fork-trunk on this machine, so main...HEAD shows the whole
    # trunk delta instead of what the push adds.
    # The push also publishes every commit message and trailer, and a squash
    # merge carries them to main. The digest binds them, so an approval covers
    # the words that go out as well as the diff.
    printf '\n\n### Commit messages (git log %s..HEAD) — pushed to github.com\n' "$base"
    git log --reverse --format='%h%n%B--' "$base..HEAD"
    printf '\n\n### Commit diff (git diff %s...HEAD) — pushed to github.com\n' "$base"
    git diff "$base...HEAD"
  } > "$art"
  secure_gate "$run_id" pr "$art" "$confirmed"   # preview+exit7, or verify digest & return
fi

# 4. attempt cap — counted only for a REAL create attempt (a gated preview above
# exits before here, so it never consumes an attempt).
attempts="$("$LEDGER" get "$run_id" '.attempts.pr')"
if [ "$attempts" -ge "$AUTOFIX_MAX_ATTEMPTS" ]; then
  "$LEDGER" set "$run_id" '.terminal = "escalated"'
  die "PR attempts exhausted ($attempts) — escalating"
fi
"$LEDGER" set "$run_id" '.attempts.pr += 1'

# 5. push (idempotent — branch may carry new commits even when a PR exists)
git push -u "$(profile_get "$run_id" .push_remote)" "$branch"

# 6. adopt an existing open PR for this branch, or create a draft PR
existing="$(gh pr list --head "$branch" --state open --json url,number,isDraft --jq '.[0]' 2>/dev/null || true)"
if [ -n "$existing" ] && [ "$existing" != "null" ]; then
  url="$(printf '%s' "$existing" | jq -r '.url // empty')"
  num="$(printf '%s' "$existing" | jq -r '.number // empty')"
  [ -n "$url" ] && [ -n "$num" ] || die "could not parse existing PR from: $existing"
  log "adopting existing open PR #$num for $branch"
  history_note="adopted existing PR $url"
else
  create_out="$(gh pr create --draft --title "$title" --body-file "$body_file" --base main --head "$branch")"
  url="$(printf '%s\n' "$create_out" | grep -Eo 'https://[^[:space:]]+/pull/[0-9]+' | tail -1)"
  [ -n "$url" ] || die "could not parse PR URL from gh pr create output: $create_out"
  num="${url##*/}"
  history_note="$url"
fi

# 7. Copilot request (advisory; REST because gh pr view misses the bot).
# --no-copilot (spec Override 2) declines it; the decision is logged either way.
# Only a newly created PR gets one: the repo asks for a single Copilot pass per
# PR, and every push after the first comes back through the adopt path above.
if [ -n "$no_copilot" ]; then
  "$LEDGER" history "$run_id" pr no-copilot "operator declined Copilot review request"
  log "Copilot review request skipped (--no-copilot)"
elif [ -n "$existing" ] && [ "$existing" != "null" ]; then
  log "Copilot review not re-requested on adopted PR #$num (one pass per PR)"
else
  gh api "repos/{owner}/{repo}/pulls/$num/requested_reviewers" \
    -f 'reviewers[]=copilot-pull-request-reviewer[bot]' >/dev/null 2>&1 \
    || log "Copilot review request failed (advisory — continuing)"
fi

# 8. record. A re-run that adopts the same PR keeps .pr.summary_comment, so the
# next `comment` edits that summary instead of posting a second one. A different
# PR (the first was closed) starts clean: its comment belongs to the old PR.
"$LEDGER" set "$run_id" '.pr = (if (.pr.url // "") == $u then .pr else {} end) + {url:$u, number:($n|tonumber)} | .terminal = "delivered"' \
  --arg u "$url" --arg n "$num"
"$LEDGER" history "$run_id" pr delivered "$history_note"
echo "$url"
