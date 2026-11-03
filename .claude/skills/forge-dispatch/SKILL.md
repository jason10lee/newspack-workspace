---
name: forge-dispatch
description: Run the forge dispatcher. Launch forge runs on Linear issues the operator names, check on them, and carry out the operator's decision when one delivers (rework, keep, or publish through an approved batch). Use when the operator says "forge NPPM-1234", asks about forge runs, or decides on a delivered run.
---

# forge-dispatch

The forge takes an issue the operator names to a reviewed local branch, unattended, and stops at delivery. This session is its dispatcher: it holds the queue, starts each run as a background session, watches it, and acts on the operator's decision. The runs do the work, each following the `forge` skill. The design is `_tooling/specs/2026-10-22-forge-design.md` in the agent-knowledge repo, and `tools/forge/README.md` describes the commands.

Run every command from the root checkout, `~/Repositories/A8C/newspack-workspace.git`, which stays on `main`. Give `launch`, `next` and `rework` a Bash timeout of 600000: each can wait for a session to start, and the default two minutes can cut one off partway.

## Launch

```
bash tools/forge/bin/forge launch <ISSUE> [--profile <name>]
```

`newspack-workspace` is the default profile, for product code under `plugins/` and `themes/`. Use `newspack-workspace-tooling` for this machine's tooling layer: `n`, `bin/`, `config/`, `tools/` and `.claude/skills/`. A run's tooling is pinned to local `main` at launch, so merge any tooling change the run needs first.

| Output | Meaning |
|---|---|
| `LAUNCHED` | The run is working. `claude agents` lists it under its run id. |
| `QUEUED` | The slot or the pool is busy. `forge next` starts it later. |
| `ALREADY` | A run of this issue is working or waiting on a decision. |
| `ROUTED-SECURE` | The issue carries the Security label. Tell the operator. Never start `autofix-secure` yourself: it needs the operator at every gate. |
| `EXISTING-PR` | The issue already has a PR. Nothing was written. |
| `ABANDONED` with "not trusted" | Ask the operator to run `claude` in that directory once, accept the trust prompt and quit. Then launch again. |
| `ABANDONED` otherwise | The session did not start. Report it. |

## While runs work

- `bash tools/forge/bin/forge check` stops a run whose session died, and restores its claim. A run that has gone two hours without progress is first only marked (`STALE-MARKED`), because the clock includes the Mac's sleep; a later check at least 45 minutes on stops it if it still has none (`STALE-WAITING` means it is too soon to tell). Run `check` whenever the operator asks about runs, and before `next`. `WAITING` means the run's session ended its turn on a question: it holds the slot until the operator answers it with `claude attach <id>`, and `check` never stops it; once answered, its stall clock starts from the last check that saw it waiting. `retire` refuses a waiting run. To drop one instead, run `claude stop <id>`: the next `check` reads the session as gone, bails the run and restores its Linear claim, and `retire` then accepts it. `UNKNOWN-STATE` (it names the state it saw) and `STOP-UNCONFIRMED` change nothing; report them.
- `bash tools/forge/bin/forge next` starts the head of the queue when the slot is free.
- `bash tools/forge/bin/forge status` lists the runs, the queue and the secure queue.

Never edit a run's worktree, and never work its issue yourself.

## When a run delivers or stops

`forge status` shows `delivered` or `stopped`. The packet's path is in the run's ledger: `bash tools/autofix/bin/ledger.sh get <RID> .delivery.packet`. Present the packet to the operator, starting with **Contested decisions** for a delivery and **Why it stopped** for a stop. Do not summarize the contested decisions away: they are what the operator is there to audit. A run routed to `autofix-secure` shows `routed-secure`, and its packet names what reached outside models.

Then wait for the operator's choice.

- **Rework**: `bash tools/forge/bin/forge rework <RID> <suggestion>`. The run resumes at the stage the suggestion touches. A stopped or dead run claims the issue again first. Rework refuses once the issue has a PR, and once it carries the Security label: changes to a published run are made by hand on its branch.
- **Keep**: `bash tools/forge/bin/forge retire <RID>`. It frees the run's pool directory and keeps the branch. For a monorepo run it prints the command that destroys the env; run that only when the operator says so.
- **Publish**: only for a run that delivered. Follow the steps below.

## Publish

The PR is the forge's first write to a public surface, and it goes out as one approved batch.

1. Run `bash tools/forge/bin/forge batch <RID>`. It re-reads the issue, refusing one that now carries the Security label or already has a PR, and checks that the branch and every body are still what `pr.sh prepare` recorded. Then it writes the plan: the push of the exact head, the draft PR, the review summary comment, and the Linear closeout. It prints `BATCH=<path>`.
2. Run the humanize pass over every body in the plan, in a subagent, as the `humanize-before-post` experiment says. If you apply a finding, edit that body in the run directory, run `pr.sh prepare` again from the run's pinned tooling with the same files (`.prepared.files` in the ledger lists them), and go back to step 1.
3. Preview the plan in a command of its own, naming the plan by its literal path: `~/.agent-knowledge/bin/batch-approval preview <path>`. Put the preview's full text in your reply.
4. Wait for the operator's approval line for that batch. Nothing else counts.
5. Make each call exactly as the plan has it: each Bash command verbatim, and the Linear comment through `mcp__plugin_linear_linear__save_comment` with the plan's input. Before the push, check that the pre-push guard is armed, as `CLAUDE.local.md` says. A tooling-profile run pushes to `fork`, never to `origin`.

Then give the operator the PR's URL. Retire the run once the PR merges or closes.
