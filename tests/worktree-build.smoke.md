# Worktree workspace-member build smoke (manual; needs Docker + a built workspace)

1. **Dual mount on create:** `n env create dbuild --worktree newspack-newsletters:<branch>`;
   `grep newspack-newsletters docker-compose.env-dbuild.yml` shows BOTH
   `:/newspack-plugins/newspack-newsletters` and `:/newspack-monorepo/plugins/newspack-newsletters`,
   plus the branch's two volumes, once: `./worktrees/<sb>:/newspack-worktrees/<sb>` and
   `./node_modules:/newspack-worktrees/<sb>/node_modules:ro`.
2. **In-place build at --build-worktrees, no install:** record `shasum -a 256 pnpm-lock.yaml
   node_modules/.modules.yaml` at the root, then `n env up dbuild --build-worktrees` (`--build` is an
   alias). The log shows "Copying built assets for newspack-newsletters...", then
   "Building workspace packages for <sb>...", then
   "Building newspack-newsletters in place (--filter <pkg-name>)..." and no `pnpm install`. The
   worktree's `worktrees/<sb>/plugins/newspack-newsletters/dist/` is freshly built (timestamps newer
   than root's) and served at `https://dbuild.test/`. The root checksums are unchanged.
3. **Rebuild reflects worktree edits:** edit a source file in the worktree
   (`worktrees/<sb>/plugins/newspack-newsletters/src/...`), then rerun
   `n env up dbuild --build-worktrees` → the rebuilt asset (worktree `dist/`) is what the env serves,
   with NO copy-to-root step.
3a. **A build the root can't satisfy fails:** add `import x from 'not-installed-xyz';` to a worktree
   entry point and rerun → `Error: build failed for newspack-newsletters: the branch imports a module
   the root has not installed`, exit 1, root checksums unchanged. Revert the import. A unit that imports a
   `newspack-components` export its own branch's packages lack fails with "the branch's own
   workspace packages lack an export a unit imports" (cause `branch-export`). On an env created
   before the branch volumes existed, the same build runs from `/newspack-monorepo` and fails with
   "the root checkout is behind the branch" instead.
4. **Migration heals an old env:** on an env created before this change, `n env up <name>` prints
   "added workspace-member mount(s)" and the compose now has the `:/newspack-monorepo/...` line;
   re-running `up` does not add it again (idempotent).
5. **Tier-2 unchanged:** a standalone-repo worktree env still shows only its single
   `/newspack-plugins/<repo>` mount and still gets the `cp -al` assets.
6. `bash -n bin/env.sh` clean (`bin/worktree-mounts.sh` was removed; its parsers now live inline in `env.sh`); `bash tests/worktree-mounts.test.sh` → 5 passed (sources `env.sh` and drives `parse_worktree_mount`/`each_worktree_in_env` directly).
7. **Each branch builds against its own packages:** two worktrees on different branches, e.g.
   `n env create pkgs --worktree newspack-popups:<branch-a> --worktree newspack-blocks:<branch-b>`,
   with `<branch-b>`'s worktree created by `n worktree add --no-install` beforehand, so it has no
   host install. On each branch, add a different export to `packages/components/src/index.js`
   (`spikeOnlyA`, `spikeOnlyB`) and use it from that branch's unit. Record the root snapshot
   (`shasum pnpm-lock.yaml`, `stat` of `node_modules/.modules.yaml` and `packages/components/dist`)
   and `touch` a marker file, then `n env up pkgs --build-worktrees`. Pass when:
   - both units build, with no "was not found" warning;
   - each branch's `packages/components/dist/esm/index.js` holds its own export and not the other's;
   - the served bundles carry each branch's marker string;
   - `find node_modules packages plugins -maxdepth 3 -newer <marker>` at the root lists nothing,
     and the root snapshot is unchanged (another session's `n test-js` installs at the root, so
     check `logs/test-js/` before blaming the build for a change).
8. **The root install is read-only in the env:**
   `docker exec newspack_env_pkgs touch /newspack-worktrees/<sb>/node_modules/x` fails with
   "Read-only file system".
