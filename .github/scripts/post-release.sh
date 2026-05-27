#!/usr/bin/env bash
#
# Post-release branch maintenance for the monorepo, run after the `release` job.
#
# After a release on the `release` branch:
#   - reset the single-serving `alpha` branch onto `release` (when the release
#     came from an alpha merge), or merge `release` into `alpha` otherwise;
#   - merge `release` back into the repository's default branch so they stay in
#     sync, notifying Slack on conflict.
#
# This lives in the monorepo (not packages/scripts, which mirrors the legacy
# newspack-scripts repo and is overwritten by the daily sync) and targets the
# repo's actual default branch rather than the hard-coded `trunk` the legacy
# script used. Pure git — no package manager required.

set -euo pipefail

# The default branch the legacy script called "trunk"; resolved dynamically so
# this works whether the repo's default is `main` or `trunk`.
DEFAULT_BRANCH=$(git remote show origin | sed -n 's/.*HEAD branch: //p')
DEFAULT_BRANCH=${DEFAULT_BRANCH:-main}

# The last commit here is the automated release commit; the one before it
# carries the merge info used to decide whether the release came from alpha.
SECOND_TO_LAST_COMMIT_MSG=$(git log -n 1 --skip 1 --pretty=format:"%s")
LATEST_VERSION_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "release")

# Notify Slack about a failed post-release merge into $1, if Slack is configured.
notify_slack() {
  local target="$1"
  if [ -z "${SLACK_CHANNEL_ID:-}" ] || [ -z "${SLACK_AUTH_TOKEN:-}" ]; then
    echo "[post-release] Missing Slack channel ID and/or token. Cannot notify."
    return
  fi
  echo "[post-release] Notifying the team on Slack."
  curl \
    --data "{\"channel\":\"$SLACK_CHANNEL_ID\",\"blocks\":[{\"type\":\"section\",\"text\":{\"type\":\"mrkdwn\",\"text\":\"⚠️ Post-release merge to \`$target\` failed for: \`$GITHUB_REPOSITORY\`. Check <$GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID|the build> for details.\"}}]}" \
    -H "Content-type: application/json" \
    -H "Authorization: Bearer $SLACK_AUTH_TOKEN" \
    -X POST https://slack.com/api/chat.postMessage \
    -s > /dev/null
}

git pull origin release
git checkout alpha

if echo "$SECOND_TO_LAST_COMMIT_MSG" | grep -q '^Merge .*alpha'; then
  echo "[post-release] Release came from the alpha branch. Resetting alpha onto release."
  # The alpha branch is single-serving; discard its history after a release.
  git reset --hard release --
  git push --force origin alpha
else
  echo "[post-release] Release came from a non-alpha branch (e.g. a hotfix). Merging release into alpha."
  if git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"; then
    git push origin alpha
  else
    git merge --abort
    echo "[post-release] Post-release merge to alpha failed."
    notify_slack alpha
  fi
fi

echo "[post-release] Merging release into $DEFAULT_BRANCH."
git checkout "$DEFAULT_BRANCH"
if git merge --no-ff release -m "chore(release): merge in release $LATEST_VERSION_TAG"; then
  git push origin "$DEFAULT_BRANCH"
else
  git merge --abort
  echo "[post-release] Post-release merge to $DEFAULT_BRANCH failed."
  notify_slack "$DEFAULT_BRANCH"
fi
