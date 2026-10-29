#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ./helpers.sh
L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
pf() { ( . ../bin/lib/common.sh; . ../bin/lib/profile.sh; "$@" ) 2>/dev/null; }

bash "$L" init p1 NPPM-1 operator-named >/dev/null
assert_eq newspack-workspace "$(pf profile_name p1)" "no decision → newspack-workspace profile"
assert_eq origin/main "$(pf profile_get p1 .base.ref)" "default base is origin/main"
assert_eq true "$(pf profile_get p1 .env)" "default profile has an env"

# default path scope is exactly today's: plugins/<unit>/ and themes/<unit>/
paths=$'plugins/newspack-plugin/a.php\nthemes/newspack-plugin/b.css\nplugins/other/c.php\nbin/x.sh'
assert_eq $'plugins/other/c.php\nbin/x.sh' "$(printf '%s\n' "$paths" | pf profile_offending_paths p1 newspack-plugin)" "default scope = affected unit only"

# an entry without a trailing slash matches that exact path only
bash "$L" init p2 NPPM-2 forge >/dev/null
bash "$L" set p2 '.decisions += [{key:"profile", value:"newspack-workspace-tooling"}]'
paths=$'n\nnode_modules/x\nn2\ntools/autofix/bin/pr.sh\nplugins/newspack-plugin/a.php'
assert_eq $'node_modules/x\nn2\nplugins/newspack-plugin/a.php' "$(printf '%s\n' "$paths" | pf profile_offending_paths p2 "")" "exact entry 'n' admits only 'n'"
assert_eq main "$(pf profile_get p2 .base.ref)" "tooling profile bases on local main"
assert_eq fork "$(pf profile_get p2 .push_remote)" "tooling profile pushes to the fork"

# commands: exact entry, or the entry plus arguments; nothing else
pf profile_allows_cmd p2 "bash tools/autofix/tests/run-tests.sh"; assert_eq 0 $? "exact command allowed"
pf profile_allows_cmd p2 "bash tools/autofix/tests/run-tests.sh test-pr.sh"; assert_eq 0 $? "command + args allowed"
pf profile_allows_cmd p2 "bash tools/autofix/tests/run-tests.shx" && rc=0 || rc=$?
assert_eq 1 "$rc" "prefix without a space is not allowed"
pf profile_allows_cmd p1 "bash anything" && rc=0 || rc=$?
assert_eq 1 "$rc" "default profile allows no extra commands"

# a drafted (unkept) profile is never read
bash "$L" init p3 NPPM-3 forge >/dev/null
bash "$L" set p3 '.decisions += [{key:"profile", value:"drafted-reprise"}]'
out="$( ( . ../bin/lib/common.sh; . ../bin/lib/profile.sh; profile_get p3 .base.ref ) 2>&1 )" && rc=0 || rc=$?
assert_eq 1 "$rc" "unkept profile → profile_get dies"
assert_contains "$out" "not used until the operator keeps it" "die message says why"

# I1: a non-default profile is forge-only — an autofix or secure run that
# records one must not get the tooling profile's fork remote and wide scope
bash "$L" init p4 NPPM-4 operator-named >/dev/null
bash "$L" set p4 '.decisions += [{key:"profile", value:"newspack-workspace-tooling"}]'
out="$( ( . ../bin/lib/common.sh; . ../bin/lib/profile.sh; profile_get p4 .push_remote ) 2>&1 )" && rc=0 || rc=$?
assert_eq 1 "$rc" "autofix run cannot select a non-default profile"
assert_contains "$out" "forge-only" "refusal says non-default profiles are forge-only"
bash "$L" init p5 NPPM-5 secure >/dev/null
bash "$L" set p5 '.decisions += [{key:"profile", value:"newspack-workspace-tooling"}]'
( . ../bin/lib/common.sh; . ../bin/lib/profile.sh; profile_get p5 .push_remote ) >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "secure run cannot select a non-default profile"

# I3: affected_repo is substituted literally, never as sed or regex syntax
paths=$'tools/autofix/bin/pr.sh\nplugins/x#;s#.*#tools/#;s#y#z/a.php'
assert_eq "tools/autofix/bin/pr.sh" "$(printf '%s\n' "$paths" | pf profile_offending_paths p1 'x#;s#.*#tools/#;s#y#z')" "crafted affected_repo cannot rewrite the scope"

# the tooling profile runs the forge's suite as well as autofix's
assert_contains "$(jq -r '."newspack-workspace-tooling".suite[]' ../profiles.json)" "bash tools/forge/tests/run-tests.sh" "tooling suite includes the forge tests"
assert_contains "$(jq -r '."newspack-workspace-tooling".commands[]' ../profiles.json)" "bash tools/forge/tests/run-tests.sh" "and allows them as a command"

finish
