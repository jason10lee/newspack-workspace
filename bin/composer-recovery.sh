#!/bin/bash
# Composer install with one-shot cache-clear recovery, for ensure-vendor.sh.
# Sourceable + host-testable: no container-only paths; `composer` is the only
# external dependency (a unit test shadows it with a shell function).
#
# Runtime-only installs (--no-dev): ensure-vendor exists to prevent fatal-on-
# activation, which needs `require` deps only. --no-dev also avoids dev-dep
# download corruption (the sebastian/* "0-byte zip" class) that has no business
# at env-up.
#
# Assumes SEQUENTIAL invocation (one plugin at a time); the clear-once flag is
# not designed for parallel/background installs.

# Default so the helper is self-contained for tests. The caller (ensure-vendor.sh)
# owns the authoritative per-run reset; this helper only ever sets it to true.
: "${COMPOSER_CACHE_CLEARED:=false}"

# composer_install_with_recovery <dir>
#   Runs: composer install --working-dir <dir> --no-dev --no-interaction
#   On failure: if the cache hasn't been cleared this run, run `composer
#   clear-cache` (once per run) and retry the install once; if it was already
#   cleared this run, just retry once without clearing.
#   Returns 0 on success (first try or after retry), 1 if it still fails.
composer_install_with_recovery() {
    local dir="${1:-}"
    [ -n "$dir" ] || return 1
    if composer install --working-dir "$dir" --no-dev --no-interaction; then
        return 0
    fi
    if [ "$COMPOSER_CACHE_CLEARED" = false ]; then
        echo "[ensure-vendor] composer install failed for $(basename "$dir") — clearing composer cache and retrying"
        if ! composer clear-cache; then
            echo "[ensure-vendor] warning: 'composer clear-cache' failed; retrying against the existing cache" >&2
        fi
        COMPOSER_CACHE_CLEARED=true
    else
        echo "[ensure-vendor] composer install failed for $(basename "$dir") — retrying (cache already cleared this run)"
    fi
    composer install --working-dir "$dir" --no-dev --no-interaction
}
