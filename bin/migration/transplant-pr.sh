#!/usr/bin/env bash
#
# transplant-pr.sh — transplant a single open PR from a legacy Newspack repo
# into the monorepo as a new PR against newspack-workspace.
#
# Cutover-day tool. Runs end-to-end:
#
#   1. Reads the legacy PR's metadata via gh: head ref, base ref, author,
#      title, body, labels, requested reviewers, draft state.
#   2. Clones the legacy repo (or the contributor's fork for fork PRs) into a
#      bare scratch repo and fetches the PR head.
#   3. Records the fork-point SHA between legacy trunk and the PR head — the
#      boundary between "already on trunk" and "the PR's actual feature
#      commits". Recorded BEFORE sync-legacy-repo.sh prunes the trunk ref.
#   4. Runs sync-legacy-repo.sh to filter the PR branch into monorepo layout
#      (files under <target-subdir>/, tags namespaced). Determinism: the
#      filter writes commit-map mapping every legacy SHA → its filtered SHA.
#   5. Looks up the filtered fork-point in the commit-map.
#   6. In the workspace clone (the directory this script is run from),
#      rebases just the feature commits — filtered_fork_point..filtered_tip —
#      onto the monorepo's chosen base (origin/trunk by default), with -X
#      theirs so the PR's intent wins on every conflict.
#   7. Applies a single "finalize" commit that fixes things the PR's commits
#      can't reasonably get right against monorepo layout: drops legacy
#      <target>/.github/ and <target>/package-lock.json, restores workspace:*
#      for the four hoisted packages (newspack-{scripts,components,colors,
#      icons}), and runs normalize_package_repos so the repository field
#      points at newspack-workspace. See lib.sh for the underlying helpers.
#   8. Pushes the result to origin as pr-import/<repo>/<N>.
#   9. Opens a new PR in newspack-workspace with the original metadata copied
#      across, plus a redirect block linking back to the legacy PR.
#  10. (Optional, --close-source) Posts a redirect comment on the legacy PR
#      and closes it.
#
# On unresolvable conflicts: the rebase is aborted, the conflicted state is
# pushed to pr-import/conflicts/<repo>-<N>, and a draft PR with the
# transplant-conflict label is opened for manual resolution. The legacy PR
# is left open so the source of truth isn't lost.
#
# Caveats called out in code comments below:
#   • newspack-plugin PRs that touch plugins/newspack-plugin/packages/{colors,
#     components,icons}/ need the same path-rerouting that sync-legacy.sh
#     applies. The post-rebase finalize step detects these and either moves
#     the file or aborts to manual resolution.
#   • Inline review comments on the legacy PR can't be relocated (they
#     reference SHAs that no longer exist after rebase). The redirect block
#     in the new PR's body links to the original review thread URL.
#   • Fork PRs from external contributors: the rebase preserves commit
#     authorship via Author headers, so the contributor still gets credit on
#     the new PR's diff. They can't push more commits to the
#     org-owned pr-import branch though — the redirect comment instructs
#     them to rebase their fork against newspack-workspace and submit a
#     follow-up.
#
# Usage:
#   bin/migration/transplant-pr.sh <repo> <pr-number> [options]
#
# Options:
#   --dry-run               Print intended pushes / gh calls without running.
#   --close-source          Close the legacy PR after success (default off).
#   --target-base <ref>     Monorepo base branch (default origin/trunk).
#   --scratch <dir>         Scratch dir (default /tmp/transplant-pr).
#
# Environment:
#   LEGACY_SYNC_GH_TOKEN    Org-scoped token if the legacy repo is private
#                           (currently only newspack-story-budget).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
. "$SCRIPT_DIR/lib.sh"

# ---------------------------------------------------------------------------
# Argument parsing.
# ---------------------------------------------------------------------------
DRY_RUN="${DRY_RUN:-0}"
CLOSE_SOURCE=0
TARGET_BASE="origin/trunk"
SCRATCH_DIR="${SCRATCH_DIR:-/tmp/transplant-pr}"
REPO=""
PR=""

usage() {
  sed -n '2,/^# ----/p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'
  exit 2
}

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run)        DRY_RUN=1; shift ;;
    --close-source)   CLOSE_SOURCE=1; shift ;;
    --target-base)    TARGET_BASE="$2"; shift 2 ;;
    --scratch)        SCRATCH_DIR="$2"; shift 2 ;;
    -h|--help)        usage ;;
    -*) echo "ERROR: unknown option $1" >&2; usage ;;
    *)
      if [ -z "$REPO" ]; then REPO="$1"
      elif [ -z "$PR" ]; then PR="$1"
      else echo "ERROR: extra positional arg $1" >&2; usage
      fi
      shift
      ;;
  esac
done
[ -n "$REPO" ] && [ -n "$PR" ] || usage

export DRY_RUN

TARGET_SUBDIR=$(legacy_repo_target "$REPO") \
  || { echo "ERROR: $REPO is not in the legacy-repos manifest (lib.sh)" >&2; exit 2; }

mkdir -p "$SCRATCH_DIR"

echo "==> Transplant: Automattic/$REPO#$PR → newspack-workspace"
echo "    target subdir : $TARGET_SUBDIR"
echo "    base ref      : $TARGET_BASE"
echo "    dry run       : $DRY_RUN"
echo "    close source  : $CLOSE_SOURCE"

# ---------------------------------------------------------------------------
# Phase 1: read the legacy PR's metadata.
# ---------------------------------------------------------------------------
# All PR metadata in one gh call to keep API usage tight. The redirect
# comment uses .author.login to @mention the original author on the legacy
# PR (and credits them in the new PR's body).
META_JSON="$SCRATCH_DIR/$REPO-$PR.meta.json"
gh pr view "$PR" --repo "Automattic/$REPO" \
  --json number,title,body,headRefName,headRefOid,headRepository,headRepositoryOwner,baseRefName,labels,assignees,reviewRequests,isDraft,author,url \
  > "$META_JSON"

PR_TITLE=$(jq -r '.title' "$META_JSON")
PR_BODY=$(jq -r '.body // ""' "$META_JSON")
PR_HEAD_REF=$(jq -r '.headRefName' "$META_JSON")
PR_HEAD_SHA=$(jq -r '.headRefOid' "$META_JSON")
PR_BASE_REF=$(jq -r '.baseRefName' "$META_JSON")
PR_AUTHOR=$(jq -r '.author.login' "$META_JSON")
PR_URL=$(jq -r '.url' "$META_JSON")
PR_IS_DRAFT=$(jq -r '.isDraft' "$META_JSON")
HEAD_OWNER=$(jq -r '.headRepositoryOwner.login // empty' "$META_JSON")
HEAD_REPO_NAME=$(jq -r '.headRepository.name // empty' "$META_JSON")

echo "    head ref      : $PR_HEAD_REF @ ${PR_HEAD_SHA:0:8}"
echo "    head owner    : ${HEAD_OWNER:-(none)} (fork=$( [ "$HEAD_OWNER" != "Automattic" ] && echo yes || echo no ))"
echo "    author        : $PR_AUTHOR"

# ---------------------------------------------------------------------------
# Phase 2: bare-clone the legacy upstream and fetch both trunk and the PR
# head into named local refs. Always cloning the upstream (even for fork
# PRs) is intentional: GitHub mirrors fork PR heads as refs/pull/<N>/head on
# the upstream, so we get the head ref through normal auth without ever
# touching the contributor's fork URL.
# ---------------------------------------------------------------------------
SRC_BARE="$SCRATCH_DIR/$REPO.src.git"
if [ -n "${LEGACY_SYNC_GH_TOKEN:-}" ]; then
  CLONE_URL="https://x-access-token:${LEGACY_SYNC_GH_TOKEN}@github.com/Automattic/$REPO.git"
else
  CLONE_URL="https://github.com/Automattic/$REPO.git"
fi

if [ ! -d "$SRC_BARE" ]; then
  echo "==> Cloning $REPO (bare) into $SRC_BARE"
  git clone --bare --quiet "$CLONE_URL" "$SRC_BARE"
else
  echo "==> Reusing existing bare clone at $SRC_BARE"
  git -C "$SRC_BARE" fetch --quiet --prune origin
fi

# Fetch the PR head. refs/pull/<N>/head exists on the upstream regardless of
# whether the PR comes from a fork. Land it on a stable local ref so
# sync-legacy-repo.sh can filter it.
echo "==> Fetching refs/pull/$PR/head from upstream"
git -C "$SRC_BARE" fetch --quiet origin "+refs/pull/$PR/head:refs/heads/transplant/pr-$PR"

# ---------------------------------------------------------------------------
# Phase 3: capture the fork-point SHA in legacy-space BEFORE filtering.
# sync-legacy-repo.sh's first action is to prune all refs except the one
# being filtered, so we have to read this now.
# ---------------------------------------------------------------------------
# Resolve the legacy trunk name (some older repos still use master). Match
# the same fallback sync-legacy-repo.sh does so the fork point is computed
# against the same branch the PR was opened against.
LEGACY_TRUNK=""
for ref in "refs/heads/$PR_BASE_REF" "refs/heads/trunk" "refs/heads/master"; do
  if git -C "$SRC_BARE" show-ref --verify --quiet "$ref"; then
    LEGACY_TRUNK="${ref#refs/heads/}"
    break
  fi
done
[ -n "$LEGACY_TRUNK" ] || { echo "ERROR: no trunk-like ref found in $SRC_BARE" >&2; exit 3; }

LEGACY_FORK_POINT=$(git -C "$SRC_BARE" merge-base "$LEGACY_TRUNK" "transplant/pr-$PR")
echo "==> Legacy fork point: ${LEGACY_FORK_POINT:0:8} (against $LEGACY_TRUNK)"

# ---------------------------------------------------------------------------
# Phase 4: filter the PR branch into monorepo layout. The bare clone is
# passed by path; sync-legacy-repo.sh writes a fresh filtered bare repo to
# the output dir and won't touch the source.
# ---------------------------------------------------------------------------
FILTERED="$SCRATCH_DIR/$REPO-pr-$PR.filtered.git"
echo "==> Filtering PR branch into $FILTERED"
"$SCRIPT_DIR/sync-legacy-repo.sh" \
  "$SRC_BARE" "$TARGET_SUBDIR" "$FILTERED" "transplant/pr-$PR"

# Look up the filtered fork-point SHA in filter-repo's commit-map. The map is
# whitespace-separated "<old> <new>" lines. If the lookup fails, the PR
# branch had no commits at the fork-point boundary, which would mean the PR
# is empty or somehow malformed — bail out rather than guess.
COMMIT_MAP="$FILTERED/filter-repo/commit-map"
[ -f "$COMMIT_MAP" ] || { echo "ERROR: no commit-map at $COMMIT_MAP" >&2; exit 4; }
FILTERED_FORK_POINT=$(awk -v sha="$LEGACY_FORK_POINT" '$1==sha{print $2; exit}' "$COMMIT_MAP")
[ -n "$FILTERED_FORK_POINT" ] || {
  echo "ERROR: legacy fork point $LEGACY_FORK_POINT not found in commit-map" >&2
  exit 4
}
FILTERED_TIP=$(git -C "$FILTERED" rev-parse "refs/heads/transplant/pr-$PR")
COMMIT_COUNT=$(git -C "$FILTERED" rev-list --count "$FILTERED_FORK_POINT..$FILTERED_TIP")
echo "==> Filtered range: ${FILTERED_FORK_POINT:0:8}..${FILTERED_TIP:0:8} ($COMMIT_COUNT commits)"

if [ "$COMMIT_COUNT" -eq 0 ]; then
  echo "ERROR: PR has 0 commits ahead of legacy trunk after filtering — nothing to transplant" >&2
  exit 5
fi

# ---------------------------------------------------------------------------
# Phase 5: rebase the feature commits onto monorepo's target base.
#
# The script must be run from a workspace clone (the directory this script
# is invoked from). We fetch the filtered objects into the workspace
# repo's object store, create a fresh branch off the target base, and
# replay the filtered range with -X theirs so the PR's intent wins on
# every conflict. -X theirs at rebase time means "for hunks that conflict
# with whatever monorepo trunk has, prefer the version coming from the
# PR's commits". Almost every conflict the rebase will see comes from
# package.json drift (workspace:* vs concrete versions, repository field
# pointing at the legacy repo). We let theirs win and then fix those up
# in the finalize step.
# ---------------------------------------------------------------------------
NEW_BRANCH="pr-import/$REPO/$PR"

# Fetch the filtered objects into the workspace clone. The lhs is the local
# ref in the bare filter; the rhs is a private namespace under refs/transplant
# so we don't pollute refs/heads.
echo "==> Fetching filtered objects into workspace"
git fetch --quiet --force "$FILTERED" \
  "+refs/heads/transplant/pr-$PR:refs/transplant/$REPO/pr-$PR"

# Resolve the target base to a SHA so the worktree starts from a fixed point
# even if origin/trunk advances mid-run.
if ! BASE_SHA=$(git rev-parse --verify --quiet "$TARGET_BASE^{commit}"); then
  echo "ERROR: target base $TARGET_BASE not found. Did you fetch?" >&2
  exit 6
fi
echo "    base SHA      : ${BASE_SHA:0:8} ($TARGET_BASE)"

# Run the rebase in a dedicated worktree so it doesn't disturb the caller's
# working tree or HEAD. Trap removes it on any exit.
TXN_WORKTREE=$(mktemp -d -t transplant-pr-XXXXXX)
MAIN_REPO=$(git rev-parse --show-toplevel)
cleanup_worktree() {
  local rc=$?
  cd "$MAIN_REPO" 2>/dev/null || cd /
  git -C "$TXN_WORKTREE" rebase --abort 2>/dev/null || true
  git -C "$MAIN_REPO" worktree remove --force "$TXN_WORKTREE" 2>/dev/null || true
  git -C "$MAIN_REPO" worktree prune 2>/dev/null || true
  return "$rc"
}
trap cleanup_worktree EXIT INT TERM

# Clean up any branch left over from a previous failed run. Force-delete is
# safe: NEW_BRANCH is fully derived from <repo,pr> and is intended to be
# reproducible from scratch on every invocation.
git branch -D "$NEW_BRANCH" 2>/dev/null || true
git branch -D "pr-import/conflicts/$REPO-$PR" 2>/dev/null || true

git worktree add --quiet -b "$NEW_BRANCH" "$TXN_WORKTREE" "$BASE_SHA"
cd "$TXN_WORKTREE"

# Cherry-pick the feature commits one at a time onto the new branch, with
# automated handling of two recurring conflict shapes:
#
#   • modify/delete (DU): legacy commit modifies <target>/.github/<x> or
#     <target>/package-lock.json, both of which the monorepo intentionally
#     removed at integration time (CI runs at the root, lockfile is
#     pnpm-lock.yaml at the root). Auto-resolve by `git rm`-ing the file.
#
#   • content conflict in plugin/theme package.json from workspace:* drift,
#     newspack-{scripts,components,colors,icons} versions, or repository
#     field divergence. -X theirs already gives us the PR's content; we'll
#     post-process those in the finalize commit.
#
# Anything else: escalate. The legacy PR stays open so the source of truth
# isn't lost.
#
# We cherry-pick rather than `git rebase --onto` so the auto-resolution
# logic runs between every pick instead of only on rebase failure (which
# stops on the first conflict it can't handle).
COMMITS=$(git -C "$FILTERED" rev-list --reverse "$FILTERED_FORK_POINT..$FILTERED_TIP")
echo "==> Cherry-picking $COMMIT_COUNT commits onto ${BASE_SHA:0:8}"
PICK_LOG=/tmp/transplant-pick.log
: > "$PICK_LOG"

# Tracks whether escalation is needed after the loop.
PICK_RC=0
PICK_FAILED_AT=""

# Auto-resolves the recurring modify/delete patterns and re-stages files
# left modified by -X theirs. Returns 0 if cherry-pick can be continued,
# 1 if there's still a conflict we can't handle.
auto_resolve_conflicts() {
  # Drop legacy CI / lockfile paths the monorepo doesn't carry per-plugin.
  local resolved_any=0
  while IFS= read -r line; do
    [ -z "$line" ] && continue
    local code path
    code="${line:0:2}"
    path="${line:3}"
    case "$code" in
      DU|UD)
        case "$path" in
          $TARGET_SUBDIR/.github/*|$TARGET_SUBDIR/package-lock.json)
            git rm -f --quiet -- "$path" 2>/dev/null || true
            resolved_any=1
            ;;
        esac
        ;;
    esac
  done < <(git status --porcelain)

  # Anything left unresolved? -X theirs has already populated the working
  # tree with the PR's version for content conflicts, so just `git add` them.
  while IFS= read -r path; do
    [ -z "$path" ] && continue
    git add -- "$path" 2>/dev/null && resolved_any=1
  done < <(git diff --name-only --diff-filter=U)

  # Final check: any unmerged paths still left?
  if [ -n "$(git diff --name-only --diff-filter=U)" ]; then
    return 1
  fi
  return 0
}

for c in $COMMITS; do
  # Skip merge commits entirely. In practice these are "merge trunk into
  # feature" sync commits inside the PR's branch; the trunk-side content
  # they bring in is already on the monorepo's base (origin/trunk or
  # origin/monorepo-integration), so picking them in pulls drift between
  # legacy trunk and monorepo into the transplanted PR's diff. Skipping
  # is correct for the common case; uncommon multi-feature merges within
  # a PR would lose content here, but those are vanishingly rare. The
  # later non-merge picks reapply each contributor commit individually
  # so we don't lose the actual feature changes.
  if git -C "$FILTERED" rev-parse --verify --quiet "$c^2" > /dev/null; then
    echo "    skip merge $c" >> "$PICK_LOG"
    continue
  fi
  if git cherry-pick -X theirs --keep-redundant-commits --allow-empty "$c" \
       >> "$PICK_LOG" 2>&1; then
    continue
  fi
  if auto_resolve_conflicts; then
    # If conflict resolution dropped every hunk in the commit (e.g. its
    # only change was to a <target>/.github/ workflow we deleted), there
    # is nothing to commit and we --skip. Otherwise --continue, which
    # commits the staged content under the original author + message.
    # We can't pass --allow-empty to --continue (cherry-pick doesn't
    # take it), so we have to make this choice up front.
    if git diff --cached --quiet HEAD -- 2>/dev/null; then
      git cherry-pick --skip >> "$PICK_LOG" 2>&1 \
        || { PICK_RC=$?; PICK_FAILED_AT="$c"; break; }
    else
      git -c core.editor=true cherry-pick --continue \
        >> "$PICK_LOG" 2>&1 \
        || { PICK_RC=$?; PICK_FAILED_AT="$c"; break; }
    fi
  else
    PICK_RC=1
    PICK_FAILED_AT="$c"
    break
  fi
done

if [ "$PICK_RC" -ne 0 ]; then
  # Park the conflicted state so a human can resolve. The cherry-pick is
  # already in-progress with markers in the working tree; commit it (markers
  # and all) under a bot identity so the conflict branch reflects exactly
  # what the automation produced.
  echo "==> Cherry-pick failed at ${PICK_FAILED_AT:0:8}. Escalating."
  CONFLICT_BRANCH="pr-import/conflicts/$REPO-$PR"
  git add -A 2>/dev/null || true
  if ! git diff --cached --quiet; then
    git -c user.name="newspack-transplant-bot" \
        -c user.email="newspack-transplant-bot@users.noreply.github.com" \
        commit --no-edit \
        -m "transplant(conflict): unresolved cherry-pick of Automattic/$REPO#$PR at ${PICK_FAILED_AT:0:8}" \
        >> "$PICK_LOG" 2>&1 || true
  fi
  git cherry-pick --abort 2>/dev/null || true

  # Rename the branch to the conflict naming convention.
  git branch -M "$CONFLICT_BRANCH" 2>/dev/null || true

  cd "$MAIN_REPO"
  git_push origin "+$CONFLICT_BRANCH:$CONFLICT_BRANCH"
  gh_pr_create \
    --base "${TARGET_BASE#origin/}" \
    --head "$CONFLICT_BRANCH" \
    --draft \
    --reviewer adekbadek \
    --label transplant-conflict \
    --title "transplant conflict: $REPO#$PR — $PR_TITLE" \
    --body "$(printf 'Unresolvable conflicts during automated transplant of %s.\n\nResolve conflicts on this branch, then mark ready for review. Source PR is **left open** — close it manually after this transplant lands.\n\nLast pick log (truncated):\n```\n%s\n```\n' "$PR_URL" "$(tail -50 "$PICK_LOG")")"
  echo "==> Conflict parked at $CONFLICT_BRANCH (legacy PR left open)."
  exit 7
fi

# If the cherry-pick loop completed but every commit got --skip'd, the PR's
# changes only touched paths that don't exist in monorepo layout (e.g.
# legacy-only <target>/.github/ workflows). Don't open an empty PR — the
# monorepo-side equivalent has to be rebuilt by hand. Surface this so the
# batch report counts these explicitly.
PICKED_COUNT=$(git rev-list --count "$BASE_SHA..HEAD")
if [ "$PICKED_COUNT" = "0" ]; then
  echo "==> Noop-after-filter: every commit in $REPO#$PR was --skip'd"
  echo "    (changes only touched paths that don't exist in monorepo layout —"
  echo "    e.g. <target>/.github/ workflows. Manual port required against the"
  echo "    monorepo's root-level config.)"
  exit 9
fi

# ---------------------------------------------------------------------------
# Phase 6: finalize commit. Fixes things the per-PR commits can't reasonably
# get right when replayed against monorepo layout:
#
#   • Drop <target>/.github/ — CI runs at the monorepo root.
#   • Drop <target>/package-lock.json — monorepo uses pnpm-lock.yaml at root.
#   • Restore workspace:* for the four hoisted packages (newspack-{scripts,
#     components,colors,icons}) if any commit in the PR bumped them to a
#     concrete version.
#   • Run normalize_package_repos so repository.url resolves to the monorepo.
#   • For newspack-plugin only: relocate any files the PR added under
#     plugins/newspack-plugin/packages/{colors,components,icons}/ to the
#     workspace path packages/<x>/. If the file already exists at both old
#     and new paths after the rebase, we abort to manual resolution rather
#     than guess at a 3-way merge.
#
# All of these get squashed into a single follow-up commit so the PR's
# original commit history is preserved and the cleanup is auditable.
# ---------------------------------------------------------------------------
echo "==> Applying finalize commit"

# Drop legacy .github/ and package-lock.json under the target subdir.
git rm -rf --ignore-unmatch --quiet \
  "$TARGET_SUBDIR/.github" \
  "$TARGET_SUBDIR/package-lock.json" \
  2>/dev/null || true

# Restore workspace:* and the monorepo repository field in <target>/package.json
# only. We deliberately don't touch other plugins'/themes' package.json: any
# pre-existing drift there is unrelated to this PR and shouldn't get bundled
# into the transplant's finalize commit.
TARGET_PJ="$TARGET_SUBDIR/package.json"
if [ -f "$TARGET_PJ" ]; then
  TARGET_PJ_PATH="$TARGET_PJ" node -e '
    const fs = require("fs");
    const HOISTED = ["newspack-scripts","newspack-components","newspack-colors","newspack-icons"];
    const url = "git+https://github.com/Automattic/newspack-workspace.git";
    const pj = process.env.TARGET_PJ_PATH;
    const src = fs.readFileSync(pj, "utf8");
    const indentMatch = src.match(/\n(\t+|[ ]+)"/);
    const indent = indentMatch ? indentMatch[1] : "  ";
    const trail = src.endsWith("\n") ? "\n" : "";
    const j = JSON.parse(src);
    let dirty = false;
    for (const key of ["dependencies","devDependencies","peerDependencies"]) {
      const deps = j[key];
      if (!deps) continue;
      for (const h of HOISTED) {
        if (deps[h] != null && deps[h] !== "workspace:*") {
          deps[h] = "workspace:*";
          dirty = true;
        }
      }
    }
    const want = { type: "git", url, directory: pj.replace(/\/package\.json$/, "") };
    if (JSON.stringify(j.repository) !== JSON.stringify(want)) {
      j.repository = want;
      dirty = true;
    }
    if (dirty) {
      fs.writeFileSync(pj, JSON.stringify(j, null, indent) + trail);
      process.stdout.write("changed");
    }
  ' | grep -q changed && git add -- "$TARGET_PJ"
fi

# newspack-plugin extracted-packages relocation. The rebase's -Xtheirs may
# have created or modified files at plugins/newspack-plugin/packages/{colors,
# components,icons}/. Move them to the workspace path packages/<x>/.
EXTRACTED_CONFLICT=0
if [ "$REPO" = "newspack-plugin" ]; then
  while IFS= read -r path; do
    [ -z "$path" ] && continue
    rel="${path#plugins/newspack-plugin/packages/}"
    target="packages/$rel"
    mkdir -p "$(dirname "$target")"
    if [ -e "$target" ]; then
      # Both the old and new paths are present in the working tree. Without
      # a 3-way merge base we can't safely combine them — escalate.
      EXTRACTED_CONFLICT=1
      break
    fi
    git mv -- "$path" "$target"
  done < <(git ls-files -- \
    'plugins/newspack-plugin/packages/colors/*' \
    'plugins/newspack-plugin/packages/components/*' \
    'plugins/newspack-plugin/packages/icons/*')
fi

if [ "$EXTRACTED_CONFLICT" = "1" ]; then
  echo "==> PR touches paths under plugins/newspack-plugin/packages/{colors,components,icons}/" >&2
  echo "    that already exist at the workspace location packages/<x>/. Manual merge required." >&2
  echo "    Aborting transplant; legacy PR is left open." >&2
  exit 8
fi

# Commit the finalize step if anything changed. authorship goes to the bot;
# the original PR commits keep their own authors.
if ! git diff --cached --quiet || ! git diff --quiet; then
  git add -A
  git -c user.name="newspack-transplant-bot" \
      -c user.email="newspack-transplant-bot@users.noreply.github.com" \
      commit --quiet \
      -m "chore(transplant): normalize workspace metadata after Automattic/$REPO#$PR"
fi

# ---------------------------------------------------------------------------
# Phase 7: push and open the new PR.
# ---------------------------------------------------------------------------
cd "$MAIN_REPO"

git_push origin "+$NEW_BRANCH:$NEW_BRANCH"

# Build the new PR body. Original body verbatim, then a redirect block so
# reviewers can find the original review thread (inline comments aren't
# transplantable).
NEW_BODY=$(printf '%s\n\n---\n\n_Transplanted from %s by `bin/migration/transplant-pr.sh` as part of the monorepo cutover. Original author: @%s. Original review thread: %s_\n' \
  "$PR_BODY" "$PR_URL" "$PR_AUTHOR" "$PR_URL")

# Labels and reviewer/assignee args. Empty arrays are fine to pass through.
LABEL_ARGS=()
while IFS= read -r lbl; do
  [ -n "$lbl" ] && LABEL_ARGS+=(--label "$lbl")
done < <(jq -r '.labels[].name' "$META_JSON")
LABEL_ARGS+=(--label transplanted)

REVIEWER_ARGS=()
while IFS= read -r rev; do
  [ -n "$rev" ] && REVIEWER_ARGS+=(--reviewer "$rev")
done < <(jq -r '.reviewRequests[]?.login // .reviewRequests[]?.name // empty' "$META_JSON")

ASSIGNEE_ARGS=()
while IFS= read -r asg; do
  [ -n "$asg" ] && ASSIGNEE_ARGS+=(--assignee "$asg")
done < <(jq -r '.assignees[].login' "$META_JSON")

DRAFT_ARG=()
[ "$PR_IS_DRAFT" = "true" ] && DRAFT_ARG=(--draft)

NEW_TITLE="[$REPO] $PR_TITLE"

if [ "$DRY_RUN" = "1" ]; then
  echo "    [dry-run] would create PR: gh pr create --base ${TARGET_BASE#origin/} --head $NEW_BRANCH --title '$NEW_TITLE' (+labels +reviewers)"
else
  gh pr create \
    --base "${TARGET_BASE#origin/}" \
    --head "$NEW_BRANCH" \
    --title "$NEW_TITLE" \
    --body "$NEW_BODY" \
    "${LABEL_ARGS[@]}" \
    "${REVIEWER_ARGS[@]}" \
    "${ASSIGNEE_ARGS[@]}" \
    "${DRAFT_ARG[@]}" \
    || echo "WARN: gh pr create failed; branch is still at origin/$NEW_BRANCH"
fi

# ---------------------------------------------------------------------------
# Phase 8: close the legacy PR (opt-in via --close-source).
# ---------------------------------------------------------------------------
if [ "$CLOSE_SOURCE" = "1" ]; then
  CLOSE_COMMENT=$(printf 'Continued at the monorepo as part of the cutover. New PR: <fill in by tooling — see pr-import/%s/%s>. Closing here. Authored by @%s.\n\nIf you need to push more changes, please rebase your fork against `Automattic/newspack-workspace:trunk` (your changes are now under `%s/`) and open a follow-up PR there.' \
    "$REPO" "$PR" "$PR_AUTHOR" "$TARGET_SUBDIR")
  if [ "$DRY_RUN" = "1" ]; then
    echo "    [dry-run] would comment+close Automattic/$REPO#$PR"
  else
    gh pr comment "$PR" --repo "Automattic/$REPO" --body "$CLOSE_COMMENT" \
      || echo "WARN: gh pr comment failed for legacy $REPO#$PR"
    gh pr close "$PR" --repo "Automattic/$REPO" \
      || echo "WARN: gh pr close failed for legacy $REPO#$PR"
  fi
fi

echo "==> Transplant complete: $REPO#$PR → $NEW_BRANCH"
