#!/bin/bash
# Unit tests for parse_worktree_mount (bin/env.sh): classifies a docker-compose
# worktree volume line into "repo|branch|kind" across the three mount shapes
# (standalone / monorepo / legacy). Host-runnable; drives env destroy + list.
set -u
ENVSH="$(cd "$(dirname "$0")/../bin" && pwd)/env.sh"
FIX="$(mktemp -d)"; trap 'rm -rf "$FIX"' EXIT
pass=0; fail=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1 (got [$2] want [$3])"; fail=$((fail+1)); fi; }

# Source ONLY the function under test (extracted) so env.sh's CLI dispatch never runs.
awk '/^parse_worktree_mount\(\)/,/^}/' "$ENVSH" > "$FIX/parse.sh"
source "$FIX/parse.sh"

chk(){ local got; got=$(parse_worktree_mount "$2") || got="(no-match)"; ok "$1" "$got" "$3"; }

chk "tier2 plugin"            "      - ./worktrees/standalone/newspack-community/feat-x:/newspack-plugins/newspack-community" "newspack-community|feat-x|standalone"
chk "tier2 theme"            "      - ./worktrees/standalone/my-theme/bug-12:/newspack-themes/my-theme"                       "my-theme|bug-12|standalone"
chk "tier2 repo w/ dashes"   "      - ./worktrees/standalone/super-cool-ad-inserter/x:/newspack-plugins/super-cool-ad-inserter" "super-cool-ad-inserter|x|standalone"
chk "tier1 plugin"           "      - ./worktrees/feat-x/plugins/newspack-newsletters:/newspack-plugins/newspack-newsletters"  "newspack-newsletters|feat-x|monorepo"
chk "tier1 theme"            "      - ./worktrees/feat-y/themes/newspack-theme:/newspack-themes/newspack-theme"                "newspack-theme|feat-y|monorepo"
chk "legacy"                 "      - ./worktrees/newspack-plugin/feat-z:/newspack-repos/newspack-plugin"                      "newspack-plugin|feat-z|legacy"
chk "non-match (no mount)"   "      - .:/newspack-monorepo"                                                                    "(no-match)"
chk "commented tier2 skipped" "      # - ./worktrees/standalone/repo-a/feat-foo:/newspack-plugins/repo-a"                      "(no-match)"

echo ""; echo "RESULT: $pass passed, $fail failed"; [ "$fail" -eq 0 ]
