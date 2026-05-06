#!/usr/bin/env bash
#
# transplant-prs-report.sh — pre-cutover snapshot of every open PR in every
# legacy Newspack repo. Output is the source-of-truth list that
# transplant-prs-batch.sh consumes (and that humans use to decide which PRs
# to merge in legacy before cutover so they don't need transplanting).
#
# Two output formats:
#   - --format json (default): a single JSON document with one entry per PR.
#     Suitable for piping into transplant-prs-batch.sh.
#   - --format table: human-readable Markdown table. Use this for sharing
#     in Slack / a planning doc.
#
# By default, dependabot PRs are EXCLUDED. Dependabot will re-open PRs
# against the monorepo's hoisted lockfile after cutover, so transplanting
# them would just create churn. Opt them back in with --include-bots.
#
# Usage:
#   bin/migration/transplant-prs-report.sh [options]
#
# Options:
#   --format json|table       Output format (default json).
#   --include-bots            Include dependabot/renovate/copilot PRs.
#   --include-drafts          Include drafts (default: included; flag is a
#                             no-op kept for symmetry with --exclude-drafts).
#   --exclude-drafts          Drop drafts from the output.
#   --output <file>           Write to file instead of stdout.

set -euo pipefail

FORMAT=json
INCLUDE_BOTS=0
EXCLUDE_DRAFTS=0
OUTPUT=""

while [ $# -gt 0 ]; do
  case "$1" in
    --format)         FORMAT="$2"; shift 2 ;;
    --include-bots)   INCLUDE_BOTS=1; shift ;;
    --include-drafts) shift ;;
    --exclude-drafts) EXCLUDE_DRAFTS=1; shift ;;
    --output)         OUTPUT="$2"; shift 2 ;;
    -h|--help)        sed -n '2,/^# Usage/p;/^# Options/,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'; exit 2 ;;
    *) echo "ERROR: unknown arg $1" >&2; exit 2 ;;
  esac
done

case "$FORMAT" in
  json|table) ;;
  *) echo "ERROR: --format must be json or table" >&2; exit 2 ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
. "$SCRIPT_DIR/lib.sh"

# Pull all open PRs from each legacy repo in parallel into per-repo JSON
# blobs in a temp dir, then jq-merge them into a single stream. gh pr list
# rate-limits gracefully; 16 concurrent calls is well inside the budget.
TMP=$(mktemp -d -t transplant-prs-report-XXXXXX)
trap 'rm -rf "$TMP"' EXIT

echo "==> Querying ${#LEGACY_REPOS[@]} legacy repos in parallel" >&2

# We don't query for newspack-scripts here even though it's in LEGACY_REPOS:
# its standalone repo is part of the workspace's packages/ tree but doesn't
# accept stand-alone PRs the same way. If you need to include it, edit the
# loop manifest below.
PIDS=()
for entry in "${LEGACY_REPOS[@]}"; do
  name="${entry%%:*}"
  if [ "$name" = "newspack-scripts" ]; then continue; fi
  (gh pr list --repo "Automattic/$name" --state open --limit 200 \
     --json number,title,author,isDraft,headRefName,headRepositoryOwner,labels,createdAt,updatedAt,url \
   | jq --arg repo "$name" '[.[] | . + {repo: $repo}]' \
   > "$TMP/$name.json" 2> "$TMP/$name.err") &
  PIDS+=($!)
done
for pid in "${PIDS[@]}"; do wait "$pid"; done

# Merge, filter, sort. Filters are applied in jq so the JSON output is the
# same shape regardless of --format.
JQ_FILTERS='.'
if [ "$INCLUDE_BOTS" = "0" ]; then
  # GraphQL surfaces dependabot as "app/dependabot" and copilot/etc as
  # "<name>[bot]". Match both patterns.
  JQ_FILTERS=$JQ_FILTERS' | map(select((.author.login | test("^app/")) | not))'
  JQ_FILTERS=$JQ_FILTERS' | map(select((.author.login | test("\\[bot\\]$"; "i")) | not))'
fi
if [ "$EXCLUDE_DRAFTS" = "1" ]; then
  JQ_FILTERS=$JQ_FILTERS' | map(select(.isDraft == false))'
fi
# Sort by repo then by PR number so the output is stable across runs.
JQ_FILTERS=$JQ_FILTERS' | sort_by(.repo, .number)'

MERGED=$(jq -s 'add' "$TMP"/*.json | jq "$JQ_FILTERS")

emit() {
  if [ -n "$OUTPUT" ]; then
    cat > "$OUTPUT"
  else
    cat
  fi
}

if [ "$FORMAT" = "json" ]; then
  printf '%s\n' "$MERGED" | emit
else
  # Markdown table. Columns: Repo, #, Title, Author, Draft?, Fork?, Updated.
  {
    printf '| Repo | # | Title | Author | Draft | Fork | Updated |\n'
    printf '| --- | ---: | --- | --- | :---: | :---: | --- |\n'
    printf '%s\n' "$MERGED" | jq -r '
      .[] |
      [
        .repo,
        ("#" + (.number|tostring)),
        (.title | gsub("\\|"; "\\|") | gsub("\n"; " ")),
        ("@" + .author.login),
        (if .isDraft then "✓" else "" end),
        (if (.headRepositoryOwner.login // "") != "Automattic" then "✓" else "" end),
        (.updatedAt | sub("T.*"; ""))
      ] | "| " + join(" | ") + " |"
    '

    # Footer with counts.
    TOTAL=$(printf '%s' "$MERGED" | jq 'length')
    PER_REPO=$(printf '%s' "$MERGED" | jq -r 'group_by(.repo) | map([.[0].repo, length] | join(": ")) | join(", ")')
    printf '\n**Total: %d PRs** (%s)\n' "$TOTAL" "$PER_REPO"
  } | emit
fi

if [ -n "$OUTPUT" ]; then
  echo "==> Wrote $(jq 'length' "$OUTPUT" 2>/dev/null || wc -l < "$OUTPUT") items to $OUTPUT" >&2
fi
