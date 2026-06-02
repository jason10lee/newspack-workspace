#!/bin/bash
#
# Migrate bare repos/<name> standalone checkouts to the typed
# repos/{plugins,themes}/<name> layout (#177/#178). Dry-run by default;
# pass --apply to act. See docs/migrating-standalone-repos.md.
#
# Decision logic lives in sourceable msr_* functions (tested by
# tests/migrate-standalone-repos.test.sh); main() is guarded so sourcing
# the file does not execute it.
set -u

# Workspace root: honor NABSPATH (set by the n script); else derive from this
# script's location (bin/..).
MSR_ROOT="${NABSPATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
MSR_TRASH_DIR="$MSR_ROOT/repos/.migration-trash"

# (msr_* functions added in later tasks.)

main() {
    echo "migrate-standalone-repos: not yet implemented" >&2
    return 1
}

# Only run main when executed directly, not when sourced by tests.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    main "$@"
fi
