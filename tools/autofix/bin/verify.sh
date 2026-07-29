#!/bin/bash
set -euo pipefail
BIN="$(dirname "${BASH_SOURCE[0]}")"
. "$BIN/lib/common.sh"
require jq
LEDGER="$BIN/ledger.sh"

cmd="${1:?usage: verify.sh signal|lint|suite <run_id> [flags]}"; run_id="${2:?}"; shift 2
branch="$("$LEDGER" get "$run_id" '.branch // empty')"
wt="$(wt_dir "$branch")"

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
#       - `n test-php` / `n test-js` — the repo's own wrapper, restricted to its
#         two test subcommands. `n`'s other subcommands are not reachable, so it
#         can't be turned into an arbitrary-command runner here.
#       - `npx playwright test …` — the EXACT fixed prefix only. Bare `npx`
#         fetches-and-runs arbitrary npm packages, so `npx <anything-else>` is
#         rejected; only Playwright (how the skill records browser repros) is
#         permitted, and it can't install a different package.
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
  case "${EV_ARGV[0]}" in
    n)
      case "${EV_ARGV[1]:-}" in
        test-php|test-js) : ;;
        *) die "evidence cmd not allowed: 'n ${EV_ARGV[1]:-}' (only 'n test-php' / 'n test-js')" ;;
      esac
      ;;
    npx)
      { [ "${EV_ARGV[1]:-}" = playwright ] && [ "${EV_ARGV[2]:-}" = test ]; } \
        || die "evidence cmd not allowed: 'npx' is only permitted as 'npx playwright test …'"
      ;;
    *)
      die "evidence cmd executable not in allowlist: '${EV_ARGV[0]}' (allowed: 'n test-php', 'n test-js', 'npx playwright test')"
      ;;
  esac
}

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
        if out="$( (cd "$wt" && "${EV_ARGV[@]}") 2>&1 )"; then st=pass; else st=fail; fi
        log "evidence[$i] '$ecmd' → $st"
        # Surface the tail of EVERY failing command — including an expected
        # fail. A signal can fail for the wrong reason (run autofix-nppm-273:
        # a missing-dev-deps PHPUnit bootstrap error read as the "failing
        # test"), and a status that matches the expectation would otherwise
        # hide it. The caller must confirm the failure is the assertion.
        if [ "$st" = fail ]; then
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
    base="$(git -C "$wt" merge-base origin/main HEAD)"
    changed="$(git -C "$wt" diff --name-only "$base"...HEAD -- '*.php')"
    [ -n "$changed" ] || { log "no changed PHP files"; exit 0; }
    (cd "$wt" && "$WORKSPACE_ROOT/vendor/bin/phpcs" --standard="$WORKSPACE_ROOT/phpcs.xml" $changed) ;;
  suite)
    [ -d "$wt" ] || die "worktree missing: $wt"
    plugin_dir="$wt/$("$LEDGER" get "$run_id" '.decisions[] | select(.key=="affected_repo") | .value' \
      | sed 's|^|plugins/|')"
    [ -d "$plugin_dir" ] || plugin_dir="$wt"
    (cd "$plugin_dir" && n test-php)
    if jq -e '.scripts["test:js"]' "$plugin_dir/package.json" >/dev/null 2>&1; then
      (cd "$plugin_dir" && n test-js)
    fi ;;
  *) die "unknown subcommand: $cmd" ;;
esac
