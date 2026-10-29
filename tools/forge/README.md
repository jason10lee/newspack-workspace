# forge

The dispatcher for forge runs. A forge run works one Linear issue that the
operator named to it, unattended, and stops at delivery; the operator then
chooses rework, keep or PR. The design is
`_tooling/specs/2026-10-22-forge-design.md` in the agent-knowledge repo.

This is fork-trunk tooling. It is never pushed to `origin`.

## Commands

The dispatcher session runs these from the root checkout. Run sessions never do.

- `forge launch <ISSUE> [--profile NAME]` checks the issue, takes a pool
  directory, pins the tooling, starts a background session and confirms it.
  It queues the issue when the slot or the pool is full, and routes a
  Security-labeled issue to a supervised `autofix-secure` session without
  writing anything.
- `forge next` launches the head of the queue when the slot is free.
- `forge check` applies the liveness rule to every run holding the slot. A
  gone or stalled session is stopped; the run is marked `bailed-dead` and its
  Linear fields are restored under the forge grant, unless it delivered first.
- `forge status` lists forge runs, the queue and the secure queue.
- `forge rework <run> <suggestion>` relaunches a delivered or dead run.
- `forge retire <run>` records the operator's decision and frees the pool
  directory; a worktree inside it must be clean.

## What a run gets

- **A background session** (`claude --bg`), named after the run. Watch runs with
  `claude agents`; open one with `claude attach <id>`.
- **A pool directory** under `~/.local/state/newspack/forge/dirs/<profile>/<n>`.
  The first launch into a new one stops with `ABANDONED … not trusted`: run
  `claude` there once, accept the trust prompt, quit, and launch again. Each
  directory asks once.
- **Pinned tooling**: a read-only export of `tools/` and `.claude/skills/` from
  local `main` at launch, under `~/.local/state/newspack/forge/tooling/<sha>`.
- **Settings** that point the scripts at the real workspace and runs directory,
  and install `hooks/mcp-read-only.sh`, which denies every MCP tool not listed
  in `mcp-read-tools.txt`. Add a tool there only after checking that it cannot
  write. The hook does not stop a run's Bash from writing with the Linear key in
  the workspace `.env`; the spec's open question 5 tracks that.

## Tests

`bash tools/forge/tests/run-tests.sh`. They stub `claude` and use autofix's
Linear mocks, so they make no network calls and start no sessions.
