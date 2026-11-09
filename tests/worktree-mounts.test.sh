#!/bin/bash
# Unit tests for env.sh worktree-mount parsing (upstream's parse_worktree_mount /
# each_worktree_in_env). Host-runnable; sources env.sh (sourceable-guarded).
set -u
BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }
source "$BIN/env.sh"

ok "monorepo plugin mount"  "$(parse_worktree_mount '      - ./worktrees/feat-x/plugins/np-news:/newspack-plugins/np-news')" "np-news|feat-x|monorepo"
ok "monorepo theme mount"   "$(parse_worktree_mount '      - ./worktrees/feat-y/themes/np-theme:/newspack-themes/np-theme')" "np-theme|feat-y|monorepo"
ok "standalone repos mount" "$(parse_worktree_mount '      - ./worktrees-repos/np-manager/feat-z:/newspack-repos/plugins/np-manager')" "np-manager|feat-z|repos"
if parse_worktree_mount '      - ./html:/var/www/html' >/dev/null 2>&1; then r=matched; else r=nomatch; fi
ok "non-worktree mount -> non-zero" "$r" "nomatch"

# each_worktree_in_env over a compose fixture with one of each shape:
C="$FIX/c.yml"; cat > "$C" <<'EOF'
    volumes:
      - ./worktrees/feat-x/plugins/np-news:/newspack-plugins/np-news
      - ./worktrees-repos/np-manager/feat-z:/newspack-repos/plugins/np-manager
      - ./html:/var/www/html
EOF
ok "each_worktree_in_env yields 2 triples" "$(each_worktree_in_env "$C" | grep -c '|')" "2"

# resolve_unsanitized_branch recovers the real git branch (feat/x) from a
# worktree dir named by its safe form (feat-x). env-destroy uses this ONLY to
# name the branch to delete separately — NOT for dir lookup: the worktree dir is
# always removed by the safe form so a retargeted worktree isn't orphaned.
export NABSPATH="$FIX"
git init -q "$FIX/src"
( cd "$FIX/src" && git commit -q --allow-empty -m init && \
  git worktree add -q -b feat/x "$FIX/worktrees/feat-x" ) >/dev/null 2>&1
ok "resolve_unsanitized_branch recovers real branch" "$(resolve_unsanitized_branch feat-x "")" "feat/x"
ok "resolve_unsanitized_branch falls back to safe form when dir absent" "$(resolve_unsanitized_branch nope "")" "nope"

# Retargeted worktree: env bound to safe dir feat-foo (branch feat/foo), then a
# user runs `git checkout -b other` inside it. resolve_unsanitized_branch now
# reports the LIVE branch (other), which is exactly why the destroy path must
# NOT feed this into dir lookup — the dir is still named worktrees/feat-foo.
# Removing by the resolved `other` would sanitize to worktrees/other and orphan
# the real dir. The fix removes by the safe form and only deletes the real
# branch separately.
( cd "$FIX/src" && git worktree add -q -b feat/foo "$FIX/worktrees/feat-foo" && \
  cd "$FIX/worktrees/feat-foo" && git checkout -q -b other ) >/dev/null 2>&1
ok "resolve_unsanitized_branch reports live (retargeted) branch" "$(resolve_unsanitized_branch feat-foo "")" "other"

# Branch volumes: one whole-worktree mount and a read-only root install per
# branch, which the build uses to resolve the branch's own packages/.
ok "branch volumes: worktree mount" "$(branch_root_volumes feat-x | sed -n 1p)" "      - ./worktrees/feat-x:/newspack-worktrees/feat-x"
ok "branch volumes: read-only root node_modules" "$(branch_root_volumes feat-x | sed -n 2p)" "      - ./node_modules:/newspack-worktrees/feat-x/node_modules:ro"
ok "branch volumes: two lines" "$(branch_root_volumes feat-x | wc -l | tr -d ' ')" "2"
C2="$FIX/c2.yml"; { cat "$C"; branch_root_volumes feat-x; } > "$C2"
ok "branch mount detected" "$(branch_root_mounted "$C2" feat-x && echo y)" "y"
ok "other branch not detected" "$(branch_root_mounted "$C2" feat-y || echo n)" "n"
ok "a branch whose name prefixes another is not detected" "$(branch_root_mounted "$C2" feat || echo n)" "n"
ok "unit mount alone is not a branch mount" "$(branch_root_mounted "$C" feat-x || echo n)" "n"
ok "each_worktree_in_env ignores branch volumes" "$(each_worktree_in_env "$C2" | grep -c '|')" "2"
mkdir -p "$FIX/p1" "$FIX/p2"; : > "$FIX/p1/n"; : > "$FIX/p2/n"
printf '{\n  "name": "w",\n  "packageManager": "pnpm@10.33.0"\n}\n' > "$FIX/p1/package.json"
printf '{\n  "name": "w"\n}\n' > "$FIX/p2/package.json"
ok "root pnpm spec read" "$(NABSPATH="$FIX/p1" root_pnpm_spec)" "pnpm@10.33.0"
ok "root pnpm spec absent" "$(NABSPATH="$FIX/p2" root_pnpm_spec)" ""
ok "create arm adds branch volumes" "$(grep -c 'branch_root_volumes "\$safe_branch"' "$BIN/env.sh")" "1"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
