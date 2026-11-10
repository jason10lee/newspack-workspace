#!/bin/bash
set -euo pipefail
BIN="$(dirname "${BASH_SOURCE[0]}")"
. "$BIN/lib/common.sh"
. "$BIN/lib/profile.sh"
require jq
LEDGER="$BIN/ledger.sh"

cmd="${1:?usage: verify.sh signal|lint|suite|js <run_id> [flags]}"; run_id="${2:?}"; shift 2
wt="$(run_wt "$run_id")"
affected_repo() { ledger_decision "$run_id" affected_repo; }

# parse_evidence_argv <cmd-string> — turn a ledger `.evidence[].cmd` into the
# argv it will be exec'd as, and enforce the executable + subcommand allowlist.
# Result is placed in the global EV_ARGV[] array. Dies (fail-closed) on anything
# outside the allowlist.
#
# Why this is safe against the command-injection finding:
#   * NO shell ever evaluates the string. It is word-split on spaces with
#     globbing disabled (`set -f`), so shell metacharacters that appear INSIDE
#     an argument — `;` `|` `&` `$` backtick `(` `)` `<` `>` `*` `\` `,` — are
#     literal argv bytes, never operators. The argv is later exec'd directly
#     (`"${EV_ARGV[@]}"`), never via `bash -c`.
#   * The executable (argv[0]) must be one of a *closed* set that contains NO
#     general-purpose code runner:
#       - `n test-php` — the repo's own wrapper, restricted to that test
#         subcommand. `n`'s other subcommands are not reachable, so it can't be
#         turned into an arbitrary-command runner here. `n test-js` is refused:
#         it always runs the package's whole Jest suite (why that matters:
#         see "A suite guard" in tools/forge/README.md).
#       - `jest-related <file>…` — Jest on the tests related to those files,
#         through lib/jest-related.js from the unit directory. It is rewritten
#         to that fixed script, so it reaches no other code.
#       - `npx playwright test …` — the EXACT fixed prefix only, and npx never
#         runs it. Where no local Playwright resolves (a worktree has no
#         node_modules of its own), npx downloads the latest Playwright from the
#         registry and runs that. So it runs the workspace's own
#         @playwright/test CLI under node, at the version lib/playwright-env.sh
#         finds, with CHROME_PATH exported for the spec. See EV_PW_TEST below
#         for where it runs and how its result is read.
#     `node`, `npx <other>`, `n <other>`, and every other executable are
#     rejected. Because backslash/comma survive as literal argv, a legitimate
#     PHPUnit filter like `n test-php --filter Namespace\Class::method` or a
#     comma-separated `--group a,b` is accepted unchanged — no charset filter is
#     needed or applied beyond rejecting raw newlines (multi-line smuggling).
parse_evidence_argv() { # cmd-string
  local ecmd="$1"
  case "$ecmd" in
    *$'\n'*|*$'\r'*) die "evidence cmd contains a newline; refusing to run: $ecmd" ;;
  esac
  local IFS=' '
  set -f
  # Intentional unquoted split into argv; `set -f` disables globbing and
  # IFS=' ' splits on spaces only. No other shell evaluation occurs, so a
  # backslash/comma inside a filter argument is preserved literally.
  # shellcheck disable=SC2206
  EV_ARGV=( $ecmd )
  set +f
  [ "${#EV_ARGV[@]}" -gt 0 ] || die "empty evidence cmd after parsing"
  EV_IN_UNIT=0; EV_PW_TEST=""
  case "${EV_ARGV[0]}" in
    n)
      case "${EV_ARGV[1]:-}" in
        test-php) EV_IN_UNIT=1 ;;
        test-js) die "evidence cmd not allowed: 'n test-js' runs the whole Jest suite; use 'jest-related <file>…'" ;;
        *) die "evidence cmd not allowed: 'n ${EV_ARGV[1]:-}' (only 'n test-php', or 'jest-related <file>…' for Jest)" ;;
      esac
      ;;
    jest-related)
      [ "${#EV_ARGV[@]}" -gt 1 ] || die "evidence cmd not allowed: 'jest-related' needs at least one file"
      EV_ARGV=( node "$AUTOFIX_BIN_DIR/lib/jest-related.js" "${EV_ARGV[@]:1}" ); EV_IN_UNIT=1
      ;;
    npx)
      { [ "${EV_ARGV[1]:-}" = playwright ] && [ "${EV_ARGV[2]:-}" = test ]; } \
        || die "evidence cmd not allowed: 'npx' is only permitted as 'npx playwright test …'"
      # CHROME_PATH stays global: it has to reach the command, which runs
      # after this function returns.
      local pwenv PW_PATH="" a; CHROME_PATH=""
      pwenv="$(AUTOFIX_WORKSPACE_ROOT="$WORKSPACE_ROOT" bash "$AUTOFIX_BIN_DIR/lib/playwright-env.sh")" \
        || die "evidence cmd needs the workspace's Playwright, and none is usable (see above); not running it"
      eval "$pwenv"
      for a in "${EV_ARGV[@]:3}"; do
        case "$a" in --reporter*) die "evidence cmd may not set --reporter: verify.sh reads the result from its own JSON report" ;; esac
      done
      EV_PW_TEST="$WORKSPACE_ROOT/node_modules/.pnpm/@playwright+test@${PW_PATH##*/playwright@}"
      EV_PW_TEST="${EV_PW_TEST%%/node_modules/playwright}/node_modules/@playwright/test"
      [ -f "$EV_PW_TEST/cli.js" ] || die "no @playwright/test CLI at $EV_PW_TEST/cli.js; not running '$ecmd'"
      export CHROME_PATH
      EV_ARGV=( node "$EV_PW_TEST/cli.js" "${EV_ARGV[@]:2}" "--reporter=line,json" )
      ;;
    *)
      # A kept profile may name further commands (a tooling repo's own test
      # runner). The ledger's .evidence[].cmd must match one exactly, or
      # extend it with arguments; nothing else runs.
      profile_allows_cmd "$run_id" "$ecmd" \
        || die "evidence cmd executable not in allowlist: '${EV_ARGV[0]}' (allowed: 'n test-php', 'jest-related <file>…', 'npx playwright test', or a command the run's profile names)"
      ;;
  esac
}

# Resolve the profile once, up front, so an unkept profile stops the run
# before any command runs.
profile_get "$run_id" '.base.ref' >/dev/null

case "$cmd" in
  signal)
    [ -d "$wt" ] || die "worktree missing: $wt"
    [ "${1:-}" = "--expect" ] || die "signal requires --expect pass|fail"
    expect="${2:?pass|fail}"
    count="$("$LEDGER" get "$run_id" '.evidence | length')"
    effective="$("$LEDGER" get "$run_id" '[.evidence[] | select(.cmd != null and .cmd != "")] | length')"
    [ "$effective" -gt 0 ] || die "no effective evidence commands to run"
    i=0; bad=0; ran=0
    while [ "$i" -lt "$count" ]; do
      ecmd="$("$LEDGER" get "$run_id" ".evidence[$i].cmd")"
      if [ -n "$ecmd" ] && [ "$ecmd" != "null" ]; then
        # Validate + word-split BEFORE running so a rejection dies visibly.
        # Never a shell — exec argv directly.
        parse_evidence_argv "$ecmd"
        # `n` and Jest resolve their project from the cwd. Playwright runs from
        # the run dir (below); a profile's own commands run from the worktree.
        run_dir="$wt"
        if [ "$EV_IN_UNIT" = 1 ]; then run_dir="$(project_dir "$wt" "$(affected_repo)")" || exit 1; fi
        report=""
        if [ -n "$EV_PW_TEST" ]; then
          # A Playwright repro lives under the run dir, and an ESM import
          # resolves by walking up from the spec, so the run dir gets a link to
          # the workspace's @playwright/test. Running from there also keeps its
          # default output out of the worktree.
          run_dir="$RUNS_DIR/$run_id"
          link="$run_dir/node_modules/@playwright/test"
          if [ -e "$link" ] && [ ! -L "$link" ]; then die "$link exists and is not a link; not replacing it"; fi
          mkdir -p "$run_dir/node_modules/@playwright" && ln -sfn "$EV_PW_TEST" "$link"
          report="$run_dir/signal-$i.json"; rm -f "$report"
        fi
        rc=0
        if out="$( (cd "$run_dir" && PLAYWRIGHT_JSON_OUTPUT_FILE="$report" "${EV_ARGV[@]}") 2>&1 )"; then st=pass; else rc=$?; st=fail
          # 126 and 127 mean the command itself could not run, not that a test
          # failed, so they never count as the red a --expect fail wants.
          case "$rc" in 126|127) st=error ;; esac
        fi
        if [ -n "$report" ]; then
          # Playwright exits 1 for a failed test, a spec that could not load
          # and "No tests found" alike. Its JSON report tells them apart: a
          # result counts only when a test ran and nothing failed to load. A
          # report that reads green from a run Playwright exited non-zero (a
          # flaky pass under --fail-on-flaky-tests) is no result either. Nor is
          # a browser that never started: Playwright reports it as a failed
          # test, and it says nothing about the code under test.
          st="$(jq -r 'if (.errors | length) > 0 or ((.stats.expected // 0) + (.stats.unexpected // 0) + (.stats.flaky // 0)) == 0 then "error"
                       elif [.. | objects | select(has("message")) | .message | strings | select(test("browserType\\.launch"))] | length > 0 then "launch"
                       elif .stats.unexpected > 0 then "fail" else "pass" end' "$report" 2>/dev/null)" || st=error
          [ -n "$st" ] || st=error
          if [ "$st" = pass ] && [ "$rc" != 0 ]; then st=error; fi
          rc="report"
          if [ "$st" = launch ]; then st=error; rc="launch"; fi
        fi
        why="exit $rc"
        if [ "$rc" = report ]; then why="no test ran, one failed to load, or Playwright failed a passing run"
        elif [ "$rc" = launch ]; then why="the browser did not launch; launch it with executablePath: process.env.CHROME_PATH"; fi
        if [ "$st" = error ]; then log "evidence[$i] '$ecmd' → could not run ($why)"
        else log "evidence[$i] '$ecmd' → $st"; fi
        # Surface the tail of EVERY failing command — including an expected
        # fail. A signal can fail for the wrong reason (run autofix-nppm-273:
        # a missing-dev-deps PHPUnit bootstrap error read as the "failing
        # test"), and a status that matches the expectation would otherwise
        # hide it. The caller must confirm the failure is the assertion.
        if [ "$st" != pass ]; then
          printf '%s\n' "$out" | tail -n 5 | sed 's/^/[autofix]   | /' >&2
        fi
        [ "$st" = "$expect" ] || bad=1
        ran=$((ran+1))
      fi
      i=$((i+1))
    done
    [ "$ran" -gt 0 ] || die "no effective evidence commands ran"
    [ "$bad" = 0 ] || { log "signal check failed (expected all to $expect)"; exit 1; }
    log "all signals $expect as expected" ;;
  lint)
    [ -d "$wt" ] || die "worktree missing: $wt"
    if [ "$(profile_get "$run_id" '.lint')" = none ]; then log "lint: none for profile $(profile_name "$run_id")"; exit 0; fi
    base="$(git -C "$wt" merge-base "$(profile_get "$run_id" '.base.ref')" HEAD)"
    # Diff the working tree, not HEAD, and add untracked files: lint runs
    # before the first commit too, and then a HEAD-only diff is empty.
    changed="$( { git -C "$wt" diff --name-only --diff-filter=d "$base" -- '*.php'
                  git -C "$wt" ls-files --others --exclude-standard -- '*.php'; } | sort -u)"
    [ -n "$changed" ] || { log "no changed PHP files"; exit 0; }
    (cd "$wt" && "$WORKSPACE_ROOT/vendor/bin/phpcs" --standard="$WORKSPACE_ROOT/phpcs.xml" $changed) ;;
  suite)
    [ -d "$wt" ] || die "worktree missing: $wt"
    if [ "$(profile_get "$run_id" '.suite')" != null ]; then
      suite_cmds="$(profile_get "$run_id" '.suite[]')"
      while IFS= read -r scmd; do
        [ -n "$scmd" ] || continue
        parse_evidence_argv "$scmd"
        # </dev/null: the loop reads its list on stdin, and a command that
        # reads stdin would otherwise swallow the entries after it.
        (cd "$wt" && "${EV_ARGV[@]}" </dev/null) || exit 1
      done <<EOF
$suite_cmds
EOF
      exit 0
    fi
    plugin_dir="$(project_dir "$wt" "$(affected_repo)")" || exit 1
    # A unit with no PHPUnit config has no PHP suite to run, and `n test-php`
    # there fails, which would read as a red suite on a change that broke nothing.
    if [ -f "$plugin_dir/phpunit.xml" ] || [ -f "$plugin_dir/phpunit.xml.dist" ]; then
      (cd "$plugin_dir" && n test-php)
    else
      log "suite: $(affected_repo) has no phpunit.xml, so no PHP tests"
    fi
    # Jest runs only on tests related to the changed files, never the whole
    # suite (see "A suite guard" in tools/forge/README.md). Packages without JS tests carry an `echo`
    # placeholder as their `test` script, and are skipped.
    if jq -e '(.scripts.test // "") | length > 0 and (startswith("echo ") | not)' \
         "$plugin_dir/package.json" >/dev/null 2>&1; then
      bash "$AUTOFIX_BIN_DIR/verify.sh" js "$run_id"
    fi ;;
  js)
    # js <run_id> [file...] — Jest on the host, one worker, limited to tests
    # related to the given files, or to the JS and TS files the branch changed
    # in the affected unit (committed, uncommitted and untracked). With none,
    # it runs nothing.
    [ -d "$wt" ] || die "worktree missing: $wt"
    plugin_dir="$(project_dir "$wt" "$(affected_repo)")" || exit 1
    if [ $# -gt 0 ]; then files=("$@")
    else
      base="$(git -C "$wt" merge-base "$(profile_get "$run_id" '.base.ref')" HEAD)"
      files=()
      while IFS= read -r f; do [ -n "$f" ] && files+=("$f"); done <<EOF
$( { git -C "$plugin_dir" diff --relative --name-only --diff-filter=d "$base" -- '*.js' '*.jsx' '*.ts' '*.tsx' '*.mjs' '*.cjs'
     git -C "$plugin_dir" ls-files --others --exclude-standard -- '*.js' '*.jsx' '*.ts' '*.tsx' '*.mjs' '*.cjs'; } | sort -u)
EOF
    fi
    [ "${#files[@]}" -gt 0 ] || { log "js: no changed JS or TS files, so no Jest run"; exit 0; }
    (cd "$plugin_dir" && node "$AUTOFIX_BIN_DIR/lib/jest-related.js" "${files[@]}") ;;
  *) die "unknown subcommand: $cmd" ;;
esac
