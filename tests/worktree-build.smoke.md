# Worktree workspace-member build smoke (manual; needs Docker + a built workspace)

1. **Dual mount on create:** `n env create dbuild --worktree newspack-newsletters:<branch>`;
   `grep newspack-newsletters docker-compose.env-dbuild.yml` shows BOTH
   `:/newspack-plugins/newspack-newsletters` and `:/newspack-monorepo/plugins/newspack-newsletters`.
2. **In-place build at --build-worktrees, no install:** record `shasum -a 256 pnpm-lock.yaml
   node_modules/.modules.yaml` at the root, then `n env up dbuild --build-worktrees` (`--build` is an
   alias). The log shows "Copying built assets for newspack-newsletters...", then
   "Building newspack-newsletters in place (--filter <pkg-name>)..." and no `pnpm install`. The
   worktree's `worktrees/<sb>/plugins/newspack-newsletters/dist/` is freshly built (timestamps newer
   than root's) and served at `https://dbuild.test/`. The root checksums are unchanged.
3. **Rebuild reflects worktree edits:** edit a source file in the worktree
   (`worktrees/<sb>/plugins/newspack-newsletters/src/...`), then rerun
   `n env up dbuild --build-worktrees` → the rebuilt asset (worktree `dist/`) is what the env serves,
   with NO copy-to-root step.
3a. **A build the root can't satisfy fails:** add `import x from 'not-installed-xyz';` to a worktree
   entry point and rerun → `Error: build failed for newspack-newsletters: the branch imports a module
   the root has not installed`, exit 1, root checksums unchanged. Revert the import. A branch newer
   than the root that uses a new `newspack-components` export fails the same way with "the root
   checkout is behind the branch".
4. **Migration heals an old env:** on an env created before this change, `n env up <name>` prints
   "added workspace-member mount(s)" and the compose now has the `:/newspack-monorepo/...` line;
   re-running `up` does not add it again (idempotent).
5. **Tier-2 unchanged:** a standalone-repo worktree env still shows only its single
   `/newspack-plugins/<repo>` mount and still gets the `cp -al` assets.
6. `bash -n bin/env.sh` clean (`bin/worktree-mounts.sh` was removed; its parsers now live inline in `env.sh`); `bash tests/worktree-mounts.test.sh` → 5 passed (sources `env.sh` and drives `parse_worktree_mount`/`each_worktree_in_env` directly).
