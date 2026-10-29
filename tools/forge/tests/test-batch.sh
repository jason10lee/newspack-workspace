#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh; . ./setup.sh
. ../../autofix/bin/lib/common.sh   # secure_digest
REPO="$(mktemp -d)"; export AUTOFIX_WORKSPACE_ROOT="$REPO"
FX=../../autofix/tests/fixtures
M="$(mktemp -d)"; export AUTOFIX_LINEAR_MOCK_DIR="$M"
cp "$FX/viewer.json" "$FX/states.json" "$M/"; cp "$FX/issue_ok.json" "$M/issue.json"
g() { git -C "$REPO" -c user.name=t -c user.email=t@example.com "$@"; }
g init -q -b main; g commit -q --allow-empty -m one
g remote add fork git@github.com:someone/tools.git
HEAD1="$(g rev-parse HEAD)"
D="$(dirname "$(bash "$L" init forge-b NPPM-7 forge)")"
printf 'The PR body.\nFORGE_BODY\nIt'"'"'s $HOME and `x`.\n' > "$D/pr-body.md"
printf 'Summary.\n' > "$D/review-summary.md"
printf 'Closeout.\n' > "$D/closeout.md"
files="$(jq -n --arg b "$D/pr-body.md" --arg bs "$(secure_digest "$D/pr-body.md")" \
  --arg s "$D/review-summary.md" --arg ss "$(secure_digest "$D/review-summary.md")" \
  --arg c "$D/closeout.md" --arg cs "$(secure_digest "$D/closeout.md")" \
  '[{role:"body", path:$b, sha256:$bs}, {role:"closeout", path:$c, sha256:$cs}, {role:"summary", path:$s, sha256:$ss}]')"
bash "$L" set forge-b '.decisions += [{key:"profile", value:"newspack-workspace-tooling"}, {key:"pr_title", value:"fix(forge): it'"'"'s fixed"}]
  | .worktree = $w | .branch = "br-1" | .prepared = {head:$h, base:"main", remote:"fork", branch:"br-1", files:$f}' \
  --arg w "$REPO" --arg h "$HEAD1" --argjson f "$files"
fb() { bash "$F" batch "$@" 2>/dev/null; }

out="$(fb forge-b)"; rc=$?
assert_eq 1 "$rc" "batch refuses a run that has not delivered"
bash "$L" set forge-b '.delivery = {kind: "delivered", at: "2026-10-29T00:00:00Z"}'
out="$(fb forge-b)"; rc=$?
assert_eq 0 "$rc" "batch builds a plan for a delivered run"
plan="$(sed -n 's/^BATCH=//p' <<<"$out")"
assert_eq "$D/batch.jsonl" "$plan" "in the run directory"
assert_contains "$out" "ENTRIES=4" "with four entries"
assert_eq "$plan" "$(bash "$L" get forge-b .batch.path)" "and records it"
line() { sed -n "${1}p" "$plan"; }
assert_eq "git -C '$REPO' push fork $HEAD1:refs/heads/br-1" "$(line 1 | jq -r .tool_input.command)" "line 1 pushes the exact head"
pr="$(line 2 | jq -r .tool_input.command)"
assert_contains "$pr" "gh pr create -R someone/tools --draft --base main --head br-1 --title 'fix(forge): it'\\''s fixed' --body-file - <<'FORGE_BODY_1'" "line 2 opens a draft PR with the quoted title"
assert_contains "$pr" "It's \$HOME and \`x\`." "and the body verbatim"
assert_eq "FORGE_BODY_1" "$(printf '%s\n' "$pr" | tail -1)" "with a delimiter the body does not contain"
assert_contains "$(line 3 | jq -r .tool_input.command)" "gh pr comment br-1 -R someone/tools --body-file - <<'FORGE_BODY'" "line 3 posts the review summary"
assert_eq "mcp__plugin_linear_linear__save_comment" "$(line 4 | jq -r .tool)" "line 4 is the Linear closeout"
assert_eq "NPPM-7" "$(line 4 | jq -r .tool_input.issueId)" "on the run's issue"
assert_eq "Closeout." "$(line 4 | jq -r .tool_input.body)" "with its body"

BA="$HOME/.agent-knowledge/bin/batch-approval"
if [ -f "$BA" ]; then
  verdict="$(python3 - "$BA" "$plan" <<'PY'
import importlib.machinery, importlib.util, json, sys
loader = importlib.machinery.SourceFileLoader("ba", sys.argv[1])
spec = importlib.util.spec_from_loader("ba", loader); m = importlib.util.module_from_spec(spec); loader.exec_module(m)
for raw in open(sys.argv[2]):
    e = json.loads(raw)
    if e["tool"] == "Bash":
        why = m.bound(e["tool_input"]["command"])
        if why:
            print("UNBOUND: " + why); sys.exit(0)
print("BOUND")
PY
)"
  assert_eq BOUND "$verdict" "every command is bound by its own text"
else
  echo "skip  batch-approval not installed; boundness not checked"
fi

# the labels are read again at publish time: a delivered run can wait days
cp "$FX/issue_security.json" "$M/issue.json"
out="$(bash "$F" batch forge-b 2>&1)"; rc=$?
assert_eq 1 "$rc" "batch refuses an issue that gained the Security label after delivery"
assert_contains "$out" "Security label" "and says why"
cp "$FX/issue_pr.json" "$M/issue.json"
out="$(bash "$F" batch forge-b 2>&1)"; rc=$?
assert_eq 1 "$rc" "batch refuses an issue that already has a PR"
assert_contains "$out" "already has a PR" "and says why"
cp "$FX/issue_ok.json" "$M/issue.json"
printf 'changed\n' >> "$D/closeout.md"
fb forge-b >/dev/null; assert_eq 1 $? "batch refuses a body changed since prepare"
printf 'Closeout.\n' > "$D/closeout.md"
g commit -q --allow-empty -m two
fb forge-b >/dev/null; assert_eq 1 $? "batch refuses a branch that moved since prepare"
bash "$L" set forge-b '.prepared.head = $h | .prepared.files[0] |= del(.role)' --arg h "$(g rev-parse HEAD)"
fb forge-b >/dev/null; assert_eq 1 $? "batch refuses a prepare record without roles"
bash "$L" set forge-b '.prepared.files[0].role = "body" | .decisions += [{key: "pr_title", value: "two\nlines"}]'
fb forge-b >/dev/null; assert_eq 1 $? "batch refuses a PR title with a newline"

finish
