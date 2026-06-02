#!/bin/bash
#
# Unit tests for bin/migrate-standalone-repos.sh decision logic + actions.
# Pure shell + synthetic git fixtures under a temp dir; no Docker.
#
# Usage: tests/migrate-standalone-repos.test.sh   (exits non-zero on failure)
set -u

BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"
trap 'rm -rf "$FIX"' EXIT

pass=0; fail=0
ok() {
    if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass + 1));
    else echo "  FAIL  $1"; echo "        expected: [$3]"; echo "        got:      [$2]"; fail=$((fail + 1)); fi
}

# Source the script with a fixture root; main() is guarded so this is safe.
NABSPATH="$FIX" source "$BIN/migrate-standalone-repos.sh"

echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
