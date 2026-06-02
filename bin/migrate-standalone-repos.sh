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
# script's location (bin/..). Canonicalize with `pwd -P` so derived paths match
# git's worktree-list output, which resolves symlinks (e.g. macOS /var ->
# /private/var) — otherwise the move's "skip the primary worktree" comparison
# fails and a clean move spuriously rolls back.
MSR_ROOT="${NABSPATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
MSR_ROOT="$(cd "$MSR_ROOT" 2>/dev/null && pwd -P || echo "$MSR_ROOT")"
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

# Classify repos/<name> into exactly one outcome string:
#   absent | already-typed | stale-bare-alongside-typed
#   | genuine-standalone:<plugin|theme> | duplicate-clean | unsafe:<reason>
msr_classify() {
    local name="$1"
    if [ -d "$MSR_ROOT/repos/plugins/$name" ] || [ -d "$MSR_ROOT/repos/themes/$name" ]; then
        # A leftover bare copy alongside the typed one is a partial prior
        # migration — surface it rather than silently SKIP.
        [ -d "$MSR_ROOT/repos/$name" ] && { echo "stale-bare-alongside-typed"; return; }
        echo "already-typed"; return
    fi
    if [ ! -d "$MSR_ROOT/repos/$name" ]; then
        echo "absent"; return
    fi
    if ! msr_is_monorepo_tracked "$name"; then
        echo "genuine-standalone:$(msr_detect_kind "$MSR_ROOT/repos/$name")"; return
    fi
    # Name collides with a monorepo plugin/theme — only a verified stale mirror
    # is safe to remove.
    local reason; reason="$(msr_unique_git_state "$MSR_ROOT/repos/$name")"
    if [ -n "$reason" ]; then echo "unsafe:$reason"; return; fi
    if ! msr_content_matches_monorepo "$name"; then echo "unsafe:divergent-content"; return; fi
    echo "duplicate-clean"
}

# One human-readable plan line for a target.
msr_plan_line() {
    local name="$1" c; c="$(msr_classify "$name")"
    case "$c" in
        genuine-standalone:*)       echo "MOVE     $name  repos/$name -> $(msr_target_relpath "$name" "${c#genuine-standalone:}")" ;;
        duplicate-clean)            echo "REMOVE   $name  (clean monorepo duplicate -> trash)" ;;
        unsafe:*)                   echo "REFUSE   $name  (${c#unsafe:})" ;;
        stale-bare-alongside-typed) echo "REPORT   $name  (typed copy exists; stale bare repos/$name remains — inspect/remove by hand)" ;;
        already-typed)              echo "SKIP     $name  (already typed)" ;;
        absent)                     echo "MISSING  $name  (no repos/$name)" ;;
    esac
}

# Enumerate bare repos/<name> dirs (excludes plugins/, themes/, trash, dotfiles).
msr_list_bare() {
    local d name
    for d in "$MSR_ROOT"/repos/*/; do
        [ -d "$d" ] || continue
        name="$(basename "$d")"
        case "$name" in plugins|themes|.*) continue ;; esac
        echo "$name"
    done
}

main() {
    local apply=false targets=() n
    while [ $# -gt 0 ]; do
        case "$1" in
            --apply) apply=true; shift ;;
            --all)   shift; while IFS= read -r n; do targets+=("$n"); done < <(msr_list_bare) ;;
            -*)      echo "Unknown option: $1" >&2; return 2 ;;
            *)       targets+=("$1"); shift ;;
        esac
    done
    if [ "${#targets[@]}" -eq 0 ]; then
        echo "Usage: migrate-standalone-repos.sh [<name>...] [--all] [--apply]" >&2
        return 2
    fi
    # Workspace-root sanity guard (review F4): a mis-resolved MSR_ROOT would make
    # every duplicate look like a genuine standalone and silently MOVE it.
    if [ ! -d "$MSR_ROOT/plugins" ] || [ ! -d "$MSR_ROOT/themes" ]; then
        echo "ERROR: $MSR_ROOT has no plugins/ + themes/ — not a workspace root (set NABSPATH)" >&2
        return 2
    fi
    # Dedupe targets (a name passed explicitly and via --all). Repo names carry
    # no spaces, so word-split re-collection is safe.
    targets=($(printf '%s\n' ${targets[@]+"${targets[@]}"} | awk '!seen[$0]++'))

    echo "Plan (workspace: $MSR_ROOT):"
    local name
    for name in "${targets[@]}"; do msr_plan_line "$name"; done

    if [ "$apply" != true ]; then
        echo ""
        echo "Dry run. Re-run with --apply to execute. Removals back up to $MSR_TRASH_DIR first."
        return 0
    fi
    msr_apply "${targets[@]}"
}

# Move a genuine standalone to its typed path, repair any linked worktrees, and
# verify. On verify failure, roll the move back and return non-zero.
msr_do_move() {
    local name="$1" kind="$2" rel src dst
    rel="$(msr_target_relpath "$name" "$kind")"
    src="$MSR_ROOT/repos/$name"; dst="$MSR_ROOT/$rel"
    # Capture the linked-worktree set BEFORE the move. The linked worktrees
    # themselves do NOT move (only this primary does), so their paths stay
    # valid; we verify each still resolves after repair. Verifying against a
    # post-move re-list would hide a dropped worktree. Strip the literal
    # "worktree " prefix with sed (not awk $2) so paths with spaces survive.
    local wts=() w
    while IFS= read -r w; do
        [ -z "$w" ] && continue
        [ "$w" = "$src" ] && continue   # skip the primary itself
        wts+=("$w")
    done < <(git -C "$src" worktree list --porcelain 2>/dev/null | sed -n 's/^worktree //p')
    mkdir -p "$(dirname "$dst")"
    if ! mv "$src" "$dst"; then echo "  ERROR moving $name" >&2; return 1; fi
    # Repair from the moved primary (fixes the primary's .git/worktrees/*/gitdir
    # and each linked worktree's .git file).
    git -C "$dst" worktree repair >/dev/null 2>&1 || true
    local broken=0
    for w in ${wts[@]+"${wts[@]}"}; do
        git -C "$w" rev-parse --is-inside-work-tree >/dev/null 2>&1 || { broken=$((broken + 1)); echo "  unresolved: $w" >&2; }
    done
    if [ "$broken" -ne 0 ]; then
        echo "  ERROR $name: $broken worktree(s) unresolved after repair; rolling back" >&2
        mv "$dst" "$src"; git -C "$src" worktree repair >/dev/null 2>&1 || true
        return 1
    fi
    echo "  MOVED  $name -> $rel (${#wts[@]} linked worktree(s) repaired)"
}

msr_apply() {
    local name c rc=0
    for name in "$@"; do
        c="$(msr_classify "$name")"
        case "$c" in
            genuine-standalone:*)       msr_do_move "$name" "${c#genuine-standalone:}" || rc=1 ;;
            duplicate-clean)            msr_do_remove "$name" || rc=1 ;;
            unsafe:*)                   echo "  REFUSED $name (${c#unsafe:}) — handle manually" >&2 ;;
            stale-bare-alongside-typed) echo "  REPORT $name (stale bare alongside typed — handle manually)" >&2 ;;
            already-typed)              echo "  SKIP   $name (already typed)" ;;
            absent)                     echo "  MISSING $name" >&2; rc=1 ;;
        esac
    done
    return "$rc"
}

# Back up a clean duplicate to the trash dir (timestamped), then remove it from
# repos/. Never rm repo content outright — the trash copy is the safety net.
msr_do_remove() {
    local name="$1" ts dest
    ts="$(date -u +%Y%m%dT%H%M%SZ)"
    dest="$MSR_TRASH_DIR/$ts"
    mkdir -p "$dest"
    if [ -e "$dest/$name" ]; then
        echo "  ERROR trash dest already exists for $name; aborting" >&2; return 1
    fi
    if ! mv "$MSR_ROOT/repos/$name" "$dest/$name"; then
        echo "  ERROR trashing $name" >&2; return 1
    fi
    echo "  REMOVED $name (backup: repos/.migration-trash/$ts/$name — restore: mv that path back to repos/$name)"
}

# Only run main when executed directly, not when sourced by tests.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    main "$@"
fi
