#!/bin/bash
# Sourceable, host-testable helpers for isolated-env worktree volume mounts.
# A tier-1 monorepo plugin/theme worktree is mounted BOTH at its serving path
# (/newspack-plugins|themes/<name>) AND at the pnpm workspace-member path
# (/newspack-monorepo/<host>), so `pnpm --filter` builds the worktree in place.
# Tier-2 standalone (repos/*) worktrees get only the serving mount.

# worktree_volume_lines <worktree_dir> <serving_container_path> <wt_host_path>
#   Emits the compose volume line(s) (6-space + "- " indented). Tier-1 hosts
#   (plugins/*, themes/*) emit serving + workspace-member lines; tier-2 (repos/*)
#   emits only the serving line.
worktree_volume_lines() {
    local worktree_dir="${1:-}" serving_path="${2:-}" host_path="${3:-}"
    printf '      - %s:%s\n' "$worktree_dir" "$serving_path"
    case "$host_path" in
        plugins/*|themes/*)
            printf '      - %s:/newspack-monorepo/%s\n' "$worktree_dir" "$host_path"
            ;;
    esac
}

# worktree_member_lines_to_add <compose_file>
#   Emits the workspace-member volume line(s) missing for tier-1 worktree serving
#   mounts already present in <compose_file>. Idempotent: a member line already in
#   the file yields nothing. Tier-2 (standalone) serving mounts are ignored.
worktree_member_lines_to_add() {
    local compose="${1:-}"
    [ -r "$compose" ] || return 0
    local line wt_dir host_path target
    while IFS= read -r line; do
        # tier-1 serving mount: ./worktrees/<sb>/(plugins|themes)/<name>:/newspack-(plugins|themes)/<name>
        if [[ "$line" =~ -[[:space:]]+(\./worktrees/[^/:]+/((plugins|themes)/[^[:space:]:]+)):/newspack-(plugins|themes)/[^[:space:]:]+[[:space:]]*$ ]]; then
            wt_dir="${BASH_REMATCH[1]}"
            host_path="${BASH_REMATCH[2]}"
            target="${wt_dir}:/newspack-monorepo/${host_path}"
            grep -qF "$target" "$compose" || printf '      - %s\n' "$target"
        fi
    done < "$compose"
}
