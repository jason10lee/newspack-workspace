# Migrating standalone repos to the typed `repos/{plugins,themes}` layout

Standalone checkouts now live at `repos/plugins/<name>` (plugins) or
`repos/themes/<name>` (themes) — auto-discovered by `bin/link-repos.sh`, `n`
cwd-detection, and `get_repo_host_path` (#177/#178). If you adopted the earlier
`repos/<name>` convention, migrate with `bin/migrate-standalone-repos.sh`.

## Requirements

- bash 3.2+ (macOS default) and **git ≥ 2.30** (the move path uses
  `git worktree repair`).

## Dry run first (always)

```bash
bin/migrate-standalone-repos.sh --all                 # classify every bare repos/<name>
bin/migrate-standalone-repos.sh newspack-community    # a single repo
```

Outcomes per repo:

| Plan label | Meaning |
|---|---|
| `MOVE` | Genuine standalone → typed path (plugin vs theme detected by content). |
| `REMOVE` | Clean monorepo duplicate (identical content, no unique git state) → backed up to trash, then removed. |
| `REFUSE` | Dirty tree / unpushed commits / stash entries / content divergent from the monorepo copy — handle by hand. |
| `REPORT` | A typed copy already exists *and* a stale bare `repos/<name>` remains (partial prior migration) — inspect/remove by hand. |
| `SKIP` | Already on the typed layout. |

## Before applying: free the directory

A repo can't be moved while it's pinned open. `--apply` runs a **pre-flight check**
and refuses (printing the exact `docker stop`) when a running container
bind-mounts the whole `./repos` or the target repo — on macOS, Docker's file
sharing makes the host `mv` fail with `Permission denied` otherwise. Most envs
mount the whole `./repos`, so in practice **all running envs must be stopped**
before a migration:

```bash
docker stop <names printed by the tool>   # or: n stop / n env down <name>
```

Also make sure **no shell or editor session is `cd`'d into `repos/<name>`** (the
directory you're moving) — including the terminal you run the tool from.

## Apply

```bash
bin/migrate-standalone-repos.sh newspack-community --apply
```

- **Moves** preserve everything and repair linked worktrees (`git worktree
  repair`); if any worktree fails to resolve afterward, the move is rolled back.
- **Removals** back up to `repos/.migration-trash/<UTC-ts>/<name>` first. Restore
  with `mv repos/.migration-trash/<ts>/<name> repos/<name>`.
- **Refusals / reports** are never touched — resolve the unique state
  (commit/push/unstash) and re-run, or migrate by hand.

## After applying

Any env that bind-mounts `./repos` must relink so the container picks up the
typed location: `n env restart <name>` (re-runs `link-repos.sh` inside the
container). The script prints the affected env names after the plan.

## Notes

- **Idempotent:** re-running skips already-typed repos; a partial prior
  migration surfaces as `REPORT`.
- **Plugin vs theme** is detected by content: a root `style.css` with a
  `Theme Name:` header, or a root `theme.json` *not* corroborated as a plugin by
  a root PHP file carrying a `Plugin Name:` header.
- **Safety scope:** the unique-state guard covers dirty trees, unpushed commits,
  and stash entries. Commits reachable only from a detached HEAD (no branch ref)
  are *not* detected — but removal is backup-to-trash, so such a checkout is
  recoverable from `repos/.migration-trash/`. Inspect anything you're unsure of
  before `--apply`.
- `repos/` is gitignored, so moves/removes never touch a git index.
