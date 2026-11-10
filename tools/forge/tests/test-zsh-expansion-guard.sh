#!/bin/bash
# The zsh expansion guard denies the command shapes that a built-in Claude Code
# safety check stops for a person to approve, which would stall an unattended
# run, and leaves every other command alone.
set -uo pipefail
cd "$(dirname "$0")" || exit 1; . ../../autofix/tests/helpers.sh
HOOK=../hooks/zsh-expansion-guard.sh
bashcall() { jq -n --arg c "$1" '{tool_name: "Bash", tool_input: {command: $c}}' | bash "$HOOK"; }
decision() { [ -n "$1" ] || { echo allow; return; }; jq -r '.hookSpecificOutput.permissionDecision // "allow"' <<<"$1"; }
denies() { assert_eq deny "$(decision "$(bashcall "$1")")" "denies: $1"; }
allows() { assert_eq allow "$(decision "$(bashcall "$1")")" "allows: $1"; }

# The command the NPPM-3728 self-review stalled on, verbatim.
denies 'files=("${(@f)$(git diff --name-only --diff-filter=d origin/main...HEAD -- '"'"'*.php'"'"')}"); ./bin/precommit-phpcs.sh "${files[@]/#/$PWD/}"; echo "phpcs exit $?"'
denies 'x=${(f)y}'
denies 'echo "${(@f)$(ls)}"'
denies 'p "${files[@]/#/$PWD/}"'
denies 'p "${a[*]/%/.php}"'

allows './bin/precommit-phpcs.sh /abs/a.php /abs/b.php; echo "phpcs exit $?"'
allows "grep -n '\${(@f)' x.sh"
allows "grep '[@]/#' x"
allows "$(printf "cat > f.sh <<'EOF'\ny=\${(f)x}\nz=\"\${a[@]/#/p}\"\nEOF\nbash f.sh")"
allows "$(printf 'cat > f.sh <<"EOF"\ny=${(f)x}\nEOF')"
allows 'echo "${files[@]}"'
allows 'echo ${#arr[@]}'
allows 'git diff --name-only origin/main...HEAD'
denies "$(printf "cat > f.sh <<'EOF'\nok\nEOF\necho \${(f)x}")"

out="$(bashcall 'x=${(f)y}')"
assert_contains "$out" "literal absolute paths" "the deny says what to run instead"
assert_eq "" "$(jq -n '{tool_name: "Read", tool_input: {file_path: "${(f)x}"}}' | bash "$HOOK")" "other tools pass untouched"
assert_eq "" "$(printf 'not json' | bash "$HOOK")" "unreadable input is let through"

finish
