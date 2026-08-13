#!/usr/bin/env bash
#
# test-newspack-manage-host.sh
#
# Specs for bin/newspack-manage-host host-add/host-remove against a temp hosts
# file (no sudo, no ifconfig), via the NEWSPACK_MANAGE_HOST_HOSTS_FILE hook,
# plus a proof that the pinned PATH is not overridable by the caller.
#
# Lives in bin/tests/ because that is the directory CI globs. The wrapper runs
# as root under a NOPASSWD sudoers rule, so a spec that only runs when someone
# remembers to run it is not protecting anything.
#
# Run: bash bin/tests/test-newspack-manage-host.sh
set -u
BIN="$(cd "$(dirname "$0")/.." && pwd)"
WRAP="$BIN/newspack-manage-host"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

H="$FIX/hosts"; : > "$H"
run(){ NEWSPACK_MANAGE_HOST_HOSTS_FILE="$H" bash "$WRAP" "$@"; }

# 2-arg host-add: unmarked line, back-compat.
run host-add 127.0.0.2 plain.test
ok "2-arg add writes unmarked line" "$(grep -c '^127.0.0.2 plain.test$' "$H")" "1"

# 3-arg host-add: marked line.
run host-add 127.0.0.3 marked.test demo
ok "3-arg add writes marker" "$(grep -c '^127.0.0.3 marked.test # newspack-env:demo$' "$H")" "1"

# Dedup must recognise an already-present MARKED domain (no duplicate).
run host-add 127.0.0.9 marked.test demo
ok "dedup skips already-present marked domain" "$(grep -c 'marked.test' "$H")" "1"

# host-remove must delete a MARKED line (trailing marker, not at EOL anchor).
run host-remove marked.test
ok "remove deletes marked line" "$(grep -c 'marked.test' "$H")" "0"

# host-remove must still delete an unmarked line, and not touch a suffix collision.
echo "127.0.0.4 keep.test.extra" >> "$H"
run host-remove plain.test
ok "remove deletes unmarked line" "$(grep -c 'plain.test' "$H")" "0"
ok "remove leaves suffix-collision line" "$(grep -c 'keep.test.extra' "$H")" "1"

# Reject an env-name containing a newline injection attempt (validation boundary).
H2="$FIX/hosts2"; : > "$H2"
NEWSPACK_MANAGE_HOST_HOSTS_FILE="$H2" bash "$WRAP" host-add 127.0.0.5 inj.test "$(printf 'evil\n127.0.0.6 injected.test')" 2>/dev/null
ok "rejects newline-injecting env-name (no line written)" "$(grep -c '.' "$H2")" "0"
ok "rejects newline-injecting env-name (no injected line)" "$(grep -c 'injected.test' "$H2")" "0"

# Dot-escaped dedup: a similarly-named line must NOT block adding the real domain.
H3="$FIX/hosts3"; printf '127.0.0.7 fooXtest\n' > "$H3"
NEWSPACK_MANAGE_HOST_HOSTS_FILE="$H3" bash "$WRAP" host-add 127.0.0.8 foo.test
ok "dot-escaped dedup adds foo.test despite fooXtest present" "$(grep -c '^127.0.0.8 foo.test$' "$H3")" "1"

# Dotted env name (e.g. foo.bar for --isolated-db) must be accepted and marked,
# matching _common.sh's validate_env_name contract.
H4="$FIX/hosts4"; : > "$H4"
NEWSPACK_MANAGE_HOST_HOSTS_FILE="$H4" bash "$WRAP" host-add 127.0.0.5 dotted.test foo.bar
ok "accepts dotted env name and writes its marker" "$(grep -c '^127.0.0.5 dotted.test # newspack-env:foo.bar$' "$H4")" "1"

# The pinned PATH must not be overridable by the caller.
#
# The wrapper runs as root under a NOPASSWD sudoers rule and macOS sets no
# secure_path, so sudo passes the caller's PATH straight through. Without the
# pin, a `grep` planted earlier in that PATH executes as root. This plants one
# and asserts it is never reached. No sudo is involved: the hijack works the
# same way whoever runs the script, so the spec proves the mechanism without
# needing privileges.
#
# Assert on the marker file rather than on exit status — a planted binary that
# runs and then succeeds would leave the status clean and the spec green.
EVIL="$FIX/evil"; mkdir -p "$EVIL"
printf '#!/bin/bash\ntouch "%s/HIJACKED"\nexit 1\n' "$FIX" > "$EVIL/grep"
chmod +x "$EVIL/grep"
H5="$FIX/hosts5"; : > "$H5"
rm -f "$FIX/HIJACKED"
PATH="$EVIL:$PATH" NEWSPACK_MANAGE_HOST_HOSTS_FILE="$H5" bash "$WRAP" host-add 127.0.0.6 pinned.test
ok "planted grep is not used (PATH pin holds)" "$([ -e "$FIX/HIJACKED" ] && echo hijacked || echo clean)" "clean"
# And the real grep still did its job, so the pin did not simply break the call.
ok "pinned PATH still resolves a working grep" "$(grep -c '^127.0.0.6 pinned.test$' "$H5")" "1"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
