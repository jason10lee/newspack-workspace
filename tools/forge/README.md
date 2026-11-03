# forge

The dispatcher for forge runs. A forge run works one Linear issue that the
operator named to it, unattended, and stops at delivery; the operator then
chooses rework, keep or PR. The design is
`_tooling/specs/2026-10-22-forge-design.md` in the agent-knowledge repo.

This is fork-trunk tooling. It is never pushed to `origin`.

## Commands

The dispatcher session runs these from the root checkout, following the
`forge-dispatch` skill. Run sessions never do.

- `forge launch <ISSUE> [--profile NAME]` checks the issue, takes a pool
  directory, pins the tooling, starts a background session and confirms it.
  It queues the issue when the slot or the pool is full, and routes a
  Security-labeled issue to a supervised `autofix-secure` session without
  writing anything.
- `forge next` launches the head of the queue when the slot is free.
- `forge check` applies the liveness rule to every run holding the slot. A
  session is gone when `claude agents` shows it `done` or `stopped` with no
  process. One waiting on a question shows `blocked`, at first with its
  process and later without one; `claude attach` resumes it either way, so
  `check` reports it as `WAITING` and never stops it or judges it stalled. Once
  answered, the run is timed from the last check that saw it waiting. A state
  `check` does not know is reported by name as `UNKNOWN-STATE` and changes
  nothing. A gone session is stopped; a run with no progress past the stage bound is
  first marked, and stopped only if a check at least `FORGE_STALE_RECHECK`
  seconds later (default 2700) still sees no progress, since the clock
  includes the machine's sleep and a review round records none until it ends. A mark set just before a long sleep can
  still stop a run on the first check after the wake. A stopped run is
  marked `bailed-dead` and its Linear fields are restored under the forge
  grant, unless it delivered first.
- `forge status` lists forge runs, the queue and the secure queue.
- `forge rework <run> <suggestion>` relaunches a delivered, stopped or dead run,
  unless its issue now has a PR or the Security label.
- `forge retire <run>` records the operator's decision and frees the pool
  directory; a worktree inside it must be clean.
- `forge batch <run>` builds the publish batch for a delivered run from what
  `pr.sh prepare` checked, refusing when the branch or a body has changed
  since, or when the issue now has a PR or the Security label. The
  dispatcher previews it with `batch-approval` and runs it only after the
  operator approves.

## Run sessions

A run follows the `forge` skill, which its brief names, and calls
`bin/forge-run` from its pinned tooling for the steps that must not depend on
judgment:

- `forge-run state <run>` prints the stage, whether the run holds its claim,
  the fresh-test mark and the operator's rework suggestion.
- `forge-run claim <run>` waits for the dispatcher to confirm the launch, then
  claims the issue. A rework whose claim still holds is checked, not claimed
  again, so the values a later restore puts back stay the original ones.
- `forge-run security-check <run>` re-reads the issue's labels at a stage
  boundary.
- `forge-run notify <run> <message>` posts a macOS notification titled with
  the issue. The three endings send one on their own; a run calls this when
  it waits on the operator.
- `forge-run deliver <run> <packet>`, `forge-run stop <run> <packet> <reason>`
  and `forge-run route-secure <run> <packet>` are the three ways a run ends.
  Each needs a packet in the run directory, and the last two restore the claim.

## What a run gets

- **A background session** (`claude --bg`), named after the run. Watch runs with
  `claude agents`; open one with `claude attach <id>`.
- **A pool directory** under `~/.local/state/newspack/forge/dirs/<profile>/<n>`.
  The first launch into a new one stops with `ABANDONED … not trusted`: run
  `claude` there once, accept the trust prompt, quit, and launch again. Each
  directory asks once.
- **Pinned tooling**: a read-only export of `tools/` and `.claude/skills/` from
  local `main` at launch, under `~/.local/state/newspack/forge/tooling/<sha>`.
- **Settings** that point the scripts at the real workspace and runs
  directory, and carry the forge state dir and the run's launch wait. The run
  gets no `AUTOFIX_*` or `FORGE_*` variable, or session id, from the
  dispatcher's shell. The settings also install `hooks/mcp-read-only.sh`,
  which denies every MCP tool not listed in `mcp-read-tools.txt`. Add a tool
  there only after checking that it cannot write outside the run's env. The
  browser tools are the one case of acting on a site: they serve a review's
  live tier, navigation passes only within the run's env host, and page
  scripts stay denied. A click on an outbound link can still leave that host.
  The hook does not stop a
  run's Bash from writing with the Linear key in the workspace `.env`; the
  spec's open question 5 tracks that. The settings also name their own file in
  `REPRISE_SETTINGS`, which `reprise-team-launch` passes to the review session
  it starts, so the hook covers that session too.

## Tests

`bash tools/forge/tests/run-tests.sh`. They stub `claude` and use autofix's
Linear mocks, so they make no network calls and start no sessions.
