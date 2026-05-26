#!/usr/bin/env bash
#
# seed-baseline-tags.sh — create `<pkgName>@<version>` baseline tags at the cutover
# commit so multi-semantic-release bumps each package from its true last-released
# version instead of resetting to 1.0.0.
#
# Why this is needed:
#   multi-semantic-release forces the semantic-release tagFormat to
#   `<pkgName>@${version}` (lib/multiSemanticRelease.js — it overrides any
#   per-package tagFormat). The legacy tags imported during migration use the
#   `<name>-v<version>` form, so they are invisible to msr. Without a tag in the
#   `<pkgName>@<version>` form reachable from the release branch, msr reports
#   "no previous release" and starts the package at 1.0.0.
#
# What it does:
#   For every releasable workspace package (one that has a release config), it
#   resolves the last published version and creates an annotated tag
#   `<pkgName>@<version>` at the target commit (HEAD by default).
#
# Baseline source, in order:
#   - plugins/ and themes/: latest non-prerelease GitHub release of the matching
#     legacy repo (Automattic/<dir-basename>).
#   - packages/ (shared npm libs): latest version published to npm.
#   - fallback (no public release found, e.g. the never-released
#     newspack-story-budget): the version in the package's package.json.
#
# Every releasable package MUST get a baseline tag — including ones with no
# public release. A package that msr sees with no prior tag does not merely
# start at 1.0.0: with no baseline it has no commit boundary, so it pulls the
# ENTIRE monorepo history into its release notes. That makes the
# `@semantic-release/git` release commit message enormous (it fails with E2BIG
# on macOS, and produces a junk changelog full of unrelated packages' commits
# even where the commit succeeds). Seeding a baseline at the cutover commit
# scopes each package to only its own post-cutover commits.
#
# Run this ONCE, at cutover, on the cutover commit, before the first release.
#
# Usage:
#   bin/migration/seed-baseline-tags.sh [--ref <commit>] [--push] [--remote <name>] [--dry-run]
#
#   --ref <commit>     Commit to tag (default: HEAD).
#   --push             Push the created tags to the remote.
#   --remote <name>    Remote to push to (default: origin).
#   --dry-run          Print the tags that would be created; create nothing.
#
# This file is part of the migration tooling and is intended to be deleted after
# cutover.

set -euo pipefail

REF="HEAD"
DO_PUSH=0
REMOTE="origin"
DRY_RUN=0

while [ $# -gt 0 ]; do
  case "$1" in
    --ref) REF="$2"; shift 2 ;;
    --push) DO_PUSH=1; shift ;;
    --remote) REMOTE="$2"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

repo_root=$(git rev-parse --show-toplevel)
cd "$repo_root"

# Read a top-level field from a package.json with node (avoids a jq dependency).
# Objects are returned as JSON; missing fields as an empty string. Never fails
# the script (trailing `|| true`), so a missing field doesn't trip `set -e`.
pkg_field() {
  node -e "const o=JSON.parse(require('fs').readFileSync('$1','utf8')); const v=o['$2']; process.stdout.write(v==null?'':(typeof v==='object'?JSON.stringify(v):String(v)))" 2>/dev/null || true
}

# Latest non-prerelease GitHub release tag for a legacy repo, stripped of a
# leading "v". Empty if the repo has no releases.
gh_latest() {
  { gh release list --repo "Automattic/$1" --exclude-pre-releases --limit 1 2>/dev/null || true; } \
    | awk 'NR==1 {print $1}' | sed 's/^v//'
}

# Latest version published to npm for a package name. Empty if unpublished.
npm_latest() { npm view "$1" version 2>/dev/null || true; }

# Print the higher of two semver strings (tolerates empty arguments).
highest_version() {
  local a="$1" b="$2"
  [ -z "$a" ] && { echo "$b"; return; }
  [ -z "$b" ] && { echo "$a"; return; }
  printf '%s\n%s\n' "$a" "$b" | sort -V | tail -1
}

created=()
skipped=()

# A package is releasable if it carries a release config that semantic-release
# (via cosmiconfig) will honour: a release.config.{js,cjs} file, any
# .releaserc* file (e.g. newspack-theme uses .releaserc.js), or a "release"
# field in package.json. Missing any of these forms here means the package is
# silently skipped and msr reborns it at 1.0.0 — so keep this in sync with
# cosmiconfig's search places.
is_releasable() {
  local dir="$1"
  [ -f "$dir/release.config.js" ] && return 0
  [ -f "$dir/release.config.cjs" ] && return 0
  ls "$dir"/.releaserc* > /dev/null 2>&1 && return 0
  [ -n "$(pkg_field "$dir/package.json" 'release')" ] && return 0
  return 1
}

# Map a workspace directory to the Automattic GitHub repo that publishes its
# automated releases. Most directories match their basename; the extracted
# shared package at packages/scripts is the standalone newspack-scripts repo.
# packages/{colors,components,icons} have no standalone repo (they were split
# out of newspack-plugin), so they resolve to a non-existent repo and the GH
# lookup simply returns empty — npm is their source of truth.
legacy_repo() {
  case "$1" in
    packages/scripts) echo "newspack-scripts" ;;
    *) basename "$1" ;;
  esac
}

seed_one() {
  local dir="$1"
  local pj="$dir/package.json"
  [ -f "$pj" ] || return 0
  is_releasable "$dir" || return 0

  local pkg_name
  pkg_name=$(pkg_field "$pj" 'name')
  [ -n "$pkg_name" ] || { echo "WARN: no name in $pj, skipping" >&2; return 0; }

  # Baseline = the highest version across every source: the legacy repo's latest
  # GitHub release (authoritative for automated-release packages), the latest
  # version on npm, and the in-repo package.json. Taking the max guards all
  # directions at once: the monorepo manifest routinely LAGS the GitHub release
  # (the sync brings code, not the release commits), while hand-released shared
  # packages can have a package.json that LEADS the registry. The max is always
  # strictly >= anything already shipped or claimed, so the first monorepo
  # release increments cleanly and never rewrites a manifest version downward.
  local gh npmv pjv version
  gh=$(gh_latest "$(legacy_repo "$dir")")
  npmv=$(npm_latest "$pkg_name")
  pjv=$(pkg_field "$pj" 'version')
  version=$(highest_version "$(highest_version "$gh" "$npmv")" "$pjv")
  if [ -z "$version" ]; then
    skipped+=("$pkg_name (no version resolvable from GitHub, npm, or package.json)")
    return 0
  fi

  local tag="$pkg_name@$version"
  if git rev-parse -q --verify "refs/tags/$tag" > /dev/null; then
    skipped+=("$tag (already exists)")
    return 0
  fi

  if [ "$DRY_RUN" = "1" ]; then
    echo "    [dry-run] would tag $REF as $tag"
  else
    git tag -a "$tag" -m "baseline: last released version before monorepo cutover" "$REF"
  fi
  created+=("$tag")
}

echo "==> Seeding baseline tags at $REF (msr tag format: <pkgName>@<version>)"

for dir in plugins/* themes/* packages/*; do
  [ -d "$dir" ] || continue
  seed_one "$dir"
done

echo ""
echo "==> Created ${#created[@]} tag(s):"
for t in "${created[@]:-}"; do [ -n "$t" ] && echo "    $t"; done
echo "==> Skipped ${#skipped[@]}:"
for s in "${skipped[@]:-}"; do [ -n "$s" ] && echo "    $s"; done

if [ "$DO_PUSH" = "1" ] && [ "$DRY_RUN" != "1" ] && [ "${#created[@]}" -gt 0 ]; then
  echo "==> Pushing tags to $REMOTE"
  git push "$REMOTE" "${created[@]}"
fi
