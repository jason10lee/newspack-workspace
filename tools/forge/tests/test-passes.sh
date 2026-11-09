#!/bin/bash
# forge pass / forge passes: the record of humanize passes that publish step 3
# reports from, so the report survives a compaction.
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
. ../../autofix/bin/lib/common.sh   # secure_digest
D="$(dirname "$(bash "$L" init forge-p NPPM-8 forge)")"
printf 'The PR body.\n' > "$D/pr-body.md"
printf 'Summary.\n' > "$D/review-summary.md"
prep() { # the prepare record for the files as they are now
  local files
  files="$(jq -n --arg b "$D/pr-body.md" --arg bs "$(secure_digest "$D/pr-body.md")" \
    --arg s "$D/review-summary.md" --arg ss "$(secure_digest "$D/review-summary.md")" \
    '[{role:"body", path:$b, sha256:$bs}, {role:"summary", path:$s, sha256:$ss}]')"
  bash "$L" set forge-p '.prepared = {head:"abc", base:"main", remote:"fork", branch:"br", files:$f}' --argjson f "$files"
}
prep
fp() { bash "$F" pass "$@" 2>&1; }
fps() { bash "$F" passes "$@" 2>&1; }

out="$(fp forge-p body --none)"; rc=$?
assert_eq 1 "$rc" "pass refuses a run that has not delivered"
bash "$L" set forge-p '.delivery = {kind: "delivered", at: "2026-11-09T01:00:00Z"}'

out="$(fp forge-p closeout --none)"; rc=$?
assert_eq 1 "$rc" "pass refuses a role the prepare record lacks"
assert_contains "$out" "closeout" "and names it"
fp forge-p body >/dev/null; assert_eq 1 $? "pass refuses a record with no findings and no --none"
fp forge-p body --none --applied x >/dev/null; assert_eq 1 $? "pass refuses --none beside findings"
fp forge-p nope --none >/dev/null; assert_eq 1 $? "pass refuses an unknown role"

out="$(fps forge-p)"; rc=$?
assert_eq 3 "$rc" "passes fails while a body has no pass"
assert_contains "$out" "body: no pass" "and says which"

fp forge-p body --applied "cut a closing line that restated the opening" \
  --declined "kept 'we' — it names the team, not the agent" >/dev/null
assert_eq 0 $? "pass records applied and declined findings"
assert_eq 1 "$(bash "$L" get forge-p '.humanize_passes | length')" "as one ledger row"
assert_eq "2026-11-09T01:00:00Z" "$(bash "$L" get forge-p '.humanize_passes[0].delivery_at')" "tied to the delivery"
assert_eq "$(secure_digest "$D/pr-body.md")" "$(bash "$L" get forge-p '.humanize_passes[0].sha256')" "with the digest of the text it read"
fp forge-p summary --declined "a quote, not our prose" >/dev/null
assert_eq 0 $? "a pass may decline everything"

out="$(fps forge-p)"; rc=$?
assert_eq 0 "$rc" "passes succeeds when every body's last pass saw its text"
assert_contains "$out" "cut a closing line that restated the opening" "and lists what was applied"
assert_contains "$out" "kept 'we' — it names the team, not the agent" "and what was declined"
assert_contains "$out" "a quote, not our prose" "even for a body whose findings were all declined"

# A finding applied after the pass changes the text: the last pass no longer covers it.
printf 'The PR body, revised.\n' > "$D/pr-body.md"; prep
out="$(fps forge-p)"; rc=$?
assert_eq 3 "$rc" "passes fails when a body changed after its last pass"
assert_contains "$out" "body: changed since its last pass" "and says which"
fp forge-p body --none >/dev/null
out="$(fps forge-p)"; rc=$?
assert_eq 0 "$rc" "a fresh pass over the new text covers it"
assert_contains "$out" "pass 2" "and both passes are listed"
assert_contains "$out" "no findings" "including one that found nothing"

# A pass reads the file now, not the prepare record: an edit without a fresh
# prepare is caught too.
printf 'Edited, not prepared.\n' > "$D/review-summary.md"
fps forge-p >/dev/null; assert_eq 3 $? "passes fails when a body's file no longer matches its prepare record"
printf 'Summary.\n' > "$D/review-summary.md"

# A rework ends the delivery; passes from it no longer count.
bash "$L" set forge-p '.delivery = {kind: "delivered", at: "2026-11-09T05:00:00Z"}'
out="$(fps forge-p)"; rc=$?
assert_eq 3 "$rc" "passes from an earlier delivery do not count"
assert_eq 0 "$(grep -c "cut a closing line" <<<"$out")" "and are not listed"

bash "$F" pass nope body --none >/dev/null 2>&1; assert_eq 1 $? "pass refuses an unknown run"
bash "$F" passes nope >/dev/null 2>&1; assert_eq 1 $? "passes refuses an unknown run"

finish
