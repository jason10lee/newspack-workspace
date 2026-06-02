#!/bin/bash
# Unit tests for bin/composer-recovery.sh (composer_install_with_recovery).
# Stubs the `composer` command — no real composer/network. Host-runnable.
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

source "$BIN/composer-recovery.sh"

CMDLOG="$FIX/cmdlog"
INSTALL_CALLS=0; INSTALL_FAIL_UNTIL=0; CLEARCACHE_RC=0
# Stub: logs the composer subcommand; `install` fails for the first
# INSTALL_FAIL_UNTIL calls then succeeds; `clear-cache` returns CLEARCACHE_RC.
composer() {
  printf '%s\n' "$1" >> "$CMDLOG"
  case "$1" in
    install) INSTALL_CALLS=$((INSTALL_CALLS+1)); [ "$INSTALL_CALLS" -le "$INSTALL_FAIL_UNTIL" ] && return 1; return 0 ;;
    clear-cache) return "$CLEARCACHE_RC" ;;
    *) return 0 ;;
  esac
}
# reset <install_fail_until> [clearcache_rc] [cache_cleared]
reset(){ : > "$CMDLOG"; INSTALL_CALLS=0; INSTALL_FAIL_UNTIL="$1"; CLEARCACHE_RC="${2:-0}"; COMPOSER_CACHE_CLEARED="${3:-false}"; }

# 1. success first try
reset 0; composer_install_with_recovery "$FIX/p"; rc=$?
ok "success: returns 0" "$rc" "0"
ok "success: install called once" "$(grep -c '^install$' "$CMDLOG")" "1"
ok "success: clear-cache not called" "$(grep -c '^clear-cache$' "$CMDLOG")" "0"

# 2. fail once then succeed
reset 1; composer_install_with_recovery "$FIX/p"; rc=$?
ok "recover: returns 0" "$rc" "0"
ok "recover: install called twice" "$(grep -c '^install$' "$CMDLOG")" "2"
ok "recover: clear-cache called once" "$(grep -c '^clear-cache$' "$CMDLOG")" "1"

# 3. always fail
reset 999; composer_install_with_recovery "$FIX/p"; rc=$?
ok "persist-fail: returns 1" "$rc" "1"
ok "persist-fail: install called twice (initial+retry)" "$(grep -c '^install$' "$CMDLOG")" "2"
ok "persist-fail: clear-cache called once" "$(grep -c '^clear-cache$' "$CMDLOG")" "1"

# 4. cache already cleared this run (preset true): retry once, NO clear-cache
reset 1 0 true; composer_install_with_recovery "$FIX/p"; rc=$?
ok "already-cleared: returns 0" "$rc" "0"
ok "already-cleared: install called twice" "$(grep -c '^install$' "$CMDLOG")" "2"
ok "already-cleared: clear-cache NOT called again" "$(grep -c '^clear-cache$' "$CMDLOG")" "0"

# 5. clear-cache itself fails + install always fails
reset 999 1 false; composer_install_with_recovery "$FIX/p"; rc=$?
ok "clearcache-fail: returns 1" "$rc" "1"
ok "clearcache-fail: install still retried (twice)" "$(grep -c '^install$' "$CMDLOG")" "2"
ok "clearcache-fail: clear-cache attempted once" "$(grep -c '^clear-cache$' "$CMDLOG")" "1"
ok "clearcache-fail: flag ended true" "$COMPOSER_CACHE_CLEARED" "true"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
