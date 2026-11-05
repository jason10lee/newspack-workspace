#!/bin/bash
# PreToolUse hook for forge run sessions, and the review sessions they start:
# deny a Bash call that would start a whole JS test suite, and name
# `verify.sh js`, which runs only the tests related to the given or changed
# files. Why a whole suite is the problem: see "A suite guard" in
# tools/forge/README.md.
#
# It catches the usual ways of starting a suite, not every way. It reads
# command words after dropping quotes, so a script that starts Jest itself, or
# a command this cannot pick apart, passes. Input it cannot read passes too,
# rather than blocking every Bash call. Nothing here limits the database's
# memory.
set -uo pipefail
set -f
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
verify="$(cd "$here/../../autofix/bin" 2>/dev/null && pwd)/verify.sh"

input="$(cat)"
[ "$(printf '%s' "$input" | jq -r '.tool_name // empty' 2>/dev/null)" = Bash ] || exit 0
cmd="$(printf '%s' "$input" | jq -r '.tool_input.command // empty' 2>/dev/null)" || exit 0
[ -n "$cmd" ] || exit 0

deny() {
  jq -n --arg r "forge: a run may not start a whole JS test suite ($1), which can exhaust the memory of the database container every env shares. Run Jest with \`bash $verify js ${FORGE_RUN_ID:-<run id>} [file...]\`, with paths relative to the plugin. It runs only the tests related to those files, or to the branch's changed JS when none are given." \
    '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: $r}}'
  exit 0
}

# judge <word>... — one simple command. Assignments, shell keywords and
# wrappers in front are dropped, with their options, then the command word
# decides. Every option that takes a value drops one more word, with a
# check, because `shift 2` on one word shifts nothing and would loop forever.
judge() {
  while [ $# -gt 0 ]; do
    case "$1" in
      *=*|--|'!'|'{'|do|then|else|elif|if|while|until|exec|command|time|nohup|sudo) shift ;;
      env|nice|timeout|xargs|npx)
        local w="$1"; shift
        while [ $# -gt 0 ]; do
          case "$w:$1" in
            env:-u|env:--unset|env:-C|env:--chdir|nice:-n|nice:--adjustment|\
            timeout:-k|timeout:-s|timeout:--kill-after|timeout:--signal|\
            xargs:-n|xargs:-I|xargs:-L|xargs:-P|xargs:-d|xargs:-s|npx:-p|npx:--package)
              shift; [ $# -gt 0 ] && shift ;;
            *:-*) shift ;;
            *) break ;;
          esac
        done
        [ "$w" = timeout ] && [ $# -gt 0 ] && shift ;;  # the duration
      *) break ;;
    esac
  done
  [ $# -gt 0 ] || return 0
  local word="${1##*/}"; shift
  case "$word" in
    jest) deny "jest" ;;
    newspack-scripts) [ "${1:-}" = test ] && deny "newspack-scripts test" ;;
    wp-scripts) [ "${1:-}" = test-unit-js ] && deny "wp-scripts test-unit-js" ;;
    n)
      case "${1:-}" in
        test-js) deny "n test-js" ;;
        # n hands these to the package manager in the container, after an
        # optional project name. Judged with and without the word that might
        # be one, so `n npm newspack-plugin test` is caught as `npm test`.
        npm|npx|pnpm|yarn) judge "$@"; [ $# -gt 1 ] && judge "$1" "${@:3}" ;;
      esac ;;
    bash|sh|zsh|dash)
      # With -c (alone or among other letters, as in -lc), the words after
      # it are the command string; without it, the first word is a script.
      while [ $# -gt 0 ]; do
        case "$1" in
          --*) shift ;;
          -*c*) shift; judge "$@"; return 0 ;;
          -*) shift ;;
          *) return 0 ;;
        esac
      done ;;
    npm|pnpm|yarn)
      # Drop options, and the value of those that take one, before and after
      # `run`. npm's -w names a workspace; pnpm's -w means the workspace root
      # and takes no value.
      local ran=0
      while [ $# -gt 0 ]; do
        case "$1" in
          --filter|-F|--prefix|-C|--dir|--cwd|--workspace) shift; [ $# -gt 0 ] && shift ;;
          -w) shift; [ "$word" = npm ] && [ $# -gt 0 ] && shift ;;
          -*) shift ;;
          run|run-script) [ "$ran" = 0 ] || break; ran=1; shift ;;
          *) break ;;
        esac
      done
      case "${1:-}" in
        # `test` in every package that has Jest tests; `test:js` is the
        # workspace root's, which runs every package's; t and tst are npm's
        # aliases for test.
        test|test:js|t|tst) deny "$word ${1}" ;;
        "") ;;
        exec|dlx|x) [ "$ran" = 0 ] && { shift; judge "$@"; } ;;
        # pnpm and yarn run a package binary by name, without exec.
        *) [ "$ran" = 0 ] && [ "$word" != npm ] && judge "$@" ;;
      esac ;;
    node)
      while [ $# -gt 0 ]; do
        case "$1" in
          -e|--eval|-p|--print) return 0 ;;
          -r|--require|--import|--loader) shift; [ $# -gt 0 ] && shift ;;
          -*) shift ;;
          *) break ;;
        esac
      done
      [ $# -gt 0 ] || return 0
      case "$1" in */newspack-scripts/scripts/test.js) deny "newspack-scripts test" ;; esac
      # A package binary started by path is judged by its name:
      # node_modules/.bin/jest, jest/bin/jest.js and wp-scripts.js among them.
      local script="${1##*/}"; shift
      script="${script%.js}"; script="${script%.cjs}"; script="${script%.mjs}"
      judge "$script" "$@" ;;
  esac
  return 0
}

# Drop quotes, so a command string given to a shell reads as words, then
# split on the operators that start a new command and judge each piece.
while IFS= read -r piece; do
  # shellcheck disable=SC2086  # word-splitting the piece is the point; globbing is off
  judge $piece
done <<EOF
$(printf '%s\n' "$cmd" | tr -d "\"'" | sed -E 's/\$\(/\n/g; s/(&&|\|\||[;|&()`])/\n/g')
EOF
exit 0
