#!/bin/bash
# The skills name commands and paths that the scripts own. These checks fail
# when a skill names a command or a file that does not exist, so a rename in
# the scripts cannot leave a skill sending runs to nothing.
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh
ROOT="$(cd ../../.. && pwd)"
RUN="$ROOT/.claude/skills/forge/SKILL.md"
DIS="$ROOT/.claude/skills/forge-dispatch/SKILL.md"
labels() { LC_ALL=C sed -nE 's/^  ([a-z|-]+)\).*/\1/p' "$1" | tr '|' '\n'; }
check_cmds() { # skill pattern script label
  local c bad=""
  for c in $(LC_ALL=C grep -oE "$2 [a-z][a-z-]*" "$1" | awk '{print $NF}' | sort -u); do
    labels "$3" | grep -qx "$c" || bad="$bad $c"
  done
  assert_eq "" "$bad" "$4"
}
check_paths() { # skill label — every $T/... path, and every tools/... path, exists
  local p bad=""
  for p in $( { LC_ALL=C grep -oE '\$T/[A-Za-z0-9_./-]+' "$1" | sed 's#^\$T/##'
               LC_ALL=C grep -oE '(^|[ `(])tools/[A-Za-z0-9_./-]+' "$1" | sed -E 's#^[ `(]##'; } \
             | sed -E 's/[.,]+$//' | sort -u); do
    [ -e "$ROOT/$p" ] || bad="$bad $p"
  done
  assert_eq "" "$bad" "$2"
}
first_line() { LC_ALL=C grep -nF -- "$2" "$1" | head -1 | cut -d: -f1; }

# the run skill
assert_eq yes "$([ -f "$RUN" ] && echo yes || echo no)" "the run skill exists where the brief points"
assert_eq "name: forge" "$(sed -n 2p "$RUN")" "its frontmatter names it forge"
check_cmds "$RUN" "forge-run" "$ROOT/tools/forge/bin/forge-run" "every forge-run command the run skill names exists"
check_cmds "$RUN" '`forge' "$ROOT/tools/forge/bin/forge" "every forge command the run skill names exists"
check_paths "$RUN" "every path the run skill names exists"
assert_eq "0 1 2 3 4 5 6 7 8" "$(LC_ALL=C sed -nE 's/^## Stage ([0-8]):.*/\1/p' "$RUN" | tr '\n' ' ' | sed 's/ $//')" "stages 0 to 8 appear in order"
r="$(first_line "$RUN" "### Reading the fresh test")"
order=""; for k in "**Code defect**" "**Not checkable locally**" "**Steps wrong**" "**Retest**" "**Every scenario ok**"; do
  order="$order $(awk -v from="$r" -v k="$k" 'NR > from && index($0, k) { print NR; exit }' "$RUN")"
done
assert_eq "$(printf '%s\n' $order | sort -n | tr '\n' ' ')" "$(printf '%s\n' $order | tr '\n' ' ')" "the fresh-test readings are acted on in the spec's order"
assert_eq 5 "$(printf '%s\n' $order | grep -c .)" "and all five are there"
for h in "## Contested decisions" "## Friction" "## Choices"; do
  assert_eq yes "$(grep -qx "$h" "$RUN" && echo yes || echo no)" "the packet template has the '$h' heading deliver requires"
done
assert_contains "$(cat "$RUN")" "600000" "the run skill sets long Bash timeouts"

# the dispatcher skill
assert_eq yes "$([ -f "$DIS" ] && echo yes || echo no)" "the dispatcher skill exists"
assert_eq "name: forge-dispatch" "$(sed -n 2p "$DIS")" "its frontmatter names it forge-dispatch"
check_cmds "$DIS" "bin/forge" "$ROOT/tools/forge/bin/forge" "every forge command the dispatcher skill names exists"
check_paths "$DIS" "every path the dispatcher skill names exists"
assert_contains "$(cat "$DIS")" "600000" "the dispatcher skill sets long Bash timeouts"
assert_contains "$(cat "$DIS")" "batch-approval preview" "publishing goes through a batch preview"
README="$ROOT/tools/forge/README.md"
for c in $(labels "$ROOT/tools/forge/bin/forge-run"); do
  assert_contains "$(cat "$README")" "\`forge-run $c" "the README describes 'forge-run $c'"
done
for c in $(labels "$ROOT/tools/forge/bin/forge"); do
  assert_contains "$(cat "$README")" "\`forge $c" "the README describes 'forge $c'"
done
assert_contains "$(cat "$RUN")" "forge-run notify" "the run skill says how to reach the operator while waiting"
assert_contains "$(cat "$RUN")" "re-read every section of the packet" "a rework re-reads its packet before delivering again"
assert_contains "$(cat "$DIS")" "STALE-MARKED" "the dispatcher skill explains a stale mark"
# A published run can't be reworked, so it gives up its pool directory at once
# rather than holding it until the PR merges.
pub="$(sed -n '/^## Publish/,$p' "$DIS")"
assert_contains "$pub" "forge retire <RID>" "publishing ends by retiring the run"
assert_eq 0 "$(grep -c 'once the PR merges or closes' "$DIS")" "and no longer waits for the PR to merge"
# Retiring drops the busy guard; until Linear shows the PR, a relaunch could
# start a second run on the issue.
i0="$(grep -n 'intake.sh check <ISSUE>' <<<"$pub" | head -1 | cut -d: -f1)"
r0="$(grep -n 'forge retire <RID>' <<<"$pub" | head -1 | cut -d: -f1)"
assert_eq yes "$([ -n "$i0" ] && [ -n "$r0" ] && [ "$i0" -le "$r0" ] && grep -q 'intake.sh check <ISSUE>.*forge retire <RID>' <<<"$pub" && echo yes || echo no)" "it checks that Linear shows the PR before retiring"
assert_contains "$pub" "every call in the batch has succeeded" "and retires only after the whole batch succeeded"
r0="$(first_line "$RUN" "forge-run claim \$RID")"; r1="$(first_line "$RUN" "If it printed \`FRESH_TEST_IN_PROGRESS=true\`")"
assert_eq yes "$([ -n "$r0" ] && [ -n "$r1" ] && [ "$r0" -lt "$r1" ] && echo yes || echo no)" "Stage 0 claims before it restores an interrupted fresh test"

finish
