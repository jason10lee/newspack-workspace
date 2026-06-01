#!/bin/bash
#
# Unit tests for the standalone-repos tooling helpers (PR #154):
#   - get_repo_host_path   (bin/repos.sh)          — typed repos/{plugins,themes} mapping
#   - resolve_project_path (bin/resolve-project-path.sh) — typed + registry-gated resolution
#   - parse_env_worktrees  (bin/env.sh)            — tier-1/tier-2 mount parsing
#
# Pure shell, no Docker: drives the helpers against a synthetic workspace
# fixture under a temp dir and asserts their output. Fast; safe to run anywhere.
#
# Usage:
#   tests/standalone-repos.test.sh
#
# Exits non-zero if any assertion fails.

set -u

BIN="$(cd "$(dirname "$0")/../bin" && pwd)"
FIX="$(mktemp -d)"
trap 'rm -rf "$FIX"' EXIT

pass=0
fail=0
ok() {
    if [ "$2" = "$3" ]; then
        echo "  PASS  $1"
        pass=$((pass + 1))
    else
        echo "  FAIL  $1"
        echo "        expected: [$3]"
        echo "        got:      [$2]"
        fail=$((fail + 1))
    fi
}

# --- Fixture: typed standalone layout + a monorepo plugin ---
mkdir -p "$FIX/repos/plugins/newspack-community/.git" # declared standalone plugin
mkdir -p "$FIX/repos/themes/acme-theme/.git"          # declared standalone theme
mkdir -p "$FIX/repos/plugins/undeclared-plugin/.git"  # present but NOT declared
mkdir -p "$FIX/plugins/newspack-plugin"               # monorepo plugin

# The registry is normally populated from bin/repos.local.sh. repos.sh resets
# newspack_standalone_repos and re-sources that file every time it's sourced,
# so set the array *after* sourcing in each subshell below (rather than writing
# a file into bin/).
REGISTRY='newspack_standalone_repos=("newspack-community" "acme-theme")'

echo "== get_repo_host_path (filesystem-probe, typed) =="
out=$(NABSPATH="$FIX" bash -c "source '$BIN/repos.sh'; $REGISTRY; get_repo_host_path newspack-community")
ok "declared plugin -> repos/plugins/<name>" "$out" "repos/plugins/newspack-community"
out=$(NABSPATH="$FIX" bash -c "source '$BIN/repos.sh'; $REGISTRY; get_repo_host_path acme-theme")
ok "declared theme (themes/ exists) -> repos/themes/<name>" "$out" "repos/themes/acme-theme"
out=$(NABSPATH="$FIX" bash -c "source '$BIN/repos.sh'; $REGISTRY; get_repo_host_path newspack-plugin")
ok "monorepo plugin -> plugins/<name>" "$out" "plugins/newspack-plugin"

echo "== resolve_project_path (typed + registry-gated) =="
RP="source '$BIN/resolve-project-path.sh'; $REGISTRY; PLUGINS_PATH='$FIX/plugins'; THEMES_PATH='$FIX/themes'; REPOS_PATH='$FIX/repos';"
out=$(NABSPATH="$FIX" bash -c "$RP resolve_project_path newspack-community")
ok "declared standalone plugin resolves (typed)" "$out" "$FIX/repos/plugins/newspack-community"
out=$(NABSPATH="$FIX" bash -c "$RP resolve_project_path acme-theme")
ok "declared standalone theme resolves (typed)" "$out" "$FIX/repos/themes/acme-theme"
out=$(NABSPATH="$FIX" bash -c "$RP resolve_project_path undeclared-plugin")
ok "undeclared repos/ checkout does NOT resolve (registry-gated)" "$out" ""
out=$(NABSPATH="$FIX" bash -c "$RP resolve_project_path newspack-plugin")
ok "monorepo plugin resolves" "$out" "$FIX/plugins/newspack-plugin"

echo "== parse_env_worktrees (tiers, typed host, metadata, branch recovery, false-match) =="
# A real git worktree for the no-metadata live-branch-recovery case (tier 1).
mkdir -p "$FIX/worktrees"
(
    cd "$FIX/worktrees" \
        && git init -q nometa-wt \
        && cd nometa-wt \
        && git -c user.email=t@t -c user.name=t commit -q --allow-empty -m init \
        && git branch -q -m feat/recovered
) 2>/dev/null

COMPOSE="$FIX/docker-compose.env-demo.yml"
cat > "$COMPOSE" <<YAML
services:
  env-demo:
    volumes:
      - ./repos:/newspack-repos
      # newspack-wt: repo=newspack-plugin branch=feat/slashed host=plugins/newspack-plugin
      - ./worktrees/feat-slashed/plugins/newspack-plugin:/newspack-plugins/newspack-plugin
      # newspack-wt: repo=newspack-community branch=fix/bar host=repos/plugins/newspack-community
      - ./worktrees/standalone/newspack-community/fix-bar:/newspack-plugins/newspack-community
      - ./worktrees/nometa-wt/plugins/newspack-blocks:/newspack-plugins/newspack-blocks
#      - ./worktrees/commented/plugins/should-not-match:/newspack-plugins/should-not-match
YAML

# env.sh sources _common.sh, which self-sets NABSPATH to its own root; re-assert
# the fixture NABSPATH after sourcing, before calling the helper.
out=$(bash -c "source '$BIN/repos.sh'; source '$BIN/env.sh' >/dev/null 2>&1; NABSPATH='$FIX'; parse_env_worktrees '$COMPOSE' --quiet")
ok "tier-1 mount w/ metadata (real branch, typed host)" \
    "$(echo "$out" | sed -n 1p)" "$(printf 'newspack-plugin\tfeat/slashed\tfeat-slashed\tplugins/newspack-plugin')"
ok "tier-2 standalone mount (typed repos/plugins host)" \
    "$(echo "$out" | sed -n 2p)" "$(printf 'newspack-community\tfix/bar\tfix-bar\trepos/plugins/newspack-community')"
ok "no-metadata mount -> live branch via resolve_unsanitized_branch" \
    "$(echo "$out" | sed -n 3p)" "$(printf 'newspack-blocks\tfeat/recovered\tnometa-wt\tplugins/newspack-blocks')"
ok "commented-out volume line is NOT parsed (false-match guard)" \
    "$(echo "$out" | wc -l | tr -d ' ')" "3"

echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
