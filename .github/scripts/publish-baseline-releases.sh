#!/usr/bin/env bash
#
# publish-baseline-releases.sh — publish each releasable package's CURRENT
# production release as a real GitHub release on the monorepo, at the cutover.
#
# Why this exists:
#   newspack-wpcloud-externals (which feeds Atomic/WPCloud) ingests GitHub
#   *releases* (zip assets), keyed by the `<pkgName>@` tag prefix. At cutover the
#   monorepo has published nothing, and multi-semantic-release won't republish a
#   package's current version (there are no commits since its baseline). So
#   before Atomic can flip its source to the monorepo, the monorepo must carry
#   each plugin/theme's current release-with-zip so externals reaches production
#   parity. See publish-baseline-releases-plan.md.
#
#   The created `<pkgName>@<version>` tag doubles as the multi-semantic-release
#   baseline, so this run also replaces seed-baseline-tags.sh at cutover: msr
#   continues from these tags, no reborn-at-1.0.0, no E2BIG.
#
# How it sources the zips:
#   It does NOT rebuild. It downloads the actual zip asset(s) attached to each
#   plugin/theme's latest non-prerelease GitHub release in the LEGACY repo
#   (Automattic/<repo>) and re-attaches them to a monorepo release tagged
#   `<pkgName>@<version>` at the cutover commit. Those are the exact artifacts
#   production runs today — byte-for-byte parity, no build divergence.
#
#   Packages with no legacy release+zip (the shared npm libs, and the
#   never-released newspack-story-budget) get a tag-only release: enough to serve
#   as the msr baseline, but no artifact. Externals does not serve those anyway
#   (shared libs ship via npm). Story-budget is logged as a warning so the
#   operator can decide whether it needs delivering separately.
#
# Usage (env-driven, to match the workflow):
#   TARGET=<sha>      Commit to tag the monorepo releases at (default: HEAD).
#   REPO=<owner/name> Target monorepo repo for the releases (default: gh's
#                     current repo). Set for sandbox rehearsal.
#   DRY_RUN=1         Resolve + download nothing is published; print only.
#   GITHUB_TOKEN      Required by gh (read legacy repos, write the monorepo repo).
#
# Part of the migration tooling — intended to be deleted after cutover.

set -euo pipefail

repo_root=$(git rev-parse --show-toplevel)
cd "$repo_root"

# shellcheck source=bin/migration/lib-versions.sh
. "$repo_root/bin/migration/lib-versions.sh"

TARGET="${TARGET:-HEAD}"
DRY_RUN="${DRY_RUN:-0}"
REPO="${REPO:-}"

# Resolve TARGET to a concrete sha so every package's release points at the same
# cutover commit even if HEAD moves mid-run.
target_sha=$(git rev-parse "$TARGET")

# --repo args for the WRITE side (monorepo releases). Legacy READS always pass
# their own explicit --repo Automattic/<name>, so they are unaffected by this.
gh_repo_args=()
[ -n "$REPO" ] && gh_repo_args=(--repo "$REPO")

scratch=$(mktemp -d)
trap 'rm -rf "$scratch"' EXIT

published=()    # sourced from a real legacy release zip
tag_only=()     # baseline tag with no artifact (shared libs)
warned=()       # tag-only where a zip was arguably expected (story-budget)
skipped=()      # already published
failed=()

# Already published on the monorepo? A release for this tag existing means a
# prior run (or the real cutover) handled it. gh release create reuses an
# existing tag, so a tag-without-release is still treated as not-yet-done.
release_exists() {
  gh release view "$1" "${gh_repo_args[@]+"${gh_repo_args[@]}"}" > /dev/null 2>&1
}

# Create the monorepo release. $1=tag, rest=asset paths (may be none).
create_release() {
  local tag="$1"; shift
  gh release create "$tag" \
    "${gh_repo_args[@]+"${gh_repo_args[@]}"}" \
    --target "$target_sha" \
    --title "$tag" \
    --notes "Baseline release at monorepo cutover (sourced from the legacy production release)." \
    "$@"
}

publish_one() {
  local dir="$1"
  local pj="$dir/package.json"
  [ -f "$pj" ] || return 0
  is_releasable "$dir" || return 0

  local pkg_name
  pkg_name=$(pkg_field "$pj" 'name')
  [ -n "$pkg_name" ] || { echo "WARN: no name in $pj, skipping" >&2; return 0; }

  local legacy legacy_tag zip_assets
  legacy=$(legacy_repo "$dir")
  legacy_tag=$(gh_latest_tag "$legacy")
  # Newline-separated *.zip asset names on that legacy release (empty if the
  # release carries none — e.g. newspack-scripts publishes to npm, no zip).
  zip_assets=""
  if [ -n "$legacy_tag" ]; then
    zip_assets=$(gh release view "$legacy_tag" --repo "Automattic/$legacy" \
      --json assets --jq '.assets[].name | select(endswith(".zip"))' 2>/dev/null || true)
  fi

  # --- Path 1: legacy release with zip asset(s) → source them. -----------------
  if [ -n "$zip_assets" ]; then
    local version="${legacy_tag#v}"
    local tag="$pkg_name@$version"
    if release_exists "$tag"; then
      skipped+=("$tag (release already exists)")
      return 0
    fi

    if [ "$DRY_RUN" = "1" ]; then
      echo "    [dry-run] $tag  <- Automattic/$legacy@$legacy_tag  assets: $(echo "$zip_assets" | paste -sd', ' -)"
      published+=("$tag (dry-run)")
      return 0
    fi

    local dl="$scratch/$pkg_name"
    mkdir -p "$dl"
    echo "==> [$pkg_name] downloading Automattic/$legacy@$legacy_tag"
    if ! gh release download "$legacy_tag" --repo "Automattic/$legacy" --dir "$dl" --pattern '*.zip'; then
      failed+=("$tag (could not download zip assets from Automattic/$legacy@$legacy_tag)")
      return 0
    fi
    local -a assets=()
    local z
    while IFS= read -r z; do assets+=("$z"); done < <(find "$dl" -maxdepth 1 -name '*.zip' | sort)

    echo "==> [$pkg_name] creating $tag at $target_sha (${#assets[@]} asset(s))"
    if create_release "$tag" "${assets[@]}"; then
      published+=("$tag (<- Automattic/$legacy@$legacy_tag, ${#assets[@]} zip)")
    else
      failed+=("$tag (gh release create failed)")
    fi
    return 0
  fi

  # --- Path 2: no legacy zip → tag-only baseline. ------------------------------
  # Version from the max of legacy release / npm / package.json (shared libs are
  # npm-authoritative; this matches seed-baseline-tags.sh).
  local version
  version=$(resolve_version "$dir" "$pkg_name")
  if [ -z "$version" ]; then
    skipped+=("$pkg_name (no version resolvable from npm or package.json)")
    return 0
  fi
  local tag="$pkg_name@$version"
  if release_exists "$tag"; then
    skipped+=("$tag (release already exists)")
    return 0
  fi

  # A plugin/theme reaching here means production has no release to mirror — flag
  # it. Shared libs (packages/*) are expected to be tag-only.
  local note="tag-only baseline (no legacy release zip)"
  case "$dir" in
    packages/*) tag_only+=("$tag — $note") ;;
    *) warned+=("$tag — $note; externals will NOT get a zip for this package") ;;
  esac

  if [ "$DRY_RUN" = "1" ]; then
    echo "    [dry-run] $tag  (tag-only, no artifact)"
    return 0
  fi
  echo "==> [$pkg_name] creating tag-only $tag at $target_sha"
  if ! create_release "$tag"; then
    failed+=("$tag (gh release create failed)")
  fi
}

echo "==> Publishing baseline releases at $target_sha (${TARGET})"
[ -n "$REPO" ] && echo "==> Target repo: $REPO"
[ "$DRY_RUN" = "1" ] && echo "==> DRY RUN — nothing will be published"

for dir in plugins/* themes/* packages/*; do
  [ -d "$dir" ] || continue
  publish_one "$dir"
done

echo ""
echo "==> Published (sourced from legacy) ${#published[@]}:"
for r in "${published[@]:-}"; do [ -n "$r" ] && echo "    $r"; done
echo "==> Tag-only baselines ${#tag_only[@]}:"
for t in "${tag_only[@]:-}"; do [ -n "$t" ] && echo "    $t"; done
if [ "${#warned[@]}" -gt 0 ]; then
  echo "==> WARNINGS ${#warned[@]}:"
  for w in "${warned[@]:-}"; do [ -n "$w" ] && echo "    $w"; done
fi
echo "==> Skipped ${#skipped[@]}:"
for s in "${skipped[@]:-}"; do [ -n "$s" ] && echo "    $s"; done
echo "==> Failed ${#failed[@]}:"
for f in "${failed[@]:-}"; do [ -n "$f" ] && echo "    $f"; done

# Non-zero exit if any package failed, so the workflow surfaces it.
[ "${#failed[@]}" -eq 0 ]
