#!/usr/bin/env bash
#
# Post-release branch maintenance for the monorepo, run after the `release` job.
#
# After a release on the `release` branch:
#   - reset the single-serving `alpha` branch onto `release` (when the release
#     came from an alpha merge), or merge `release` into `alpha` otherwise;
#   - restore `workspace:*` for any internal workspace dep that the release
#     concretized (see restore_workspace_deps below);
#   - merge `release` back into the repository's default branch so they stay in
#     sync (then restore workspace:* there too), notifying Slack on conflict.
#
# This lives in the monorepo (not packages/scripts, which mirrors the legacy
# newspack-scripts repo and is overwritten by the daily sync) and targets the
# repo's actual default branch rather than the hard-coded `trunk` the legacy
# script used. Uses node only for the small workspace-deps rewrite (preinstalled
# on ubuntu-latest runners); otherwise pure git.

set -euo pipefail

# The default branch the legacy script called "trunk"; resolved dynamically so
# this works whether the repo's default is `main` or `trunk`.
DEFAULT_BRANCH=$(git remote show origin | sed -n 's/.*HEAD branch: //p')
DEFAULT_BRANCH=${DEFAULT_BRANCH:-main}

# The last commit here is the automated release commit; the one before it
# carries the merge info used to decide whether the release came from alpha.
SECOND_TO_LAST_COMMIT_MSG=$(git log -n 1 --skip 1 --pretty=format:"%s")
LATEST_VERSION_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "release")

# Restore workspace:* for any internal monorepo dep
# (newspack-{scripts,components,colors,icons}) in every plugin/theme
# package.json, then commit if anything changed.
#
# Why: @semantic-release/npm's prepare step rewrites "workspace:*" to a concrete
# version in package.json before publishing to npm (npm can't consume
# workspace:*), and @semantic-release/git then commits that change to the
# `release` branch as part of the release commit. Resetting alpha onto release
# (and merging release into the default branch) carries those concrete versions
# forward, but pnpm-lock.yaml at the workspace root is keyed to workspace:* —
# the next `pnpm install --frozen-lockfile` then fails with
# ERR_PNPM_OUTDATED_LOCKFILE. Restoring workspace:* here keeps both branches
# consistent with the lockfile and lets the next trunk→alpha promotion merge
# cleanly without any manual restoration dance.
#
# The commit (when needed) carries [skip ci] so it doesn't re-trigger
# release.yml — the alpha branch tip is then a chore[skip ci], same as today,
# and the team's normal promotion merge commit later (without [skip ci]) is
# what fires release.yml.
restore_workspace_deps_and_commit() {
  local branch="$1"
  node -e '
    const fs = require("fs"), path = require("path");
    const WS_PACKAGES = ["newspack-scripts", "newspack-components", "newspack-colors", "newspack-icons"];
    const roots = ["plugins", "themes"];
    const changed = [];
    for (const r of roots) {
      if (!fs.existsSync(r)) continue;
      for (const name of fs.readdirSync(r)) {
        const pj = path.join(r, name, "package.json");
        if (!fs.existsSync(pj)) continue;
        const src = fs.readFileSync(pj, "utf8");
        const indentMatch = src.match(/\n(\t+|[ ]+)"/);
        const indent = indentMatch ? indentMatch[1] : "  ";
        const trail = src.endsWith("\n") ? "\n" : "";
        const j = JSON.parse(src);
        let dirty = false;
        for (const section of ["dependencies", "devDependencies", "peerDependencies"]) {
          if (!j[section]) continue;
          for (const pkg of WS_PACKAGES) {
            if (j[section][pkg] && j[section][pkg] !== "workspace:*") {
              j[section][pkg] = "workspace:*";
              dirty = true;
            }
          }
        }
        if (!dirty) continue;
        fs.writeFileSync(pj, JSON.stringify(j, null, indent) + trail);
        changed.push(pj);
      }
    }
    for (const f of changed) process.stdout.write(f + "\0");
  ' | while IFS= read -r -d "" f; do
    git add -- "$f"
  done
  if [ -n "$(git status --porcelain)" ]; then
    git commit -m "chore(release): restore workspace:* deps after release [skip ci]"
    echo "[post-release] Restored workspace:* deps on $branch."
  fi
}

# Notify Slack about a failed post-release merge into $1, if Slack is configured.
# $2 (optional) is a newline-separated list of conflicting files; when present
# they're named in the message so readers can tell what needs reconciling
# without opening the build log.
notify_slack() {
  local target="$1"
  local conflicts="${2:-}"
  if [ -z "${SLACK_CHANNEL_ID:-}" ] || [ -z "${SLACK_AUTH_TOKEN:-}" ]; then
    echo "[post-release] Missing Slack channel ID and/or token. Cannot notify."
    return
  fi
  echo "[post-release] Notifying the team on Slack."
  # Build the JSON payload with node (already used in this script) rather than
  # hand-rolling it: the conflict list is variable-length and newline-separated,
  # and raw newlines aren't valid inside a JSON string literal. node's
  # JSON.stringify escapes the message text correctly.
  # A merge with many conflicts could otherwise blow past Slack's 3000-char
  # section-text limit, which makes chat.postMessage reject the payload and drop
  # the whole alert — exactly the large-messy-merge case this naming exists for.
  # So cap the named list and hard-trim the text (the build link is appended last
  # and never trimmed, so it always survives).
  local payload
  payload=$(TARGET="$target" CONFLICTS="$conflicts" node -e '
    const MAX_FILES = 10;
    const MAX_TEXT = 2900;
    const conflicts = (process.env.CONFLICTS || "")
      .split("\n")
      .map((s) => s.trim())
      .filter(Boolean);
    const header = `⚠️ Post-release merge to \`${process.env.TARGET}\` failed for: \`${process.env.GITHUB_REPOSITORY}\`.`;
    const runUrl = `${process.env.GITHUB_SERVER_URL}/${process.env.GITHUB_REPOSITORY}/actions/runs/${process.env.GITHUB_RUN_ID}`;
    const footer = `\nCheck <${runUrl}|the build> for details.`;
    let body = "";
    if (conflicts.length) {
      const shown = conflicts.slice(0, MAX_FILES);
      let list = shown.map((f) => `• \`${f}\``).join("\n");
      if (conflicts.length > MAX_FILES) {
        list += `\n• …and ${conflicts.length - MAX_FILES} more`;
      }
      body = "\nConflicting files:\n" + list;
    }
    let text = header + body + footer;
    if (text.length > MAX_TEXT) {
      // Backstop for pathologically long paths: trim on a line boundary so we
      // never sever a `path` backtick pair (an unclosed backtick makes Slack
      // swallow the rest as inline code, including the build link). The MAX_FILES
      // cap above keeps this from firing in practice.
      const room = Math.max(0, MAX_TEXT - header.length - footer.length - 2);
      const trimmed = body.slice(0, room).split("\n").slice(0, -1).join("\n");
      text = header + trimmed + "\n…" + footer;
    }
    process.stdout.write(JSON.stringify({
      channel: process.env.SLACK_CHANNEL_ID,
      blocks: [{ type: "section", text: { type: "mrkdwn", text } }],
    }));
  ') || {
    # node missing/erroring must not abort the script (set -e) on this
    # already-failed path, nor leave subsequent merges + sync_failed unreported.
    echo "[post-release] Slack payload build failed; skipping notification."
    return
  }
  if [ -z "$payload" ]; then
    echo "[post-release] Empty Slack payload; skipping notification."
    return
  fi
  curl \
    --data "$payload" \
    -H "Content-type: application/json" \
    -H "Authorization: Bearer $SLACK_AUTH_TOKEN" \
    -X POST https://slack.com/api/chat.postMessage \
    -s > /dev/null
}

# Tracks whether any release -> branch sync hit a conflict. We attempt every
# sync (so each gets its own Slack ping) but exit non-zero at the end if any
# failed, so the job goes red instead of silently passing on an aborted merge.
sync_failed=0

git pull origin release
git checkout alpha

if echo "$SECOND_TO_LAST_COMMIT_MSG" | grep -q '^Merge .*alpha'; then
  echo "[post-release] Release came from the alpha branch. Resetting alpha onto release."
  # The alpha branch is single-serving; discard its history after a release.
  git reset --hard release --
  restore_workspace_deps_and_commit alpha
  git push --force origin alpha
else
  echo "[post-release] Release came from a non-alpha branch (e.g. a hotfix). Merging release into alpha."
  if git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"; then
    restore_workspace_deps_and_commit alpha
    git push origin alpha
  else
    # Capture the conflicting paths before --abort clears the unmerged state.
    conflicts=$(git diff --name-only --diff-filter=U)
    git merge --abort
    echo "[post-release] Post-release merge to alpha failed."
    notify_slack alpha "$conflicts"
    sync_failed=1
  fi
fi

echo "[post-release] Merging release into $DEFAULT_BRANCH."
git checkout "$DEFAULT_BRANCH"
if git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"; then
  restore_workspace_deps_and_commit "$DEFAULT_BRANCH"
  git push origin "$DEFAULT_BRANCH"
else
  # Capture the conflicting paths before --abort clears the unmerged state.
  conflicts=$(git diff --name-only --diff-filter=U)
  git merge --abort
  echo "[post-release] Post-release merge to $DEFAULT_BRANCH failed."
  notify_slack "$DEFAULT_BRANCH" "$conflicts"
  sync_failed=1
fi

if [ "$sync_failed" -ne 0 ]; then
  echo "[post-release] One or more post-release syncs hit conflicts (see above); failing the job."
  exit 1
fi
