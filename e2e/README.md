# Newspack end-to-end testing

🎥 See the latest test report at https://automattic.github.io/newspack-e2e-tests.

## Setup

This suite lives in the `e2e/` directory of the [`newspack-workspace`](https://github.com/Automattic/newspack-workspace) monorepo. It is a self-contained npm project (its own `package.json`/`package-lock.json`), deliberately kept out of the pnpm workspace so the monorepo's per-package CI does not try to build or run it.

### Local setup & testing

The monorepo's `n env e2e-setup` helper does the one-time setup for you: it spins up an isolated local site, builds the Newspack plugins, installs `e2e-plugin.php`, runs `e2e-reset.sh` to create the `vanilla`/`with-woo` snapshots, and writes a matching `.env` here.

```sh
# From the monorepo root:
n env e2e-setup <name>            # build a local env wired for this suite

# Then, from this directory:
npm ci && npx playwright install
USE_SNAPSHOTS=true npm run test:snapshots   # full run
```

Other handy scripts:
- `npm t` – single run (no snapshot switching)
- `npm run test:ui` – run with the Playwright UI
- `npm run codegen -- <site-url>` – open the codegen recorder

For payments-dependent tests (`donations`), wire Stripe test keys – see "Payments" below. To reset the site by hand, re-run `e2e-reset.sh` inside the env container.

### CI testing

CI runs nightly on **TeamCity** (internal) against a dedicated staging site. The build's VCS root is this monorepo (`Automattic/newspack-workspace`) with the build working directory set to `e2e/`; the steps are `npm ci` → `npx playwright install` → `USE_SNAPSHOTS=true npm run test:snapshots`. (TeamCity project/credentials details are in the internal CI docs.)

A CI test site needs to be reachable by the CI server and accept password-only SSH authentication. The credentials for the current staging site live in the a8c secret store (internal).

1. Define all variables listed in `.env-sample` in the TeamCity build parameters
2. Also define the following:
   1. `SSH_USER` - simply a username string, e.g. `newspack-user`
   2. `SSH_HOST` - hostname of the platform, e.g. `ssh.myplatform.net`
   3. `SSH_USER_PASS` - SSH password
   4. `SSH_KNOWN_HOST` - this you can get by connecting to the platform and copying the line added to the `/root/.ssh/known_hosts` file
   5. `GITHUB_COMMITER_EMAIL`, `GIT_COMMITTER_NAME`, `GITHUB_TOKEN` – for GH pages deployment
   6. `SLACK_AUTH_TOKEN`, `SLACK_CHANNEL_ID` – for Slack notifications
3. Set up payments - see "Payments" section below

### Payments

1. Configure the Stripe gateway to use the WC Connect Stripe gateway version (*not* the "Legacy checkout experience").
1. Make sure Stripe "Link by Stripe" express checkout (in "Payment Methods") is disabled

## Snapshot switching
With `npm run test:snapshots` the test runner will switch between two snapshots: a vanilla Newspack site and a Newspack site with WooCommerce. The test site needs to have twp snapshots available: `vanilla` and `with-woo`. [See snapshot docs](https://github.com/automattic/newspack-manager?tab=readme-ov-file#site-testing-snapshots) for more details on how to set up snapshots.

Because tests can't run in any order when testing both snapshots, the test runner will run all tests in order. The dependencies in the `playwright.config.ts` file are a bit complicated, but the order is as follows:
```
USE_SNAPSHOTS is TRUE (with workers: 1):

🔧 SETUP PHASE
┌─────────────────┐
│  setup-vanilla  │  (switches to vanilla snapshot)
└─────────┬───────┘
          │
🧪 VANILLA TESTS PHASE
          ▼
┌─────────────────┐
│ Vanilla Desktop │  (@vanilla tests)
│     Chrome      │
└─────────┬───────┘
          │
          ▼
┌─────────────────┐
│ Vanilla Mobile  │  (@vanilla tests) 
│     Chrome      │  ← LAST @vanilla test
└─────────┬───────┘
          │
🔧 WOO SETUP PHASE
          ▼
┌─────────────────┐
│ setup-with-woo  │  (switches to Woo snapshot)
└─────────┬───────┘
          │
🧪 WOO TESTS PHASE
          ▼
┌─────────────────┐
│ With Woo Desktop│  (@with-woo tests. Depends on setup-with-woo that in turn depends on Vanilla in Mobile Chrome to finish)
│     Chrome      │
└─────────┬───────┘
          │
          ▼
┌─────────────────┐
│ With Woo Mobile │  (@with-woo tests)
│     Chrome      │
└─────────────────┘
```

If you want to exclude media from snapshots (and that is proabably a good idea for e2e testing), set the `NP_MANAGER_SNAPSHOTS_EXCLUDE_MEDIA` constant to true.

## Writing tests

Tests can be written by hand in the `tests` directory, or with the help of Playwright codegen. To use the latter option, run `npm run codegen -- <site-url>`. When you're done, copy and paste the code to `tests/<test-name>.spec.js`, adjust, and submit the changes in a PR.

If the tests manipulate any persistent items (anything in the DB), reset commands should be added to the `/bin/e2e-reset.sh` script. In the future, if that's too brittle, we might opt for a full reset, though.

## Resetting the test site
The test site can be reset by running the `e2e-reset.sh` script. Please note that on initial setup of the site, you will need to set up the Stripe test connection manually or put this in an `.env` file in the same dir as the `e2e-reset.sh`:
```
STRIPE_PUB_KEY=<the-pub-key>
STRIPE_SECRECT_KEY=<tha-secret-key>
```
Without these keys the Stripe gateway is left unconfigured, so `@with-woo` tests that complete a checkout (e.g. `donations.spec.ts`) cannot pass. Tests that only exercise the reader/account flow (e.g. `reader-registration.spec.ts`) do not need them.
