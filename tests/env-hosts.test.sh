#!/bin/bash
# Unit tests for bin/env-hosts.sh pure helpers (classify, strip, sed_expr).
# env_hosts_remove is privileged (sudo) and covered by tests/env-hosts-cleanup.smoke.md.
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

source "$BIN/env-hosts.sh"

# --- env_hosts_classify ---------------------------------------------------
HOSTS="$FIX/hosts"
cat > "$HOSTS" <<'EOF'
127.0.0.1 localhost
127.0.0.5 alive.test # newspack-env:alive
127.0.0.6 dead.test # newspack-env:dead
127.0.0.7 ghost.test
127.0.0.8 myproject.local
10.0.0.1 internal.example.com
EOF

# Live: env "alive" (domain alive.test). Everything else is stale or foreign.
out=$(env_hosts_classify "$HOSTS" "alive" "alive.test")

ok "marked-orphan == {dead.test}" \
   "$(echo "$out" | grep '^marked-orphan ' | awk '{print $2}' | sort | tr '\n' ',')" \
   "dead.test,"
ok "legacy-candidate == {ghost.test, myproject.local}" \
   "$(echo "$out" | grep '^legacy-candidate ' | awk '{print $2}' | sort | tr '\n' ',')" \
   "ghost.test,myproject.local,"
ok "alive.test (live, marked) not flagged" "$(echo "$out" | grep -c 'alive.test')" "0"
ok "internal.example.com (non-loopback) not flagged" "$(echo "$out" | grep -c 'internal')" "0"
ok "localhost (not .test/.local) not flagged" "$(echo "$out" | grep -c 'localhost')" "0"

# Unmarked line whose domain IS a live env (old 2-arg host-add) must be kept.
out2=$(env_hosts_classify "$HOSTS" "" "ghost.test")
ok "unmarked but live domain not flagged" "$(echo "$out2" | grep -c 'ghost.test')" "0"

# Missing file is a no-op (returns 0, no output).
out3=$(env_hosts_classify "$FIX/nope" "" ""); ok "missing hosts file -> empty" "$out3" ""

# --- env_hosts_strip ------------------------------------------------------
STRIP="$FIX/strip"
cat > "$STRIP" <<'EOF'
127.0.0.6 dead.test # newspack-env:dead
127.0.0.7 ghost.test
127.0.0.9 keep.test
127.0.0.10 dead.test.extra
EOF
env_hosts_strip "dead.test" "$STRIP"
ok "strip removes the marked line" "$(grep -c '127.0.0.6' "$STRIP")" "0"
ok "strip leaves suffix-collision line (dead.test.extra)" "$(grep -c 'dead.test.extra' "$STRIP")" "1"
env_hosts_strip "ghost.test" "$STRIP"
ok "strip removes the unmarked line" "$(grep -c '127.0.0.7' "$STRIP")" "0"
ok "strip keeps unrelated line (keep.test)" "$(grep -c 'keep.test' "$STRIP")" "1"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
