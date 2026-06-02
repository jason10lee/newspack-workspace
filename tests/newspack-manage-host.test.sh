#!/bin/bash
# Unit tests for bin/newspack-manage-host host-add/host-remove against a temp
# hosts file (no sudo, no ifconfig). Uses the NEWSPACK_MANAGE_HOST_HOSTS_FILE hook.
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
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

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
