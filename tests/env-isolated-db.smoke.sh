#!/bin/bash
#
# Smoke test for NEWS-2286: n env create --isolated-db
#
# Spins up a short-lived env, asserts the sidecar is configured with
# lower_case_table_names=1, creates a PascalCase $wpdb table from PHP,
# asserts both .frm and .ibd are lowercase, then tears down.
#
# Usage:
#   tests/env-isolated-db.smoke.sh
#
# Idempotent: cleans up any prior run of the same env name before starting.

set -euo pipefail

NABSPATH="$(cd "$(dirname "$0")/.." && pwd)"
ENV_NAME="smoke-isolated-db"
SAFE_NAME="smoke_isolated_db"
DB_NAME="wordpress_${SAFE_NAME}"
SIDECAR_CONTAINER="newspack_db_lowercase_${SAFE_NAME}"

cleanup() {
    "$NABSPATH/n" env destroy "$ENV_NAME" 2>/dev/null || true
}
trap cleanup EXIT

# Tear down any leftover state from a prior run.
cleanup

# Source DB credentials.
set -a
# shellcheck disable=SC1091
source "$NABSPATH/default.env"
# shellcheck disable=SC1091
[[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
set +a

echo "==> Creating env $ENV_NAME with --isolated-db..."
"$NABSPATH/n" env create "$ENV_NAME" --isolated-db --up

echo "==> Asserting sidecar exists and reports lower_case_table_names=1..."
lctn=$(docker exec "$SIDECAR_CONTAINER" \
    mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" -N -B \
    -e "SELECT @@lower_case_table_names" | tr -d '\r')
if [[ "$lctn" != "1" ]]; then
    echo "FAIL: sidecar reports lower_case_table_names=$lctn (expected 1)" >&2
    exit 1
fi
echo "    sidecar lower_case_table_names=1 confirmed"

echo "==> Creating PascalCase table via \$wpdb from PHP..."
docker exec "newspack_env_${SAFE_NAME}" wp --allow-root eval '
    global $wpdb;
    $wpdb->query("CREATE TABLE NewsTwo286Smoke (id INT)");
    if ($wpdb->last_error) {
        fwrite(STDERR, "\$wpdb error: " . $wpdb->last_error . "\n");
        exit(1);
    }
'

echo "==> Asserting both files are lowercase on disk..."
files=$(docker exec "$SIDECAR_CONTAINER" \
    ls "/var/lib/mysql/${DB_NAME}/" | grep -i newstwo286smoke || true)
if [[ -z "$files" ]]; then
    echo "FAIL: no NewsTwo286Smoke files found in sidecar data dir" >&2
    exit 1
fi
if echo "$files" | grep -q '[A-Z]'; then
    echo "FAIL: uppercase characters found in:" >&2
    echo "$files" >&2
    exit 1
fi
echo "    all NewsTwo286Smoke files are lowercase:"
echo "$files" | sed 's/^/      /'

echo "==> Read-after-write should succeed (the original ERROR 1033 case)..."
docker exec "newspack_env_${SAFE_NAME}" wp --allow-root db query \
    "SELECT COUNT(*) FROM NewsTwo286Smoke"

echo ""
echo "PASS: NEWS-2286 isolated-db sidecar working end-to-end."
