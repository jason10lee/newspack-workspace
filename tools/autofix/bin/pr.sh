#!/bin/bash
set -euo pipefail
BIN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$BIN/lib/common.sh"
require jq
require git
require gh
LEDGER="$BIN/ledger.sh"

cmd="${1:-}"
case "$cmd" in
  create|comment) ;;
  *) die "usage: pr.sh create <run_id> --title <t> --body-file <f> [--confirmed=<digest>] [--no-copilot]
       pr.sh comment <run_id> --body-file <f> [--confirmed=<digest>]" ;;
esac
run_id="${2:?}"; shift 2

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
  [ -s "$body_file" ] || die "comment body is empty: $body_file"
  bash "$BIN/redact.sh" scan "$body_file" || die "redaction findings in comment body — fix and retry"

  url="$("$LEDGER" get "$run_id" '.pr.url // empty')"
  [ -n "$url" ] || die "no PR recorded in ledger for $run_id — run pr.sh create first"
  # Taken from the PR URL rather than the worktree's remote, so a comment can
  # still be posted or edited after the run's env has been swept.
  repo="$(printf '%s' "$url" | sed -nE 's#^https://github\.com/([^/]+/[^/]+)/pull/[0-9]+$#\1#p')"
  [ -n "$repo" ] || die "cannot read owner/repo from PR url: $url"
  cid="$("$LEDGER" get "$run_id" '.pr.summary_comment.id // empty')"

  # The artifact names the action as well as the bytes, so approving a new
  # comment never covers an edit, and replaying an approval after the post
  # lands is refused instead of posting twice.
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
wt="$(wt_dir "$branch")"
[ -d "$wt" ] || die "worktree missing: $wt"
cd "$wt"

# 2. PR-scope guard — a run branch must be based on, and only touch, the
# affected repo. Real incident: an autofix run branched from this machine's
# local fork-trunk `main` (a 153-commit local tooling aggregate) and this
# script pushed the WHOLE delta upstream as PR #723 (closed within minutes).
# Runs BEFORE the attempt cap below — a scope violation must not burn an
# attempt. Fail closed throughout: never guess at the upstream base ref.
fetch_upstream_main "$wt"

affected_repo="$(ledger_decision "$run_id" affected_repo)"
[ -n "$affected_repo" ] || die "no affected_repo decision in ledger for $run_id"

offending="$(git diff --name-only origin/main...HEAD \
  | grep -v -e "^plugins/${affected_repo}/" -e "^themes/${affected_repo}/" || true)"
if [ -n "$offending" ]; then
  die "branch carries changes beyond the affected repo — fork-trunk leak guard (see PR #723 incident); offending paths (first 10):
$(printf '%s\n' "$offending" | head -10)"
fi

commit_count="$(git rev-list --count origin/main..HEAD)"
if [ "$commit_count" -gt "$AUTOFIX_MAX_BRANCH_COMMITS" ]; then
  die "branch carries $commit_count commits ahead of origin/main (max \$AUTOFIX_MAX_BRANCH_COMMITS=$AUTOFIX_MAX_BRANCH_COMMITS) — fork-trunk leak guard (see PR #723 incident)"
fi

# 3. disclosing-write gate (secure runs only). The artifact the operator approves
# is the RESOLVED PR body PLUS the commit diff that would be pushed to a
# public-capable surface — for a Security fix, that diff IS the disclosure. A
# preview writes it to the run dir; without a matching --confirmed=<digest> this
# exits 7 with no push, no PR, no Copilot. Non-secure runs skip straight past.
if is_secure "$run_id"; then
  art="$(mktemp)"
  {
    printf '### PR title\n%s\n\n### PR body\n' "$title"
    cat "$body_file"
    # Diff against origin/main, the base the scope guard above checks. Local
    # `main` is the fork-trunk on this machine, so main...HEAD shows the whole
    # trunk delta instead of what the push adds.
    printf '\n\n### Commit diff (git diff origin/main...HEAD) — pushed to github.com\n'
    git diff origin/main...HEAD
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
git push -u origin "$branch"

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
