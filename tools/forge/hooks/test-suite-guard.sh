#!/bin/bash
# PreToolUse hook for forge run sessions: deny a Bash call that would start a
# whole JS test suite. On 2026-10-31 a run's whole-suite Jest run was going
# when the database container every env shares was OOM-killed, and every env
# went down with it. Jest runs through `verify.sh js`, which runs only the
# tests related to the files given or changed.
#
# This is a tripwire for the commands a run reaches for, not a sandbox: it
# reads command words, so a suite started indirectly (a script that calls
# Jest) passes. For the same reason, input it cannot read is let through
# rather than blocking every Bash call.
set -uo pipefail
set -f
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
verify="$(cd "$here/../../autofix/bin" 2>/dev/null && pwd)/verify.sh"

input="$(cat)"
[ "$(printf '%s' "$input" | jq -r '.tool_name // empty' 2>/dev/null)" = Bash ] || exit 0
cmd="$(printf '%s' "$input" | jq -r '.tool_input.command // empty' 2>/dev/null)" || exit 0
[ -n "$cmd" ] || exit 0

deny() {
  jq -n --arg r "forge: a run may not start a whole JS test suite ($1); one OOM-killed the shared database on 2026-10-31. Run Jest with \`bash $verify js <run id> [file...]\`, which runs only the tests related to those files, or to the branch's changed JS when none are given." \
    '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: $r}}'
  exit 0
}

# judge <word>... — one simple command. Leading assignments and wrappers are
# dropped, then the command word decides.
judge() {
  while [ $# -gt 0 ]; do
    case "$1" in
      *=*) shift ;;
      env|exec|command|time|nice|sudo|xargs) shift ;;
      timeout) shift; [ $# -gt 0 ] && shift ;;
      npx) shift; while [ $# -gt 0 ] && [ "${1#-}" != "$1" ]; do shift; done ;;
      *) break ;;
    esac
  done
  [ $# -gt 0 ] || return 0
  local word="${1##*/}"; shift
  case "$word" in
    jest) deny "jest" ;;
    newspack-scripts) [ "${1:-}" = test ] && deny "newspack-scripts test" ;;
    wp-scripts) [ "${1:-}" = test-unit-js ] && deny "wp-scripts test-unit-js" ;;
    n) [ "${1:-}" = test-js ] && deny "n test-js" ;;
    npm|pnpm|yarn)
      # Drop options, and the value of those that take one. npm's -w names a
      # workspace; pnpm's -w means the workspace root and takes no value.
      while [ $# -gt 0 ]; do
        case "$1" in
          --filter|-F|--prefix|-C|--dir|--workspace) shift; [ $# -gt 0 ] && shift ;;
          -w) shift; [ "$word" = npm ] && [ $# -gt 0 ] && shift ;;
          -*) shift ;;
          *) break ;;
        esac
      done
      case "${1:-}" in run|run-script) shift; while [ $# -gt 0 ] && [ "${1#-}" != "$1" ]; do shift; done ;; esac
      case "${1:-}" in
        # `test` in every package that has Jest tests; `test:js` is the
        # workspace root's, which runs every package's; t and tst are npm's
        # aliases for test.
        test|test:js|t|tst) deny "$word ${1}" ;;
        exec|dlx) shift; judge "$@" ;;
        "") ;;
        # pnpm and yarn run a package binary by name, without exec.
        *) [ "$word" = npm ] || judge "$@" ;;
      esac ;;
    node)
      while [ $# -gt 0 ] && [ "${1#-}" != "$1" ]; do shift; done
      case "${1:-}" in
        */jest.js|jest.js|*/.bin/jest|*/jest/bin/*|*/jest-cli/*) deny "node ${1}" ;;
        */newspack-scripts/scripts/test.js) deny "newspack-scripts test" ;;
        */wp-scripts.js|*/.bin/wp-scripts) [ "${2:-}" = test-unit-js ] && deny "wp-scripts test-unit-js" ;;
      esac ;;
  esac
  return 0
}

# Split on the operators that start a new command, then judge each piece.
while IFS= read -r piece; do
  # shellcheck disable=SC2086  # word-splitting the piece is the point; globbing is off
  judge $piece
done <<EOF
$(printf '%s\n' "$cmd" | sed -E 's/\$\(/\n/g; s/(&&|\|\||[;|&()`])/\n/g')
EOF
exit 0
