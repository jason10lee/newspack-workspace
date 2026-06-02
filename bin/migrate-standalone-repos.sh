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

# Detect plugin vs theme by content. Theme = root style.css carrying a
# "Theme Name:" header (WP theme contract), or a root theme.json (FSE) that is
# NOT corroborated as a plugin by a root PHP file declaring a "Plugin Name:"
# header (some plugins ship a root theme.json). Everything else is a plugin.
msr_detect_kind() {
    local dir="$1"
    if [ -f "$dir/style.css" ] && grep -qiE '^[[:space:]]*Theme Name:' "$dir/style.css" 2>/dev/null; then
        echo "theme"; return
    fi
    if [ -f "$dir/theme.json" ]; then
        local php has_plugin_header=false
        for php in "$dir"/*.php; do
            [ -f "$php" ] || continue   # literal glob when no .php files — skip
            if grep -qiE '^[[:space:]]*\*?[[:space:]]*Plugin Name:' "$php" 2>/dev/null; then
                has_plugin_header=true; break
            fi
        done
        [ "$has_plugin_header" = false ] && { echo "theme"; return; }
    fi
    echo "plugin"
}

# Map name + kind to the typed host-relative path.
msr_target_relpath() {
    local name="$1" kind="$2"
    if [ "$kind" = "theme" ]; then echo "repos/themes/$name"; else echo "repos/plugins/$name"; fi
}

# 0 if <name> is a non-empty tracked monorepo dir (plugins/<name> or
# themes/<name>). An empty migration stub does NOT count (link-repos treats
# those as "use the repos/ copy").
msr_is_monorepo_tracked() {
    local name="$1" kind d
    for kind in plugins themes; do
        d="$MSR_ROOT/$kind/$name"
        if [ -d "$d" ] && [ -n "$(ls -A "$d" 2>/dev/null)" ]; then return 0; fi
    done
    return 1
}

# Echo a reason if the checkout carries unique (unrecoverable-on-remove) git
# state, else echo ''. Reasons: not-a-git-repo, dirty-working-tree,
# unpushed-commits (a local branch tip not contained in any remote ref),
# stash-entries. Detached-HEAD-only commits remain a known gap (recoverable via
# the trash backup; documented in the operator guide).
msr_unique_git_state() {
    local dir="$1" tip
    git -C "$dir" rev-parse --git-dir >/dev/null 2>&1 || { echo "not-a-git-repo"; return; }
    if [ -n "$(git -C "$dir" status --porcelain 2>/dev/null)" ]; then echo "dirty-working-tree"; return; fi
    while IFS= read -r tip; do
        [ -z "$tip" ] && continue
        if [ -z "$(git -C "$dir" branch -r --contains "$tip" 2>/dev/null)" ]; then
            echo "unpushed-commits"; return
        fi
    done <<EOF
$(git -C "$dir" for-each-ref --format='%(objectname)' refs/heads 2>/dev/null)
EOF
    [ -n "$(git -C "$dir" stash list 2>/dev/null)" ] && { echo "stash-entries"; return; }
    echo ""
}

# 0 if repos/<name> tracked content is byte-identical to the monorepo copy
# (ignoring .git). Confirms a name-collision is a true stale mirror, not a fork.
msr_content_matches_monorepo() {
    local name="$1" mono="" kind d
    for kind in plugins themes; do
        d="$MSR_ROOT/$kind/$name"
        if [ -d "$d" ] && [ -n "$(ls -A "$d" 2>/dev/null)" ]; then mono="$d"; break; fi
    done
    [ -n "$mono" ] || return 1
    diff -rq --exclude=.git "$MSR_ROOT/repos/$name" "$mono" >/dev/null 2>&1
}

main() {
    echo "migrate-standalone-repos: not yet implemented" >&2
    return 1
}

# Only run main when executed directly, not when sourced by tests.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    main "$@"
fi
