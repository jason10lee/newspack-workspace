#!/bin/bash
# Unit tests for bin/ssl-trust.sh — the openssl-based cert-chain check is fully
# automatable (no keychain). ssl_host_ca_trusted (macOS `security`) is covered
# by the manual smoke checklist in Task 5, not here.
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

# Build a throwaway CA + a leaf cert signed by it, and an unrelated CA.
( cd "$FIX"
  openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out rootCA.pem -days 1 -subj "/CN=test mkcert CA" >/dev/null 2>&1
  openssl req -newkey rsa:2048 -nodes -keyout leaf.key -out leaf.csr -subj "/CN=example.test" >/dev/null 2>&1
  openssl x509 -req -in leaf.csr -CA rootCA.pem -CAkey ca.key -CAcreateserial -out leaf.pem -days 1 >/dev/null 2>&1
  openssl req -x509 -newkey rsa:2048 -nodes -keyout other.key -out otherCA.pem -days 1 -subj "/CN=other CA" >/dev/null 2>&1 )

source "$BIN/ssl-trust.sh"

# ssl_cert_is_host_trusted uses `mkcert -CAROOT`; stub mkcert to point at our fixture CA dir.
mkcert(){ if [ "$1" = "-CAROOT" ]; then echo "$FIX"; else return 0; fi; }
export -f mkcert 2>/dev/null || true

ssl_cert_is_host_trusted "$FIX/leaf.pem"; ok "leaf signed by host CA -> trusted" "$?" "0"

# Re-point CAROOT at a dir whose rootCA.pem is the unrelated CA.
ALT="$(mktemp -d)"; cp "$FIX/otherCA.pem" "$ALT/rootCA.pem"
mkcert(){ if [ "$1" = "-CAROOT" ]; then echo "$ALT"; else return 0; fi; }
ssl_cert_is_host_trusted "$FIX/leaf.pem"; ok "leaf NOT signed by host CA -> untrusted" "$?" "1"
rm -rf "$ALT"

ssl_cert_is_host_trusted "$FIX/does-not-exist.pem"; ok "missing cert -> untrusted" "$?" "1"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
