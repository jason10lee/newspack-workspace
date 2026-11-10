#!/bin/bash
# PreToolUse hook for forge run sessions, and the review sessions they start:
# deny a Bash call that uses a zsh parameter-expansion flag (`${(f)…}`) or an
# array pattern substitution (`${a[@]/#/…}`). Claude Code's built-in safety
# check cannot parse those shapes and stops for a person to approve the
# command, which no allow rule or permission mode clears, so an unattended run
# would sit waiting. A deny instead tells the session what to run, and it
# retries. The NPPM-3728 self-review stalled on exactly this, building its
# PHPCS file list as a zsh array.
#
# Only text the shell would expand is judged: single-quoted spans and the
# bodies of quoted heredocs are dropped first, so a grep for the pattern or a
# script written through `<<'EOF'` passes. Double-quoted text is kept, because
# zsh expands inside it. Input it cannot read passes, rather than blocking
# every Bash call.
set -uo pipefail

input="$(cat)"
[ "$(printf '%s' "$input" | jq -r '.tool_name // empty' 2>/dev/null)" = Bash ] || exit 0
cmd="$(printf '%s' "$input" | jq -r '.tool_input.command // empty' 2>/dev/null)" || exit 0
[ -n "$cmd" ] || exit 0

# Drop quoted-heredoc bodies (<<'X', <<"X", <<\X, <<-'X' through the line that is X),
# then single-quoted spans.
expanded="$(printf '%s\n' "$cmd" | perl -ne '
  if (defined $end) { if (/^\t*\Q$end\E$/) { undef $end } next }
  $end = defined $2 ? $2 : $3 if /<<-?\s*(?:(["\x27])([^\s"\x27]+)\1|\\([^\s"\x27\\]+))/;
  s/\x27[^\x27]*\x27//g;
  print;
')"

if printf '%s' "$expanded" | grep -Eq '\$\{\(|\[[@*]\]/[#%]'; then
  jq -n --arg r "forge: this command uses a zsh expansion (\`\${(…)…}\` or \`\${arr[@]/#/…}\`) that Claude Code's safety check stops for a person to approve, so an unattended run would stall on it. Pass the files as literal absolute paths instead, one argument each, for example \`./bin/precommit-phpcs.sh /abs/path/a.php /abs/path/b.php\`. If the text is literal, such as a grep pattern or a script you are writing, single-quote it on one line or write it through \`<<'EOF'\`." \
    '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: $r}}'
fi
exit 0
