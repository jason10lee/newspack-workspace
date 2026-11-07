#!/bin/bash
set -euo pipefail
BIN="$(dirname "${BASH_SOURCE[0]}")"
. "$BIN/lib/common.sh"
. "$BIN/lib/linear.sh"
require jq
LEDGER="$BIN/ledger.sh"

ISSUE_Q='query Issue($id: String!) { issue(id: $id) {
  id identifier assignee { id } state { id name } team { id }
  labels { nodes { id name } } } }'
POSTCLAIM_Q='query IssuePostClaim($id: String!) { issue(id: $id) {
  id identifier assignee { id } state { id name } labels { nodes { id name } }
  comments(first: 50) { nodes { body } } } }'
COMMENTS_Q='query IssueComments($id: String!) { issue(id: $id) {
  id comments(first: 100) { nodes { body } } } }'
STATES_Q='query States($teamId: String!) { team(id: $teamId) { states { nodes { id name } } } }'
UPDATE_M='mutation Update($id: String!, $input: IssueUpdateInput!) {
  issueUpdate(id: $id, input: $input) { success } }'
COMMENT_M='mutation Comment($input: CommentCreateInput!) {
  commentCreate(input: $input) { success } }'

fetch_issue() { # opname issue-id
  local q
  case "$1" in
    issue_comments) q="$COMMENTS_Q" ;;
    issue)          q="$ISSUE_Q" ;;
    *)              q="$POSTCLAIM_Q" ;;
  esac
  linear_gql "$1" "$q" "$(jq -nc --arg id "$2" '{id:$id}')" | jq '.data.issue'
}
update_issue() { # uuid input-json
  # -c keeps requests.log one line per request (linear_gql just appends
  # "$vars\n" — a pretty-printed multi-line vars blob would break any
  # line-oriented grep/tail over the log).
  linear_gql issueUpdate "$UPDATE_M" \
    "$(jq -nc --arg id "$1" --argjson input "$2" '{id:$id, input:$input}')" >/dev/null
}
comment() { # uuid body
  linear_gql commentCreate "$COMMENT_M" \
    "$(jq -nc --arg id "$1" --arg body "$2" '{input:{issueId:$id, body:$body}}')" >/dev/null
}

cmd="${1:?usage: claim.sh claim|comment|release <ISSUE-ID> <RUN_ID> [flags]}"; issue_id="${2:?}"; run_id="${3:?}"; shift 3

# A run acts only on the issue its ledger names. For the forge this is the
# grant's boundary ("no write to an issue the operator did not name"); for
# every other mode it costs nothing, because they always pass their own issue.
ledger_issue="$("$LEDGER" get "$run_id" '.issue')" || die "cannot read ledger for $run_id"
[ "$(printf '%s' "$ledger_issue" | tr '[:lower:]' '[:upper:]')" = "$(printf '%s' "$issue_id" | tr '[:lower:]' '[:upper:]')" ] \
  || die "issue $issue_id is not this run's issue ($ledger_issue) — refusing"

# The forge grant covers assignee and state only. A comment or a label is
# outside it, so refuse them here rather than trust the caller.
if is_forge "$run_id"; then
  case "$cmd" in
    comment) die "forge runs post no Linear comments (outside the forge grant)" ;;
    release) for a in "$@"; do case "$a" in --comment|--fail-label)
      die "forge runs release without a comment or label (outside the forge grant)" ;; esac; done ;;
  esac
fi

# await_claim <issue-id> <me> <run-id> <need-comment> <prior-assignee> — read
# the issue until the claim shows, and leave the last read in $post. Linear can
# answer a read with data from before a write made a moment earlier, so a read
# showing the issue as it was before the claim proves nothing. Only an assignee
# that is neither us nor the prior one ends the wait early. Returns 2 when a
# read fails: that says nothing about the claim, and the caller must not treat
# it as a lost race.
await_claim() {
  local tries="${AUTOFIX_CLAIM_RECHECKS:-5}" delay="${AUTOFIX_CLAIM_RECHECK_DELAY:-2}" i=1 got n
  while :; do
    post="$(fetch_issue issue_postclaim "$1")" || return 2
    got="$(printf '%s' "$post" | jq -r '.assignee.id // ""')"
    if [ "$got" = "$2" ]; then
      [ "$4" != comment ] && return 0
      n="$(printf '%s' "$post" | jq -r --arg r "$3" '[.comments.nodes[].body | select(contains($r))] | length')"
      [ "$n" != 0 ] && return 0
    elif [ -n "$got" ] && [ "$got" != "$5" ]; then
      return 1
    fi
    [ "$i" -ge "$tries" ] && return 1
    i=$((i + 1)); sleep "$delay"
  done
}

# back_off <uuid> <me> <in-progress-state-id> — undo this run's claim after a
# lost race, using the last await_claim read in $post. A field goes back to its
# prior value only when that read shows the value this run set (spec magi #2,
# and the forge grant's condition). A read showing anything else, the prior
# value included, may be stale over someone else's write, so the field is left
# as it is and named in the log.
back_off() {
  local prior_state prior_assignee got_state got_assignee input='{}'
  prior_state="$("$LEDGER" get "$run_id" '.linear_prior.stateId')"
  prior_assignee="$("$LEDGER" get "$run_id" '.linear_prior.assigneeId')"
  got_state="$(printf '%s' "$post" | jq -r '.state.id // ""')"
  got_assignee="$(printf '%s' "$post" | jq -r '.assignee.id // ""')"
  if [ "$got_state" = "$3" ]; then
    input="$(printf '%s' "$input" | jq --arg s "$prior_state" '.stateId = $s')"
  else
    log "back-off: state not shown as ours ($got_state); left as it is"
  fi
  if [ "$got_assignee" = "$2" ]; then
    input="$(printf '%s' "$input" | jq --arg a "$prior_assignee" '.assigneeId = (if $a == "null" or $a == "" then null else $a end)')"
  else
    log "back-off: assignee not shown as ours (${got_assignee:-none}); left as it is"
  fi
  [ "$input" = "{}" ] || update_issue "$1" "$input"
}

case "$cmd" in
  claim)
    # same-issue guard (spec magi #4): another non-terminal run on this issue?
    for lf in "$RUNS_DIR"/*/ledger.json; do
      [ -f "$lf" ] || continue
      # skip unparsable ledgers: under set -e a jq parse failure would abort the
      # claim outright; a corrupt ledger can't testify to an active run anyway
      jq -e 'type == "object"' "$lf" >/dev/null 2>&1 \
        || { log "same-issue guard: skipping unparsable ledger $lf"; continue; }
      # A forge run the operator retired is done with the issue even though
      # nothing set its terminal state, so it does not hold the issue either.
      other="$(jq -r --arg i "$issue_id" 'select(.issue==$i and .terminal==null and .retired_at==null) | .run_id' "$lf")"
      if [ -n "$other" ] && [ "$other" != "$run_id" ]; then
        log "SAME-ISSUE: non-terminal run $other already targets $issue_id"
        # mark THIS run's own ledger terminal now — otherwise it stays
        # terminal:null forever (never swept, and itself trips this same
        # guard for every future run against this issue).
        "$LEDGER" set "$run_id" '.terminal = "bailed-superseded"'
        exit 4
      fi
    done
    # Forge: the dispatcher writes launch=confirmed once it has seen this
    # session start. A session that starts after the dispatcher gave up finds
    # "abandoned" (or nothing) and must claim nothing.
    if is_forge "$run_id" && [ "$("$LEDGER" get "$run_id" '.launch // ""')" != confirmed ]; then
      log "forge: launch not confirmed for $run_id — not claiming"
      exit 8
    fi
    # linear_prior is this launch's alone: release restores from it, so a
    # rework clears it before any read that can stop the claim. Whether any
    # launch of the run wrote to the issue survives in issue_written (forge's
    # later_run reads it), so a rework whose claim stops before its own write
    # still shows the earlier one.
    "$LEDGER" set "$run_id" '.issue_written = (.issue_written or .linear_prior.claimed_state_id != null) | .linear_prior = null'
    me="$(linear_viewer_id)"
    issue="$(fetch_issue issue "$issue_id")"
    uuid="$(printf '%s' "$issue" | jq -r .id)"
    team="$(printf '%s' "$issue" | jq -r .team.id)"
    "$LEDGER" set "$run_id" '.linear_prior = $p' --argjson p "$(printf '%s' "$issue" \
      | jq '{assigneeId:(.assignee.id // null), stateId:.state.id, stateName:.state.name,
             labels:[.labels.nodes[].id]}')"
    prior_assignee_id="$(printf '%s' "$issue" | jq -r '.assignee.id // ""')"
    in_progress="$(linear_gql states "$STATES_Q" "$(jq -nc --arg teamId "$team" '{teamId:$teamId}')" \
      | jq -r '.data.team.states.nodes[] | select(.name=="In Progress") | .id')"
    [ -n "$in_progress" ] || die "no 'In Progress' state on team $team"
    # remember the state id THIS RUN set, so release can tell "still where we
    # put it" apart from "a human moved it mid-run" (conditional restore).
    "$LEDGER" set "$run_id" '.linear_prior.claimed_state_id = $s | .issue_written = true' --arg s "$in_progress"
    # assign-self + move to In Progress are working-state transitions, NOT
    # disclosing writes — ungated in every mode (spec judgment call #2).
    update_issue "$uuid" "$(jq -nc --arg a "$me" --arg s "$in_progress" '{assigneeId:$a, stateId:$s}')"

    if is_secure "$run_id" || is_forge "$run_id"; then
      # SECURE: the claim comment is a disclosing write — DEFERRED, not posted.
      # Preview it for the operator; ownership is verified by assignee alone,
      # and the missing run-comment marker is recorded as drift.
      # FORGE: no claim comment at all — the team does not post them, and the
      # forge grant covers assignee and state only. Same assignee-only check.
      if is_secure "$run_id"; then
        cbody="🤖 autofix run $run_id started"
        cf="$(mktemp)"; printf '%s\n' "$cbody" > "$cf"
        cdg="$(secure_digest "$cf")"
        pvdir="$RUNS_DIR/$run_id/previews"; (umask 077; mkdir -p "$pvdir")
        cp "$cf" "$pvdir/claim-$cdg.txt"; rm -f "$cf"
        "$LEDGER" set "$run_id" '.decisions += [{key:"claim_comment", value:"deferred"}]'
        "$LEDGER" history "$run_id" intake claim-comment-deferred "digest=$cdg file=$pvdir/claim-$cdg.txt"
        log "secure: claim comment deferred (preview $pvdir/claim-$cdg.txt) — verifying by assignee only"
      fi
      held=0; await_claim "$issue_id" "$me" "$run_id" assignee "$prior_assignee_id" || held=$?
      # A failed read leaves the run open, as any other failure here does, so
      # a later restore can still find it.
      [ "$held" != 2 ] || die "post-claim read of $issue_id failed; claim not verified, run left open"
      if [ "$held" != 0 ]; then
        log "LOST-RACE ($(is_secure "$run_id" && echo secure || echo forge)): claim did not hold (assignee=$(printf '%s' "$post" | jq -r '.assignee.id // ""')); backing off"
        back_off "$uuid" "$me" "$in_progress"
        # no back-off comment in secure mode (a disclosing write); log only.
        "$LEDGER" set "$run_id" '.terminal = "bailed-lost-claim-race"'
        exit 5
      fi
      "$LEDGER" drift "$run_id" claim_marker "run-comment" "assignee-only"
      if is_secure "$run_id"; then
        "$LEDGER" history "$run_id" intake claimed "assignee=$me state=In Progress (secure; comment deferred)"
      else
        "$LEDGER" history "$run_id" intake claimed "assignee=$me state=In Progress (forge; no comment)"
      fi
      log "claimed $issue_id as $(is_secure "$run_id" && echo secure || echo forge) run $run_id"
    else
      comment "$uuid" "🤖 autofix run $run_id started"
      # verify the claim held (spec codex #1): assignee is us AND our comment exists.
      # Ownership = assignee-match AND *this run's* claim comment. Assignee
      # alone is not run-specific — two concurrent runs under the same operator
      # identity would both see a match — so the comment must carry our RUN_ID.
      held=0; await_claim "$issue_id" "$me" "$run_id" comment "$prior_assignee_id" || held=$?
      # A failed read leaves the run open, as any other failure here does, so
      # a later restore can still find it.
      [ "$held" != 2 ] || die "post-claim read of $issue_id failed; claim not verified, run left open"
      if [ "$held" != 0 ]; then
        log "LOST-RACE: claim did not hold (assignee=$(printf '%s' "$post" | jq -r '.assignee.id // ""')); backing off"
        back_off "$uuid" "$me" "$in_progress"
        comment "$uuid" "🤖 autofix run $run_id detected a competing claim and backed off."
        "$LEDGER" set "$run_id" '.terminal = "bailed-lost-claim-race"'
        exit 5
      fi
      "$LEDGER" history "$run_id" intake claimed "assignee=$me state=In Progress"
      log "claimed $issue_id as run $run_id"
    fi ;;
  comment)
    # gated, redaction-scanned, idempotent comment primitive (spec Piece 2).
    # In secure runs the skill routes ALL comment posting through here so that
    # redaction + digest-gate + audit apply uniformly.
    body_file=""; confirmed=""
    while [ $# -gt 0 ]; do case "$1" in
      --body-file) body_file="$2"; shift 2 ;;
      --confirmed) confirmed="$2"; shift 2 ;;
      --confirmed=*) confirmed="${1#*=}"; shift ;;
      *) die "unknown flag: $1" ;;
    esac; done
    [ -n "$body_file" ] || die "--body-file required"
    [ -f "$body_file" ] || die "body file not found: $body_file"
    # redaction gate FIRST (base secret scan), before any digest/preview/post.
    bash "$BIN/redact.sh" scan "$body_file" \
      || die "redaction findings in comment body — redact and retry (nothing posted)"
    # disclosing-write gate: preview+exit7 unless a matching digest is confirmed.
    secure_gate "$run_id" comment "$body_file" "$confirmed"
    dg="$(secure_digest "$body_file")"
    marker="<!-- autofix:$run_id:$dg -->"
    # idempotency (spec magi #13): skip if this exact (run,digest) marker exists.
    existing="$(fetch_issue issue_comments "$issue_id")"
    if printf '%s' "$existing" | jq -e --arg m "$marker" \
        '.comments.nodes[] | select(.body | contains($m))' >/dev/null 2>&1; then
      log "comment already posted for $run_id/$dg — skipping (idempotent)"
      exit 0
    fi
    uuid="$(printf '%s' "$existing" | jq -r .id)"
    comment "$uuid" "$(cat "$body_file")

$marker"
    "$LEDGER" history "$run_id" comment posted "digest=$dg"
    log "posted comment on $issue_id ($dg)" ;;
  release)
    fail_label=""; note=""; confirmed=""; if_assigned=""
    while [ $# -gt 0 ]; do case "$1" in
      --fail-label) fail_label=1; shift ;;
      # Restore nothing unless the issue is still assigned to us. For a run that
      # lost its claim race, In Progress under another assignee is the winner's.
      --if-assigned) if_assigned=1; shift ;;
      # FAIL CLOSED on an explicitly passed empty comment (run autofix-nppm-305
      # incident: a vanished payload file became --comment "" and the release
      # shipped with no explanation on the issue). Omitting --comment is the
      # legitimate "no comment" path; passing it empty is always an upstream bug.
      --comment) note="${2-}"; [ -n "$note" ] || die "--comment given but empty — refusing to release without the explanation (fail closed)"; shift 2 ;;
      --confirmed) confirmed="$2"; shift 2 ;;
      --confirmed=*) confirmed="${1#*=}"; shift ;;
      *) die "unknown flag: $1" ;;
    esac; done
    # redaction gate FIRST — before any Linear read/write — so a finding
    # leaves Linear untouched (no partial release) rather than gating only
    # the PR body while a no-go/cannot-reproduce comment ships unredacted.
    if [ -n "$note" ]; then
      note_file="$(mktemp)"
      trap 'rm -f "$note_file"' EXIT
      printf '%s\n' "$note" > "$note_file"
      bash "$BIN/redact.sh" scan "$note_file" \
        || die "redaction findings in release comment — redact and retry (no Linear write performed)"
    fi
    me="$(linear_viewer_id)"
    cur="$(fetch_issue issue_release "$issue_id")"
    uuid="$(printf '%s' "$cur" | jq -r .id)"
    prior_assignee="$("$LEDGER" get "$run_id" '.linear_prior.assigneeId')"
    prior_state="$("$LEDGER" get "$run_id" '.linear_prior.stateId')"
    claimed_state="$("$LEDGER" get "$run_id" '.linear_prior.claimed_state_id')"
    cur_assignee="$(printf '%s' "$cur" | jq -r '.assignee.id // ""')"
    cur_state="$(printf '%s' "$cur" | jq -r '.state.id // ""')"
    restored=""; drifted=""
    # A secure release runs more than once: the gated pass restores state and logs
    # it, then a confirmed pass (or a fresh preview after an edited brief) follows.
    # Evaluating the restore again reads the run's own restore as drift, since the
    # issue is now unassigned and back in its prior state, so skip it.
    already_restored=""
    if is_secure "$run_id" \
      && [ "$("$LEDGER" get "$run_id" '[.stage_history[] | select(.stage == "release" and .outcome == "restored")] | length')" -gt 0 ]; then
      already_restored=1
      restored="(done at preview)"
    fi
    # A launch with no claimed state stopped before its claim wrote, so the
    # issue holds nothing of this run's to restore. Whoever holds it now, the
    # operator included, set that themselves; it is not drift either.
    if [ -z "$already_restored" ] && { [ -z "$claimed_state" ] || [ "$claimed_state" = null ]; }; then
      already_restored=1
      restored="(nothing claimed)"
    fi
    # Conditional restore of assignee/status is a working-state transition, NOT
    # a disclosing write — it proceeds UNGATED even in secure mode, because
    # leaving a Security issue stuck In-Progress/assigned-to-bot is worse than
    # the minimal signal of restoring it. "Conditional" = restore only where the
    # field still holds the value this run set (spec magi #9).
    input='{}'
    if [ -n "$already_restored" ]; then
      :
    elif [ "$cur_assignee" = "$me" ]; then
      input="$(printf '%s' "$input" | jq --arg a "$prior_assignee" \
        '.assigneeId = (if $a == "null" or $a == "" then null else $a end)')"
      restored="$restored assigneeId"
    else
      "$LEDGER" drift "$run_id" assigneeId "$me" "$cur_assignee"
      drifted="$drifted assigneeId"
    fi
    if [ -n "$already_restored" ]; then
      :
    elif [ -n "$if_assigned" ] && [ "$cur_assignee" != "$me" ]; then
      "$LEDGER" drift "$run_id" stateId "$claimed_state" "$cur_state"
      drifted="$drifted stateId"
    elif [ "$cur_state" = "$claimed_state" ]; then
      input="$(printf '%s' "$input" | jq --arg s "$prior_state" '.stateId = $s')"
      restored="$restored stateId"
    else
      "$LEDGER" drift "$run_id" stateId "$claimed_state" "$cur_state"
      drifted="$drifted stateId"
    fi

    # SECURE gate: the np-agent-failed label AND the brief are disclosing writes.
    # Restore state first (above), then gate. Without a matching confirmation the
    # state is left clean, the brief is previewed, and the run exits 7 — the
    # operator posts the approved brief (and label) via a confirmed re-invocation.
    if is_secure "$run_id" && [ -z "$confirmed" ] && { [ -n "$fail_label" ] || [ -n "$note" ]; }; then
      [ "$input" = "{}" ] || update_issue "$uuid" "$input"
      art="$(mktemp)"
      {
        printf 'np-agent-failed: %s\n\n' "$([ -n "$fail_label" ] && echo yes || echo no)"
        [ -n "$note" ] && cat "$note_file" || printf '(no comment)\n'
      } > "$art"
      "$LEDGER" history "$run_id" release restored \
        "restored:[${restored# }] drifted:[${drifted# }] (secure: label/comment gated)"
      secure_gate "$run_id" release "$art" ""   # writes preview, prints GATED, exits 7
    fi
    # Confirmed (secure) or non-secure: verify the digest binds to what we post.
    if is_secure "$run_id" && { [ -n "$fail_label" ] || [ -n "$note" ]; }; then
      art="$(mktemp)"
      {
        printf 'np-agent-failed: %s\n\n' "$([ -n "$fail_label" ] && echo yes || echo no)"
        [ -n "$note" ] && cat "$note_file" || printf '(no comment)\n'
      } > "$art"
      secure_gate "$run_id" release "$art" "$confirmed"   # verifies digest, returns 0
    fi
    if [ -n "$fail_label" ]; then
      cur_labels="$(printf '%s' "$cur" | jq '[.labels.nodes[].id]')"
      input="$(printf '%s' "$input" | jq --argjson l "$cur_labels" --arg f "$AUTOFIX_FAILED_LABEL_ID" \
        '.labelIds = ($l + [$f] | unique)')"
    fi
    [ "$input" = "{}" ] || update_issue "$uuid" "$input"
    [ -n "$note" ] && comment "$uuid" "$note"
    "$LEDGER" history "$run_id" release restored \
      "restored:[${restored# }] drifted:[${drifted# }]"
    log "released $issue_id" ;;
  *) die "unknown subcommand: $cmd" ;;
esac
