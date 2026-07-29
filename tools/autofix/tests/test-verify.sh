#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ./helpers.sh
V=../bin/verify.sh; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/br-1"

# Stub the allowlisted executables (`n`, `npx`) on PATH. `verify.sh signal`
# exec's evidence commands directly (no shell) and only permits
# `n test-php`/`n test-js` and `npx playwright test …`, so tests drive
# pass/fail through a token in the argv: an arg containing PASS → exit 0,
# FAIL → exit 1, otherwise exit 0. Each stub echoes its argv to stdout (so the
# failing-output-tail assertion has something to find) and appends it to
# ARGV_LOG so tests can assert the exact bytes reached the executable —
# proving no shell mangled a backslash and no metacharacter ran a command.
STUB="$(mktemp -d)"; export PATH="$STUB:$PATH"
export ARGV_LOG="$STUB/argv.log"; : > "$ARGV_LOG"
cat > "$STUB/n" <<'EOF'
#!/bin/bash
printf 'n %s\n' "$*" >> "${ARGV_LOG:?}"
printf 'n %s\n' "$*"
for a in "$@"; do case "$a" in *PASS*) exit 0 ;; *FAIL*) exit 1 ;; esac; done
exit 0
EOF
cat > "$STUB/npx" <<'EOF'
#!/bin/bash
printf 'npx %s\n' "$*" >> "${ARGV_LOG:?}"
printf 'npx %s\n' "$*"
for a in "$@"; do case "$a" in *PASS*) exit 0 ;; *FAIL*) exit 1 ;; esac; done
exit 0
EOF
chmod +x "$STUB/n" "$STUB/npx"

bash "$L" init runv NPPM-1 operator-named >/dev/null
bash "$L" set runv '.branch = "br-1"'
bash "$L" evidence runv failing-test t.php 'n test-php --filter FAIL'

bash "$V" signal runv --expect fail
assert_eq 0 $? "--expect fail passes while signal fails"
bash "$V" signal runv --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "--expect pass fails while signal fails"

bash "$L" evidence runv fixed t2.php 'n test-php --filter PASS'
bash "$V" signal runv --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "mixed signals: any failing cmd fails --expect pass"

# missing worktree dir → signal dies fail-closed (never a false pass)
bash "$L" init runw NPPM-2 operator-named >/dev/null
bash "$L" set runw '.branch = "no-such-branch"'
bash "$L" evidence runw failing-test t.php 'n test-php --filter FAIL'
bash "$V" signal runw --expect fail >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "missing worktree: signal dies (--expect fail)"
bash "$V" signal runw --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "missing worktree: signal dies (--expect pass, no false pass)"

# evidence entries all with empty cmd → signal dies (nothing effective ran)
bash "$L" init rune NPPM-3 operator-named >/dev/null
bash "$L" set rune '.branch = "br-1"'
bash "$L" evidence rune note t.php ''
bash "$L" evidence rune note t2.php ''
bash "$V" signal rune --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "all-empty-cmd evidence: signal dies"

# ---- COMMAND-INJECTION regression -------------------------------------------
# Every evidence cmd outside the executable+subcommand allowlist must make
# `signal` die fail-closed, BEFORE any execution. A rejected run leaves ARGV_LOG
# untouched (the executable was never invoked).
reject_case() { # label cmd
  local label="$1" cmd="$2" rid rc
  rid="rej-$(printf '%s' "$label" | tr -cd 'a-z0-9')"
  bash "$L" init "$rid" NPPM-R operator-named >/dev/null
  bash "$L" set "$rid" '.branch = "br-1"'
  bash "$L" evidence "$rid" failing-test t.php "$cmd"
  : > "$ARGV_LOG"
  bash "$V" signal "$rid" --expect fail >/dev/null 2>&1 && rc=0 || rc=$?
  assert_eq 1 "$rc" "rejected (die): $label"
  assert_eq "" "$(cat "$ARGV_LOG")" "rejected cmd never executed: $label"
}
reject_case "npx-arbitrary-package"  'npx evil-package'
reject_case "npx-wrong-subcommand"   'npx playwright install'
reject_case "node-require-flag"      'node -r /tmp/evil.js'
reject_case "node-eval-flag"         'node -e process.exit(0)'
reject_case "bare-node-script"       'node evil.js'
reject_case "n-non-test-subcommand"  'n build newspack-plugin'
reject_case "semicolon-glued-subcmd" 'n test-php; rm -rf x'
reject_case "bare-executable"        'curl http://evil/x'

# No-shell proof: an allowlisted `n test-php …` whose args carry a `; touch …`
# suffix is accepted (argv[1] is test-php) and RUNS, but the `touch` never
# executes — there is no shell, so `;` and the filename are literal argv.
marker="$STUB/pwned-$$"
rm -f "$marker"
bash "$L" init runinj NPPM-I operator-named >/dev/null
bash "$L" set runinj '.branch = "br-1"'
bash "$L" evidence runinj failing-test t.php "n test-php --filter FAIL; touch $marker"
bash "$V" signal runinj --expect fail
assert_eq 0 $? "injection suffix: runs as literal argv, still a fail signal"
[ -e "$marker" ] && present=yes || present=no
assert_eq no "$present" "injection suffix: no shell ran the 'touch' side effect"

# Legitimate backslashed PHPUnit FQCN filter is ACCEPTED and runs; the backslash
# reaches the executable literally (word-split has no shell backslash handling).
: > "$ARGV_LOG"
bash "$L" init runbs NPPM-B operator-named >/dev/null
bash "$L" set runbs '.branch = "br-1"'
bash "$L" evidence runbs fixed t.php 'n test-php --filter Newspack\Data_Events::test_PASS'
bash "$V" signal runbs --expect pass
assert_eq 0 $? "backslashed FQCN filter accepted and passes"
assert_contains "$(cat "$ARGV_LOG")" 'Newspack\Data_Events::test_PASS' \
  "backslash in filter reaches executable literally"

# Comma-separated group list is ACCEPTED (comma is literal argv, not a shell op).
: > "$ARGV_LOG"
bash "$L" init runcs NPPM-C operator-named >/dev/null
bash "$L" set runcs '.branch = "br-1"'
bash "$L" evidence runcs fixed t.php 'n test-php --group byline-block,data-events-PASS'
bash "$V" signal runcs --expect pass
assert_eq 0 $? "comma-separated --group list accepted and passes"
assert_contains "$(cat "$ARGV_LOG")" 'byline-block,data-events-PASS' \
  "comma in group list reaches executable literally"

# Playwright repro: the exact fixed prefix is ACCEPTED and runs.
: > "$ARGV_LOG"
bash "$L" init runpw NPPM-P operator-named >/dev/null
bash "$L" set runpw '.branch = "br-1"'
bash "$L" evidence runpw playwright-repro r.spec.js 'npx playwright test specs/repro_PASS.spec.js'
bash "$V" signal runpw --expect pass
assert_eq 0 $? "npx playwright test <spec> accepted and passes"
assert_contains "$(cat "$ARGV_LOG")" 'npx playwright test specs/repro_PASS.spec.js' \
  "playwright prefix reaches npx"

# CRITICAL 1 regression: a branch containing '/' (a Linear branchName like
# "jason/nppm-1-fix") lives at the SANITIZED path on disk — `n` runs
# safe_branch=$(tr '/' '-') when it names the worktree dir — not at a naive
# WORKSPACE_ROOT/worktrees/<raw-branch> join.
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/jason-nppm-1-fix"
bash "$L" init runslash NPPM-9 operator-named >/dev/null
bash "$L" set runslash '.branch = "jason/nppm-1-fix"'
bash "$L" evidence runslash failing-test t.php 'n test-php --filter FAIL'
bash "$V" signal runslash --expect fail
assert_eq 0 $? "slashed branch: signal finds worktree at the sanitized (dash) path"

# Wrong-reason visibility: the tail of every failing evidence cmd is surfaced,
# even when fail is the EXPECTED status (run nppm-273 bootstrap-error lesson)
bash "$L" init runtail NPPM-3 operator-named >/dev/null
bash "$L" set runtail '.branch = "br-1"'
bash "$L" evidence runtail failing-test t.php 'n test-php --filter BOOTSTRAP-MARKER-FAIL'
out="$(bash "$V" signal runtail --expect fail 2>&1)" && rc=0 || rc=$?
assert_eq 0 "$rc" "expected fail still passes the check"
assert_contains "$out" "BOOTSTRAP-MARKER" "failing cmd output tail surfaced on expected fail"

# lint smoke: worktree repo with no changed PHP files vs merge-base → exit 0
WT="$AUTOFIX_WORKSPACE_ROOT/worktrees/br-1"
git -C "$WT" init -q
( cd "$WT" && echo hi > readme.txt && git add readme.txt \
  && git -c user.email=t@example.com -c user.name=t commit -qm init \
  && git update-ref refs/remotes/origin/main HEAD )
bash "$V" lint runv >/dev/null 2>&1
assert_eq 0 $? "lint: no changed PHP files exits 0"

# suite smoke: n test-php from plugins/<affected_repo>; no test-js without script
# Uses its own stub dir (prepended to PATH) so it shadows the signal stubs above;
# every signal-based assertion must therefore come BEFORE this point.
SUITE_STUB="$(mktemp -d)"; export PATH="$SUITE_STUB:$PATH"
cat > "$SUITE_STUB/n" <<'EOF'
#!/bin/bash
echo "$PWD n $*" >> "${N_LOG:?}"
exit 0
EOF
chmod +x "$SUITE_STUB/n"
export N_LOG="$SUITE_STUB/n.log"; : > "$N_LOG"
bash "$L" set runv '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
mkdir -p "$WT/plugins/my-plugin"
printf '{"scripts":{"test":"noop"}}\n' > "$WT/plugins/my-plugin/package.json"
bash "$V" suite runv >/dev/null 2>&1
assert_eq 0 $? "suite exits 0"
assert_contains "$(cat "$N_LOG")" "plugins/my-plugin n test-php" "n test-php runs from plugin dir"
assert_eq 0 "$(grep -c 'test-js' "$N_LOG")" "n test-js not invoked without test:js script"
finish
