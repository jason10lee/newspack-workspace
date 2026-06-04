# My Account page without WooCommerce — Design

- **Linear:** [NPPD-1567](https://linear.app/a8c/issue/NPPD-1567/my-account-page-without-woocommerce)
- **Milestone:** My Account
- **Branch:** `feat/native-my-account`
- **Date:** 2026-06-04
- **Status:** Design approved; ready for implementation plan

## Problem

Readers need access to a "My Account" page when the **WooCommerce plugin is not active**. Today, Newspack's My Account experience is built entirely on top of WooCommerce: it overrides Woo's account templates, but those templates and the surrounding controller still depend on WooCommerce at runtime for page provisioning, endpoint/rewrite registration, page detection, URL generation, the account menu, content dispatch, and the `[woocommerce_my_account]` shortcode.

The goal is **not** to reproduce WooCommerce's commerce features (orders, payment methods, addresses). It is to let Newspack's own account experience — account settings, newsletters, delete account, logout — load at a real account endpoint without WooCommerce, while commerce/donation tabs are contributed by whichever revenue integration is active.

## Goals

- A working My Account page and sub-tab routing when WooCommerce is **not** active.
- Core account tabs work without Woo: **account settings** (display name / email with verification / password), **newsletter preferences**, **delete account**, **logout**.
- Commerce/donation tabs (orders, subscriptions, donations) are **contributed by integrations** (WooCommerce today; an in-progress **Fundraise Up** integration; future ones) through a single interface that works with or without Woo.
- **Zero behavior change** for existing sites that have WooCommerce active.

## Non-Goals

- Reimplementing WooCommerce commerce functionality (orders, payment methods, addresses, subscriptions data) without Woo.
- Building the Fundraise Up integration itself. Its tab is a **validation target** for the interface, not a deliverable of this work.
- The fuller "Approach A" inversion in which WooCommerce is migrated to consume the new shell even while active. That is a deliberate future step; this design only leaves the seam for it.

## Approach (selected: B)

Three approaches were considered:

- **A — Full inversion.** Newspack always owns the shell; WooCommerce becomes just a provider even when active. Cleanest end state, but rewrites the mature, battle-tested Woo path (payment methods, subscriptions, memberships, Stripe/Braintree, email-change, verification gating). Highest risk.
- **B — Newspack-owned shell + provider interface, activated natively only when Woo is absent. (Selected.)** Delivers the goal, gives the Fundraise Up work a real interface, and is an incremental step toward A without endangering the existing Woo experience.
- **C — WC-function polyfill shim.** Smallest diff, but brittle (collisions if Woo loads conditionally), muddy ownership, no clean contribution model. Rejected.

### Key insight

Newspack **already has** the provider interface. Reader Activation "Integrations" can declare a My Account tab via `Integration::get_my_account_menu_item()` and render it via `Integration::render_my_account_page()` (`includes/reader-activation/integrations/class-integration.php:317-341`). Today that machinery is bolted onto WooCommerce — it registers endpoints but dispatches through Woo's `woocommerce_account_{slug}_endpoint` action and injects into Woo's account menu (`includes/reader-activation/class-integrations.php:556-606`). This work **frees that mechanism from WooCommerce** and adds the core account tabs natively.

## Architecture

Introduce one new core module, `Newspack\My_Account` (`includes/reader-activation/class-my-account.php`), owning the 6 plumbing jobs independent of Woo. The existing `WooCommerce_My_Account` class is **unchanged** in this phase and keeps owning the experience when Woo is active.

```
Reader_Activation
 ├── My_Account                ← NEW core shell (Woo-independent)
 │     • provisions/locates the account page
 │     • registers rewrite endpoints + query vars
 │     • is_account_page() / get_endpoint_url()  ← native, with Woo fallback
 │     • builds the tab registry (core tabs + integration tabs)
 │     • dispatches: endpoint → template + newspack_my_account_content
 │     └── core tabs: settings, password, delete-account, logout
 │
 ├── Integrations              ← EXISTING; generalized to target My_Account
 │     • get_my_account_menu_item() / render_my_account_page()  (unchanged interface)
 │     • newsletters tab (existing), Fundraise Up tab (in progress)
 │
 └── WooCommerce_My_Account    ← EXISTING; unchanged. Acts as the "Woo provider"
       • only active when WooCommerce is present
```

### The switch

`Reader_Activation::is_enabled()` turns the feature on. `class_exists( 'WooCommerce' )` decides ownership of jobs 1–6.

- **Woo present** → `WooCommerce_My_Account` owns the shell (today's behavior, zero change). `My_Account` defers to it.
- **Woo absent** → `My_Account` owns the shell natively.

Integrations target the **same** interface in both worlds, so a tab is authored once and works with or without Woo. That is the payoff and the seam toward Approach A.

## The 6 plumbing jobs

Each job maps a current WooCommerce dependency to a Newspack-owned accessor. All current call sites switch to the accessor; the accessor delegates to Woo when Woo is active and runs natively otherwise.

| Job | Today (Woo) | Newspack accessor / native behavior |
|---|---|---|
| 1. The page | `woocommerce_myaccount_page_id` + `[woocommerce_my_account]` | `My_Account::get_page_id()`; new option `newspack_my_account_page_id`; new `[newspack_my_account]` shortcode + block render calling `My_Account::render()` |
| 2. Endpoints/routing | WC rewrite endpoints + shortcode dispatch | `add_rewrite_endpoint( $slug, EP_PAGES )` per tab on `init` + query-var registration (lifted from `class-integrations.php`); skipped when Woo active |
| 3. Page detection | `is_account_page()` | `My_Account::is_account_page()` → `is_page( get_page_id() )`; delegates to Woo when active |
| 4. URL generation | `wc_get_account_endpoint_url()` | `My_Account::get_endpoint_url( $endpoint )`; native builds from `get_page_id()` + endpoint slug |
| 5. Tab registry/order | `woocommerce_account_menu_items` filter | `My_Account::get_tabs()` = core tabs + integration tabs + `newspack_my_account_tabs` filter; also feeds the Woo menu filter when active (one source of truth) |
| 6. Content rendering/dispatch | `woocommerce_account_content` action | `My_Account::render()` reads query var → renders matching tab (core → template; integration → `render_my_account_page()`); fires new `newspack_my_account_content` action |

**Job-1 page resolution order:** Woo page (`woocommerce_myaccount_page_id`) when Woo active → else `newspack_my_account_page_id`. The page is provisioned by Reader Activation setup, mirroring how it already provisions auth pages.

**Job-2 rewrite flush:** triggered on activation and whenever the page or tab set changes.

## Provider / integration interface

The `Integration` base class already defines `get_my_account_menu_item()` and `render_my_account_page()`. Changes are small and **do not alter the author-facing interface**:

- Generalize `class-integrations.php` so it dispatches `render_my_account_page()` through `My_Account` (job 6) instead of Woo's `woocommerce_account_{slug}_endpoint`. When Woo is present, keep the Woo dispatch as an adapter.
- Document that `render_my_account_page()` must not assume WooCommerce.
- The **newsletters** tab and the in-progress **Fundraise Up** tab are the first two integration tabs validated against the native shell.

## Core tabs (decoupled from Woo)

Four core tabs ship in `My_Account`; none requires Woo.

- **Settings** (display name / email / password). The save currently rides `woocommerce_save_account_details`. Extract the shared validation + email-change-verification logic so it can be invoked from both paths. Add a native form handler (`admin_post` / `template_redirect`) doing `wp_update_user` + the existing email-change verification flow. Woo active → keep the Woo hook; Woo absent → native handler.
- **Password** (reset/set). Reuse the existing passwords module; swap its `is_account_page()` calls to `My_Account::is_account_page()`.
- **Delete account.** Already pure Newspack (`wp_delete_user`, transients, magic-link); only its page-detection/URL calls swap to the new accessors.
- **Logout.** Native `wp_logout_url()` with redirect; drops the `customer-logout` endpoint dependency when Woo is absent.

## Templates

Templates keep their location and markup. Surgically remove hard Woo calls from the **core-tab** templates only:

- `wc_get_endpoint_url( ... )` → `My_Account::get_endpoint_url( ... )`
- `wc_print_notices()` → `My_Account::print_notices()` (wraps `wc_*` when present, else a native notice store)
- `[woocommerce_my_account]` in `my-account.php` → `[newspack_my_account]`

**Commerce-tab templates** (orders / subscriptions / payment-methods / addresses) are **not** touched. They are owned by the Woo provider and simply do not appear when their provider is absent.

## Coexistence summary

| | Woo active | Woo absent |
|---|---|---|
| Shell owner | `WooCommerce_My_Account` (unchanged) | `My_Account` (native) |
| Page | Woo page | `newspack_my_account_page_id` |
| Endpoints | Woo | `My_Account` rewrite endpoints |
| Dispatch | Woo shortcode | `My_Account::render()` |
| Core + integration tabs | via Woo adapter | native |

Existing Woo sites get **zero behavior change**. Woo-less sites get the native shell.

## Testing

**PHPUnit** (guard Woo-active cases behind `class_exists`):
- Page resolution order (Woo page vs `newspack_my_account_page_id`).
- Endpoint registration: rewrite endpoints + query vars present without Woo.
- Tab registry composition and ordering (core + a fake integration tab).
- Dispatch routes to a fake integration tab's `render_my_account_page()`.
- Settings save + email-change verification without Woo.
- Delete-account flow without Woo.

**Manual:**
- Isolated env with WooCommerce deactivated (`n env create`): each core tab + the newsletters integration tab render and submit correctly.
- Regression pass on a Woo-active env: My Account unchanged.

## Risks & open questions

- **Rewrite-flush timing** on activation / page or tab changes (avoid stale 404s).
- **Standalone page chrome:** Woo provides a page template wrapper for its account page; the native path needs an equivalent block-theme/classic-theme wrapper for `[newspack_my_account]`.
- **Newsletters tab dependency:** confirm its content has no hidden `wc_*` dependency before relying on it as the validation tab.
- **Fundraise Up** integration is unbuilt; it is a validation target, not a deliverable here.

## Out of scope / future

- Approach A convergence: migrating WooCommerce to consume `My_Account` even while active.
- Any new commerce data rendering without a provider.
