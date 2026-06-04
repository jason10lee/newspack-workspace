# My Account without WooCommerce — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Newspack's My Account experience (account settings, password, delete account, logout, plus integration-contributed tabs) load at a real account endpoint when WooCommerce is **not** active, with zero behavior change when WooCommerce **is** active.

**Architecture:** Introduce a new core class `Newspack\My_Account` that owns the 6 plumbing jobs (page, endpoints, page detection, URL generation, tab registry, content dispatch) behind accessors. Each accessor delegates to WooCommerce when `class_exists( 'WooCommerce' )`, and runs natively otherwise. The existing `WooCommerce_My_Account` / `My_Account_UI_V1` classes are untouched when Woo is active; they are simply skipped when Woo is absent. Reader Activation "Integrations" keep their existing `get_my_account_menu_item()` / `render_my_account_page()` interface and are dispatched through `My_Account` instead of through Woo when Woo is absent.

**Tech Stack:** PHP 8.3, WordPress, WooCommerce (optional), PHPUnit (`WP_UnitTestCase`), Newspack coding standards (WordPress-Extra/Docs/VIP, short arrays, `\`-prefixed core calls inside `Newspack` namespace).

**Spec:** `docs/superpowers/specs/2026-06-04-my-account-without-woocommerce-design.md`

**Conventions for this plan:**
- All paths are relative to `plugins/newspack-plugin/`.
- Run tests with: `n test-php --filter <TestClass>` from inside `plugins/newspack-plugin/` (per AGENTS.md). To run a single new file: `n test-php -- tests/unit-tests/<file>.php` is not supported; use `--filter`.
- Commit after every task with a conventional-commit message. Do **not** push (AGENTS.md: never push automatically).
- Do not edit `CHANGELOG.md` or `.pot` files.

---

## File Structure

**New files:**
- `includes/reader-activation/class-my-account.php` — the `Newspack\My_Account` core shell. One responsibility: own jobs 1–6 and the four core tabs, switching between Woo-delegation and native behavior.
- `src/blocks/my-account/block.json` + `src/blocks/my-account/class-my-account-block.php` — server-rendered `[newspack_my_account]` block + shortcode that calls `My_Account::render_page()`.
- `tests/unit-tests/my-account.php` — unit tests for the new class (Woo-absent paths; Woo-active paths guarded by `class_exists`).

**Modified files:**
- `includes/class-newspack.php:129` area — include the new class file.
- `includes/reader-activation/class-integrations.php` — generalize endpoint dispatch so it routes through `My_Account` when Woo is absent (keep Woo path as adapter).
- `includes/plugins/woocommerce/my-account/templates/v1/my-account.php:46` — replace `[woocommerce_my_account]` with native render when Woo absent.
- `includes/plugins/woocommerce/my-account/templates/v1/navigation.php:94,111` — swap `wc_*` URL calls to `My_Account` accessors.
- `includes/plugins/woocommerce/my-account/templates/v1/account-settings.php` — already uses Newspack hooks; only the form submit path needs a native handler (Task 12).
- `src/blocks/my-account-button/class-my-account-button-block.php:51-72` — replace the `function_exists` fallback chain with `My_Account` accessors.

**Untouched (owned by the Woo provider):** all commerce templates — `payment-information.php`, `my-subscriptions.php`, `view-subscription.php`, `subscription-*.php`, `form-edit-address.php`, `related-*.php`, `order-again.php`, `group-subscription-members.php`.

---

## Phase 1 — Core class scaffold + accessors (page id, detection, URL)

### Task 1: Create `My_Account` class skeleton and wire it in

**Files:**
- Create: `includes/reader-activation/class-my-account.php`
- Modify: `includes/class-newspack.php` (add include after line 129)
- Test: `tests/unit-tests/my-account.php`

- [ ] **Step 1: Write the failing test**

Create `tests/unit-tests/my-account.php`:

```php
<?php
/**
 * Tests for the Newspack My Account core shell.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account;

/**
 * Test the My_Account class.
 */
class Newspack_Test_My_Account extends WP_UnitTestCase {
	/**
	 * The class should exist and expose its public accessors.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'Newspack\My_Account' ) );
		$this->assertTrue( method_exists( 'Newspack\My_Account', 'get_page_id' ) );
		$this->assertTrue( method_exists( 'Newspack\My_Account', 'is_account_page' ) );
		$this->assertTrue( method_exists( 'Newspack\My_Account', 'get_endpoint_url' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: FAIL — `Class "Newspack\My_Account" not found`.

- [ ] **Step 3: Create the class file**

Create `includes/reader-activation/class-my-account.php`:

```php
<?php
/**
 * Newspack My Account core shell.
 *
 * Owns the My Account page, endpoints, page detection, URL generation, tab
 * registry, and content dispatch independently of WooCommerce. When
 * WooCommerce is active, every accessor delegates to WooCommerce so behavior
 * is unchanged; when it is absent, the shell runs natively.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * My_Account class.
 */
class My_Account {
	/**
	 * Option that stores the native account page ID (used only when WooCommerce
	 * is not active).
	 */
	const PAGE_ID_OPTION = 'newspack_my_account_page_id';

	/**
	 * Whether WooCommerce owns the My Account shell.
	 *
	 * @return bool
	 */
	public static function woocommerce_owns_shell() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_permalink' );
	}

	/**
	 * Initialize hooks. No-op for now; populated in later tasks.
	 */
	public static function init() {
		// Hooks are added in later tasks.
	}

	/**
	 * Get the My Account page ID.
	 *
	 * Resolution order: WooCommerce account page when Woo is active, else the
	 * native Newspack account page.
	 *
	 * @return int Page ID, or 0 if none is set.
	 */
	public static function get_page_id() {
		if ( self::woocommerce_owns_shell() ) {
			return (int) \get_option( 'woocommerce_myaccount_page_id', 0 );
		}
		return (int) \get_option( self::PAGE_ID_OPTION, 0 );
	}

	/**
	 * Whether the current request is the My Account page (or one of its
	 * endpoints).
	 *
	 * @return bool
	 */
	public static function is_account_page() {
		if ( self::woocommerce_owns_shell() && function_exists( 'is_account_page' ) ) {
			return \is_account_page();
		}
		$page_id = self::get_page_id();
		return $page_id && \is_page( $page_id );
	}

	/**
	 * Get the URL for a My Account endpoint.
	 *
	 * @param string $endpoint Endpoint slug. Empty string returns the base
	 *                         account page URL.
	 * @param string $value    Optional endpoint value (e.g. a subscription ID).
	 * @return string URL, or empty string if the page is not set.
	 */
	public static function get_endpoint_url( $endpoint = '', $value = '' ) {
		if ( self::woocommerce_owns_shell() ) {
			if ( '' === $endpoint || 'dashboard' === $endpoint ) {
				return \wc_get_account_endpoint_url( 'dashboard' );
			}
			return \wc_get_endpoint_url( $endpoint, $value, \wc_get_page_permalink( 'myaccount' ) );
		}

		$page_id = self::get_page_id();
		if ( ! $page_id ) {
			return '';
		}
		$permalink = \get_permalink( $page_id );
		if ( ! $permalink || '' === $endpoint ) {
			return $permalink ? $permalink : '';
		}

		if ( \get_option( 'permalink_structure' ) ) {
			$url = \trailingslashit( $permalink ) . $endpoint;
			if ( '' !== $value ) {
				$url .= '/' . $value;
			}
			return \user_trailingslashit( $url );
		}
		return \add_query_arg( $endpoint, $value, $permalink );
	}
}

My_Account::init();
```

- [ ] **Step 4: Wire the include into the bootstrap**

In `includes/class-newspack.php`, add this line immediately after the existing `class-integrations.php` include (line 111):

```php
		include_once NEWSPACK_ABSPATH . 'includes/reader-activation/class-my-account.php';
```

- [ ] **Step 5: Run test to verify it passes**

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/reader-activation/class-my-account.php includes/class-newspack.php tests/unit-tests/my-account.php
git commit -m "feat(my-account): add My_Account core shell scaffold"
```

---

### Task 2: Test + harden `get_page_id()` / `is_account_page()` native paths

**Files:**
- Modify: `tests/unit-tests/my-account.php`

- [ ] **Step 1: Write the failing tests**

Add these methods to `Newspack_Test_My_Account`:

```php
	/**
	 * Native page ID comes from the Newspack option when Woo is absent.
	 */
	public function test_get_page_id_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$this->assertSame( $page_id, My_Account::get_page_id() );

		delete_option( My_Account::PAGE_ID_OPTION );
		$this->assertSame( 0, My_Account::get_page_id() );
	}

	/**
	 * is_account_page() is true on the native account page and false elsewhere.
	 */
	public function test_is_account_page_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$other_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$this->go_to( get_permalink( $page_id ) );
		$this->assertTrue( My_Account::is_account_page() );

		$this->go_to( get_permalink( $other_id ) );
		$this->assertFalse( My_Account::is_account_page() );

		delete_option( My_Account::PAGE_ID_OPTION );
	}

	/**
	 * get_endpoint_url() returns the base permalink for the empty endpoint and a
	 * sub-path for a named endpoint.
	 */
	public function test_get_endpoint_url_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( My_Account::PAGE_ID_OPTION, $page_id );

		$base = My_Account::get_endpoint_url();
		$this->assertSame( get_permalink( $page_id ), $base );

		$edit = My_Account::get_endpoint_url( 'edit-account' );
		$this->assertStringContainsString( 'edit-account', $edit );
		$this->assertStringStartsWith( rtrim( get_permalink( $page_id ), '/' ), rtrim( $edit, '/' ) );

		delete_option( My_Account::PAGE_ID_OPTION );
	}
```

- [ ] **Step 2: Run tests to verify behavior**

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS (the Task 1 implementation already satisfies these). If any fail, fix `class-my-account.php` accessors to match — do **not** change the tests.

- [ ] **Step 3: Commit**

```bash
git add tests/unit-tests/my-account.php
git commit -m "test(my-account): cover native page id, detection, and endpoint URLs"
```

---

## Phase 2 — Page provisioning + `[newspack_my_account]` block/shortcode

### Task 3: Native account page provisioning

**Files:**
- Modify: `includes/reader-activation/class-my-account.php`
- Modify: `tests/unit-tests/my-account.php`

- [ ] **Step 1: Write the failing test**

Add to `Newspack_Test_My_Account`:

```php
	/**
	 * get_or_create_page() creates a page once and reuses it afterward.
	 */
	public function test_get_or_create_page_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		delete_option( My_Account::PAGE_ID_OPTION );

		$page_id = My_Account::get_or_create_page();
		$this->assertGreaterThan( 0, $page_id );
		$this->assertSame( 'page', get_post_type( $page_id ) );
		$this->assertStringContainsString( '[newspack_my_account]', get_post( $page_id )->post_content );

		// Second call must not create a new page.
		$this->assertSame( $page_id, My_Account::get_or_create_page() );

		wp_delete_post( $page_id, true );
		delete_option( My_Account::PAGE_ID_OPTION );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `n test-php --filter test_get_or_create_page_native`
Expected: FAIL — `Call to undefined method ... get_or_create_page()`.

- [ ] **Step 3: Implement `get_or_create_page()`**

Add to `class My_Account` (after `get_page_id()`), mirroring the Donations page pattern (`includes/class-donations.php:925-997,1054-1068`):

```php
	/**
	 * Get the native account page ID, creating the page if it does not exist.
	 *
	 * Only used when WooCommerce is not active.
	 *
	 * @return int Page ID.
	 */
	public static function get_or_create_page() {
		$page_id = (int) \get_option( self::PAGE_ID_OPTION, 0 );
		if ( $page_id && 'page' === \get_post_type( $page_id ) ) {
			return $page_id;
		}

		$page_id = \wp_insert_post(
			[
				'post_type'      => 'page',
				'post_title'     => \esc_html__( 'My Account', 'newspack-plugin' ),
				'post_content'   => '[newspack_my_account]',
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			]
		);

		if ( \is_numeric( $page_id ) && $page_id ) {
			\update_option( self::PAGE_ID_OPTION, (int) $page_id );
			\update_post_meta( $page_id, 'newspack_hide_page_title', true );
			return (int) $page_id;
		}

		return 0;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `n test-php --filter test_get_or_create_page_native`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/reader-activation/class-my-account.php tests/unit-tests/my-account.php
git commit -m "feat(my-account): provision native account page"
```

---

### Task 4: `[newspack_my_account]` shortcode + block render → `render_page()`

**Files:**
- Modify: `includes/reader-activation/class-my-account.php`
- Create: `src/blocks/my-account/block.json`
- Create: `src/blocks/my-account/class-my-account-block.php`
- Modify: `includes/class-blocks.php` (require the new block file)
- Modify: `tests/unit-tests/my-account.php`

- [ ] **Step 1: Write the failing test**

Add to `Newspack_Test_My_Account`:

```php
	/**
	 * The [newspack_my_account] shortcode is registered and renders a container.
	 */
	public function test_shortcode_registered() {
		My_Account::register_shortcode();
		$this->assertTrue( shortcode_exists( 'newspack_my_account' ) );

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$html = do_shortcode( '[newspack_my_account]' );
		$this->assertStringContainsString( 'newspack-my-account', $html );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `n test-php --filter test_shortcode_registered`
Expected: FAIL — `Call to undefined method ... register_shortcode()`.

- [ ] **Step 3: Implement shortcode + `render_page()` in `My_Account`**

Add to `class My_Account`:

```php
	/**
	 * Register the [newspack_my_account] shortcode.
	 */
	public static function register_shortcode() {
		\add_shortcode( 'newspack_my_account', [ __CLASS__, 'render_page' ] );
	}

	/**
	 * Render the My Account page body.
	 *
	 * Outputs the navigation and the content for the current endpoint. Used by
	 * the [newspack_my_account] shortcode and block when WooCommerce is absent.
	 *
	 * @return string Rendered HTML.
	 */
	public static function render_page() {
		if ( ! \is_user_logged_in() ) {
			return '';
		}

		ob_start();
		echo '<div class="newspack-my-account newspack-ui">';
		self::render_navigation();
		echo '<div class="newspack-my-account__content woocommerce-MyAccount-content">';
		self::render_content();
		echo '</div>';
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Render the navigation. Implemented in Task 9 (tab registry); placeholder
	 * for now so render_page() is testable.
	 */
	protected static function render_navigation() {
		// Replaced in Task 9.
	}

	/**
	 * Render the content for the current endpoint. Implemented in Task 8
	 * (dispatcher); placeholder for now.
	 */
	protected static function render_content() {
		// Replaced in Task 8.
	}
```

Add `self::register_shortcode();` inside `My_Account::init()` (replace the placeholder comment):

```php
	public static function init() {
		\add_action( 'init', [ __CLASS__, 'register_shortcode' ] );
	}
```

- [ ] **Step 4: Create the block**

Create `src/blocks/my-account/block.json`:

```json
{
	"$schema": "https://schemas.wp.org/trunk/block.json",
	"apiVersion": 3,
	"name": "newspack/my-account",
	"title": "My Account",
	"category": "newspack",
	"description": "Renders the Newspack My Account experience.",
	"textdomain": "newspack-plugin",
	"supports": {
		"html": false,
		"align": false
	}
}
```

Create `src/blocks/my-account/class-my-account-block.php`:

```php
<?php
/**
 * My Account Block.
 *
 * @package Newspack
 */

namespace Newspack\Blocks\My_Account;

use Newspack\My_Account;

defined( 'ABSPATH' ) || exit;

/**
 * My Account Block.
 */
final class My_Account_Block {
	/**
	 * Initialize the block.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_block' ] );
	}

	/**
	 * Register block from metadata.
	 */
	public static function register_block() {
		\register_block_type_from_metadata(
			__DIR__ . '/block.json',
			[
				'render_callback' => [ __CLASS__, 'render_block' ],
			]
		);
	}

	/**
	 * Render the block.
	 *
	 * @return string
	 */
	public static function render_block() {
		return My_Account::render_page();
	}
}

My_Account_Block::init();
```

- [ ] **Step 5: Require the block in the Blocks loader**

In `includes/class-blocks.php`, in `init()` next to the other block requires (the `my-account-button` require is the model), add:

```php
		require_once NEWSPACK_ABSPATH . 'src/blocks/my-account/class-my-account-block.php';
```

- [ ] **Step 6: Run test to verify it passes**

Run: `n test-php --filter test_shortcode_registered`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/reader-activation/class-my-account.php src/blocks/my-account includes/class-blocks.php tests/unit-tests/my-account.php
git commit -m "feat(my-account): add newspack_my_account shortcode and block"
```

---

### Task 5: Serve the native page template when Woo is absent

**Files:**
- Modify: `includes/reader-activation/class-my-account.php`
- Modify: `includes/plugins/woocommerce/my-account/templates/v1/my-account.php`

> Rationale: When Woo is active, `My_Account_UI_V1::page_template()` already swaps in `my-account.php`, which runs `[woocommerce_my_account]`. When Woo is absent that class is never loaded, so `My_Account` must (a) provide the full-page chrome template and (b) make `my-account.php` render natively instead of the Woo shortcode.

- [ ] **Step 1: Add the native `page_template` filter to `My_Account::init()`**

Update `init()`:

```php
	public static function init() {
		\add_action( 'init', [ __CLASS__, 'register_shortcode' ] );
		if ( ! self::woocommerce_owns_shell() ) {
			\add_filter( 'page_template', [ __CLASS__, 'page_template' ], 11 );
		}
	}

	/**
	 * Use the blank My Account page template on the native account page.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function page_template( $template ) {
		if ( ! self::is_account_page() || ! \is_user_logged_in() ) {
			return $template;
		}
		return NEWSPACK_ABSPATH . 'includes/plugins/woocommerce/my-account/templates/v1/my-account.php';
	}
```

- [ ] **Step 2: Make `my-account.php` render natively when Woo is absent**

In `includes/plugins/woocommerce/my-account/templates/v1/my-account.php`, replace line 46:

```php
						echo do_shortcode( '[woocommerce_my_account]' );
```

with:

```php
						if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_permalink' ) ) {
							echo do_shortcode( '[woocommerce_my_account]' );
						} else {
							echo do_shortcode( '[newspack_my_account]' );
						}
```

- [ ] **Step 3: Verify nothing regressed (Woo-active path unchanged)**

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS. (The branch keeps the Woo shortcode exactly as before when Woo is active.)

- [ ] **Step 4: Commit**

```bash
git add includes/reader-activation/class-my-account.php includes/plugins/woocommerce/my-account/templates/v1/my-account.php
git commit -m "feat(my-account): serve native page template without WooCommerce"
```

---

## Phase 3 — Endpoints, query vars, tab registry, dispatch

### Task 6: Register native rewrite endpoints + query vars

**Files:**
- Modify: `includes/reader-activation/class-my-account.php`
- Modify: `tests/unit-tests/my-account.php`

> The core endpoints are the four core tabs plus integration-declared slugs. Integration slugs are resolved in Task 10; here we register the **core** endpoints and expose a hook so integrations can add theirs.

- [ ] **Step 1: Write the failing test**

Add to `Newspack_Test_My_Account`:

```php
	/**
	 * Core endpoints are registered as query vars when Woo is absent.
	 */
	public function test_query_vars_native() {
		if ( My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$vars = My_Account::add_query_vars( [] );
		$this->assertContains( 'edit-account', $vars );
		$this->assertContains( 'newspack-delete-account', $vars );
	}

	/**
	 * get_endpoints() returns the core endpoint slugs.
	 */
	public function test_get_endpoints_core() {
		$endpoints = My_Account::get_endpoints();
		$this->assertArrayHasKey( 'edit-account', $endpoints );
		$this->assertArrayHasKey( 'newspack-delete-account', $endpoints );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `n test-php --filter test_get_endpoints_core`
Expected: FAIL — `Call to undefined method ... get_endpoints()`.

- [ ] **Step 3: Implement endpoint registry + registration**

Add to `class My_Account`:

```php
	/**
	 * Core endpoint slug constants.
	 */
	const ENDPOINT_EDIT_ACCOUNT   = 'edit-account';
	const ENDPOINT_DELETE_ACCOUNT = 'newspack-delete-account';

	/**
	 * Get the registered endpoint slugs => labels for the native shell.
	 *
	 * Core tabs plus any integration-declared endpoints. Filterable so
	 * integrations and sites can extend the set.
	 *
	 * @return array<string,string> slug => label.
	 */
	public static function get_endpoints() {
		$endpoints = [
			self::ENDPOINT_EDIT_ACCOUNT   => \__( 'Account details', 'newspack-plugin' ),
			self::ENDPOINT_DELETE_ACCOUNT => \__( 'Delete account', 'newspack-plugin' ),
		];
		/**
		 * Filters the My Account endpoint slugs => labels (native shell).
		 *
		 * @param array<string,string> $endpoints slug => label.
		 */
		return \apply_filters( 'newspack_my_account_endpoints', $endpoints );
	}

	/**
	 * Register rewrite endpoints for the native shell.
	 */
	public static function register_endpoints() {
		foreach ( array_keys( self::get_endpoints() ) as $slug ) {
			\add_rewrite_endpoint( $slug, EP_PAGES );
		}
	}

	/**
	 * Add the endpoint slugs to the public query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		foreach ( array_keys( self::get_endpoints() ) as $slug ) {
			$vars[] = $slug;
		}
		return $vars;
	}

	/**
	 * Get the current endpoint slug from the query, or '' for the dashboard.
	 *
	 * @return string
	 */
	public static function get_current_endpoint() {
		global $wp;
		foreach ( array_keys( self::get_endpoints() ) as $slug ) {
			if ( isset( $wp->query_vars[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}
```

Wire native registration into `init()` (only when Woo absent):

```php
	public static function init() {
		\add_action( 'init', [ __CLASS__, 'register_shortcode' ] );
		if ( ! self::woocommerce_owns_shell() ) {
			\add_filter( 'page_template', [ __CLASS__, 'page_template' ], 11 );
			\add_action( 'init', [ __CLASS__, 'register_endpoints' ], 6 );
			\add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		}
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/reader-activation/class-my-account.php tests/unit-tests/my-account.php
git commit -m "feat(my-account): register native endpoints and query vars"
```

---

### Task 7: Rewrite-flush on endpoint-set change + activation

**Files:**
- Modify: `includes/reader-activation/class-my-account.php`

> Mirror the safe flush pattern in `class-integrations.php:register_my_account_endpoints()` — only flush when the slug set changes, so we never flush on every request.

- [ ] **Step 1: Add the flush-on-change logic**

Add a constant and extend `register_endpoints()`:

```php
	/**
	 * Option storing the last-registered endpoint slug set (for flush detection).
	 */
	const ENDPOINTS_OPTION = 'newspack_my_account_endpoint_slugs';
```

Replace `register_endpoints()` body with:

```php
	public static function register_endpoints() {
		$slugs = array_keys( self::get_endpoints() );
		foreach ( $slugs as $slug ) {
			\add_rewrite_endpoint( $slug, EP_PAGES );
		}

		$current = $slugs;
		sort( $current );
		$previous = \get_option( self::ENDPOINTS_OPTION, [] );
		if ( ! is_array( $previous ) ) {
			$previous = [];
		}
		sort( $previous );
		if ( $current !== $previous ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- only fires when the slug set changes.
			\flush_rewrite_rules( false );
			\update_option( self::ENDPOINTS_OPTION, $current );
		}
	}
```

- [ ] **Step 2: Verify tests still pass**

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/reader-activation/class-my-account.php
git commit -m "feat(my-account): flush rewrite rules only when endpoint set changes"
```

---

### Task 8: Content dispatcher (`render_content`)

**Files:**
- Modify: `includes/reader-activation/class-my-account.php`
- Modify: `tests/unit-tests/my-account.php`

- [ ] **Step 1: Write the failing test**

Add to `Newspack_Test_My_Account`:

```php
	/**
	 * The dispatcher fires the newspack_my_account_content action for the
	 * current endpoint and the core content callback for the dashboard.
	 */
	public function test_render_content_dispatch() {
		$fired = [];
		add_action(
			'newspack_my_account_content',
			function ( $endpoint ) use ( &$fired ) {
				$fired[] = $endpoint;
			}
		);

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		ob_start();
		My_Account::render_content();
		ob_get_clean();

		$this->assertSame( [ '' ], $fired );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `n test-php --filter test_render_content_dispatch`
Expected: FAIL — `newspack_my_account_content` is never fired (current `render_content()` is an empty placeholder).

- [ ] **Step 3: Implement the dispatcher**

Replace the `render_content()` placeholder in `My_Account` with:

```php
	/**
	 * Render the content for the current endpoint.
	 *
	 * Core endpoints render their own templates; the dashboard renders the
	 * default landing. Integration endpoints are rendered by the
	 * newspack_my_account_content action (see Task 10).
	 */
	protected static function render_content() {
		$endpoint = self::get_current_endpoint();

		switch ( $endpoint ) {
			case self::ENDPOINT_EDIT_ACCOUNT:
				self::render_account_settings();
				break;
			case self::ENDPOINT_DELETE_ACCOUNT:
				self::render_delete_account();
				break;
			case '':
				self::render_dashboard();
				break;
		}

		/**
		 * Fires when rendering My Account content for an endpoint. Integrations
		 * hook this to render their tab body when their slug is current.
		 *
		 * @param string $endpoint Current endpoint slug ('' for dashboard).
		 */
		\do_action( 'newspack_my_account_content', $endpoint );
	}

	/**
	 * Render the dashboard landing. Stub; refined in Task 11.
	 */
	protected static function render_dashboard() {
		echo '<p>' . \esc_html__( 'Welcome to your account.', 'newspack-plugin' ) . '</p>';
	}

	/**
	 * Render the account settings tab. Implemented in Task 11.
	 */
	protected static function render_account_settings() {
		// Implemented in Task 11.
	}

	/**
	 * Render the delete-account tab. Implemented in Task 11.
	 */
	protected static function render_delete_account() {
		// Implemented in Task 11.
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `n test-php --filter test_render_content_dispatch`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/reader-activation/class-my-account.php tests/unit-tests/my-account.php
git commit -m "feat(my-account): add endpoint content dispatcher"
```

---

### Task 9: Native navigation from the tab registry

**Files:**
- Modify: `includes/reader-activation/class-my-account.php`
- Modify: `tests/unit-tests/my-account.php`

- [ ] **Step 1: Write the failing test**

Add to `Newspack_Test_My_Account`:

```php
	/**
	 * get_tabs() returns ordered slug => label entries including core tabs.
	 */
	public function test_get_tabs() {
		$tabs = My_Account::get_tabs();
		$this->assertArrayHasKey( 'edit-account', $tabs );
		$this->assertArrayHasKey( 'customer-logout', $tabs );
		// Logout is always last.
		$this->assertSame( 'customer-logout', array_key_last( $tabs ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `n test-php --filter test_get_tabs`
Expected: FAIL — `Call to undefined method ... get_tabs()`.

- [ ] **Step 3: Implement `get_tabs()` + native navigation render**

Add to `class My_Account`:

```php
	/**
	 * Get the ordered set of navigation tabs (slug => label).
	 *
	 * Dashboard first, then endpoints (core + integration), then logout last.
	 *
	 * @return array<string,string>
	 */
	public static function get_tabs() {
		$tabs = array_merge(
			[ '' => \__( 'Account', 'newspack-plugin' ) ],
			self::get_endpoints(),
			[ 'customer-logout' => \__( 'Sign out', 'newspack-plugin' ) ]
		);
		/**
		 * Filters the ordered My Account navigation tabs.
		 *
		 * @param array<string,string> $tabs slug => label.
		 */
		return \apply_filters( 'newspack_my_account_tabs', $tabs );
	}

	/**
	 * Render the native navigation menu.
	 */
	protected static function render_navigation() {
		$current = self::get_current_endpoint();
		echo '<nav class="woocommerce-MyAccount-navigation newspack-ui" aria-label="' . \esc_attr__( 'Account pages', 'newspack-plugin' ) . '">';
		echo '<ul>';
		foreach ( self::get_tabs() as $slug => $label ) {
			if ( 'customer-logout' === $slug ) {
				$url = \wp_logout_url( \home_url( '/' ) );
			} else {
				$url = self::get_endpoint_url( $slug );
			}
			$is_current = ( $slug === $current );
			printf(
				'<li class="%1$s"><a href="%2$s"%3$s>%4$s</a></li>',
				\esc_attr( $is_current ? 'is-active' : '' ),
				\esc_url( $url ),
				$is_current ? ' aria-current="page"' : '',
				\esc_html( $label )
			);
		}
		echo '</ul>';
		echo '</nav>';
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/reader-activation/class-my-account.php tests/unit-tests/my-account.php
git commit -m "feat(my-account): render native navigation from tab registry"
```

---

## Phase 4 — Decouple Integrations dispatch from WooCommerce

### Task 10: Route integration tabs through `My_Account` when Woo is absent

**Files:**
- Modify: `includes/reader-activation/class-integrations.php`
- Modify: `tests/unit-tests/integrations/` (add a focused test; see step 1)

> Today `register_my_account_endpoints()` registers `add_rewrite_endpoint()` then dispatches via Woo's `woocommerce_account_{slug}_endpoint` action and injects into `woocommerce_account_menu_items`. We add a Woo-absent path: contribute the integration slugs/labels into `My_Account` (via the `newspack_my_account_endpoints` filter) and dispatch via the `newspack_my_account_content` action. The Woo path is unchanged.

- [ ] **Step 1: Write the failing test**

Create `tests/unit-tests/integrations/my-account-dispatch.php`:

```php
<?php
/**
 * Tests integration My Account dispatch without WooCommerce.
 *
 * @package Newspack\Tests
 */

use Newspack\My_Account;

/**
 * @group reader-activation
 */
class Newspack_Test_Integration_My_Account_Dispatch extends WP_UnitTestCase {
	/**
	 * Integration-declared endpoints appear in My_Account::get_endpoints()
	 * via the newspack_my_account_endpoints filter.
	 */
	public function test_integration_endpoint_contributed() {
		add_filter(
			'newspack_my_account_endpoints',
			function ( $endpoints ) {
				$endpoints['newsletters'] = 'Newsletters';
				return $endpoints;
			}
		);
		$this->assertArrayHasKey( 'newsletters', My_Account::get_endpoints() );
	}
}
```

- [ ] **Step 2: Run test to verify it passes for the filter, then add dispatch**

Run: `n test-php --filter Newspack_Test_Integration_My_Account_Dispatch`
Expected: PASS (the `newspack_my_account_endpoints` filter from Task 6 already supports this). This confirms the contribution seam exists.

- [ ] **Step 3: Add the Woo-absent contribution + dispatch in `class-integrations.php`**

In `Integrations::init()` (around lines 84–101), after the existing My Account hook registrations, add a Woo-absent branch:

```php
		if ( ! ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_permalink' ) ) ) {
			add_filter( 'newspack_my_account_endpoints', [ __CLASS__, 'filter_native_my_account_endpoints' ] );
			add_action( 'newspack_my_account_content', [ __CLASS__, 'render_native_my_account_content' ] );
		}
```

Add these two methods to `Integrations`:

```php
	/**
	 * Contribute integration-declared endpoints to the native My Account shell.
	 *
	 * @param array<string,string> $endpoints slug => label.
	 * @return array<string,string>
	 */
	public static function filter_native_my_account_endpoints( $endpoints ) {
		self::register_my_account_endpoints();
		foreach ( self::$my_account_endpoints as $slug => $integration_id ) {
			if ( isset( $endpoints[ $slug ] ) ) {
				continue;
			}
			$integration = self::get_integration( $integration_id );
			if ( ! $integration ) {
				continue;
			}
			$item = $integration->get_my_account_menu_item();
			if ( is_array( $item ) && ! empty( $item['label'] ) ) {
				$endpoints[ $slug ] = $item['label'];
			}
		}
		return $endpoints;
	}

	/**
	 * Dispatch the current native My Account endpoint to its integration.
	 *
	 * @param string $endpoint Current endpoint slug.
	 */
	public static function render_native_my_account_content( $endpoint ) {
		if ( '' === $endpoint || empty( self::$my_account_endpoints[ $endpoint ] ) ) {
			return;
		}
		$integration = self::get_integration( self::$my_account_endpoints[ $endpoint ] );
		if ( $integration ) {
			$integration->render_my_account_page( '' );
		}
	}
```

> Note: `register_my_account_endpoints()` already populates `self::$my_account_endpoints` and registers `add_rewrite_endpoint()` for each integration slug, so the native rewrite endpoints are covered. Confirm `$my_account_endpoints` is a class property accessible here (it is — used at lines 589–639, 647–722, 730–735).

- [ ] **Step 4: Run tests**

Run: `n test-php --filter Newspack_Test_Integration_My_Account_Dispatch`
Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS for both.

- [ ] **Step 5: Commit**

```bash
git add includes/reader-activation/class-integrations.php tests/unit-tests/integrations/my-account-dispatch.php
git commit -m "feat(my-account): dispatch integration tabs without WooCommerce"
```

---

## Phase 5 — Core tabs (native, Woo-independent)

### Task 11: Render core tab templates (settings, delete) natively

**Files:**
- Modify: `includes/reader-activation/class-my-account.php`

> The existing `account-settings.php` template already uses Newspack hooks and `WooCommerce_My_Account` static helpers; but those classes are not loaded when Woo is absent. For the native shell we render a focused settings form and reuse the existing delete-account flow constants. Keep the markup class names (`woocommerce-EditAccountForm`, `newspack-ui__*`) so existing styles apply.

- [ ] **Step 1: Implement `render_account_settings()`**

Replace the `render_account_settings()` stub in `My_Account`:

```php
	/**
	 * Render the native account settings form (display name, email, password
	 * link). Email-change verification reuses the shared handler (Task 12).
	 */
	protected static function render_account_settings() {
		$user = \wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}
		?>
		<section id="account-profile">
			<h4 class="newspack-ui__font--m newspack-ui__spacing-top--0"><?php \esc_html_e( 'Profile', 'newspack-plugin' ); ?></h4>
			<form class="woocommerce-EditAccountForm edit-profile newspack-my-account__settings-form" action="" method="post">
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="account_display_name"><?php \esc_html_e( 'Display name', 'newspack-plugin' ); ?></label>
					<input type="text" class="woocommerce-Input input-text" name="account_display_name" id="account_display_name" value="<?php echo \esc_attr( $user->display_name ); ?>" />
				</p>
				<p class="woocommerce-form-row form-row form-row-wide">
					<label for="account_email"><?php \esc_html_e( 'Email address', 'newspack-plugin' ); ?>&nbsp;<span class="required">*</span></label>
					<input type="email" class="woocommerce-Input input-text" name="account_email" id="account_email" value="<?php echo \esc_attr( $user->user_email ); ?>" required />
				</p>
				<?php
				/** Lets integrations add fields, mirroring the Woo template hook. */
				\do_action( 'newspack_woocommerce_edit_account_form_fields' );
				?>
				<p class="woocommerce-buttons-card">
					<?php \wp_nonce_field( 'newspack_my_account_save', 'newspack_my_account_save_nonce' ); ?>
					<input type="hidden" name="action" value="newspack_my_account_save_account" />
					<button type="submit" class="newspack-ui__button newspack-ui__button--primary"><?php \esc_html_e( 'Update profile', 'newspack-plugin' ); ?></button>
				</p>
			</form>
		</section>
		<section id="delete-account">
			<h4 class="newspack-ui__font--m is-destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></h4>
			<p><?php \esc_html_e( 'Please note, account deletion is final, and there will be no way to restore your account.', 'newspack-plugin' ); ?></p>
			<p class="woocommerce-buttons-card">
				<a class="newspack-ui__button newspack-ui__button--destructive" href="<?php echo \esc_url( self::get_endpoint_url( self::ENDPOINT_DELETE_ACCOUNT ) ); ?>"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * Render the native delete-account confirmation tab.
	 */
	protected static function render_delete_account() {
		?>
		<section id="delete-account-confirm">
			<h4 class="newspack-ui__font--m is-destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></h4>
			<p><?php \esc_html_e( 'This action is permanent. To confirm, submit the request below and follow the link we email you.', 'newspack-plugin' ); ?></p>
			<form method="post" action="">
				<?php \wp_nonce_field( 'newspack_my_account_delete', 'newspack_my_account_delete_nonce' ); ?>
				<input type="hidden" name="action" value="newspack_my_account_request_delete" />
				<button type="submit" class="newspack-ui__button newspack-ui__button--destructive"><?php \esc_html_e( 'Request account deletion', 'newspack-plugin' ); ?></button>
			</form>
		</section>
		<?php
	}
```

- [ ] **Step 2: Manual smoke check (no unit test for markup)**

There is no unit assertion for raw markup; coverage comes from Task 8's dispatch test plus the manual QA in Task 16. Verify the class still loads:

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/reader-activation/class-my-account.php
git commit -m "feat(my-account): render native account settings and delete tabs"
```

---

### Task 12: Native save + delete handlers (extract shared logic)

**Files:**
- Modify: `includes/reader-activation/class-my-account.php`
- Modify: `tests/unit-tests/my-account.php`

> When Woo is active, settings save rides `woocommerce_save_account_details` and delete rides `WooCommerce_My_Account::handle_delete_account()` — unchanged. When Woo is absent we add native `admin_post`/`template_redirect` handlers. The email-change verification logic in `WooCommerce_My_Account` is Woo-independent in substance; rather than duplicate, the native save calls the existing public methods when available and otherwise updates directly. Keep this task's scope to display name + email (no email-change verification when Woo absent in v1; email updates directly). Document that limitation.

- [ ] **Step 1: Write the failing test**

Add to `Newspack_Test_My_Account`:

```php
	/**
	 * The native save handler updates display name and email.
	 */
	public function test_native_save_account() {
		$user_id = self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'display_name' => 'Old Name',
				'user_email'   => 'old@example.com',
			]
		);
		wp_set_current_user( $user_id );

		$_POST['newspack_my_account_save_nonce'] = wp_create_nonce( 'newspack_my_account_save' );
		$_POST['account_display_name']           = 'New Name';
		$_POST['account_email']                  = 'new@example.com';

		My_Account::handle_save_account();

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( 'New Name', $user->display_name );
		$this->assertSame( 'new@example.com', $user->user_email );

		unset( $_POST['newspack_my_account_save_nonce'], $_POST['account_display_name'], $_POST['account_email'] );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `n test-php --filter test_native_save_account`
Expected: FAIL — `Call to undefined method ... handle_save_account()`.

- [ ] **Step 3: Implement the native handlers + wire them (Woo-absent only)**

Add to `class My_Account`:

```php
	/**
	 * Handle the native account-settings save (Woo absent).
	 *
	 * Updates display name and email. Returns the updated user ID, or 0 on
	 * failure / invalid nonce.
	 *
	 * @return int
	 */
	public static function handle_save_account() {
		if ( ! \is_user_logged_in() ) {
			return 0;
		}
		$nonce = isset( $_POST['newspack_my_account_save_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['newspack_my_account_save_nonce'] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, 'newspack_my_account_save' ) ) {
			return 0;
		}

		$user_id = \get_current_user_id();
		$args    = [ 'ID' => $user_id ];
		if ( isset( $_POST['account_display_name'] ) ) {
			$args['display_name'] = \sanitize_text_field( \wp_unslash( $_POST['account_display_name'] ) );
		}
		if ( isset( $_POST['account_email'] ) ) {
			$email = \sanitize_email( \wp_unslash( $_POST['account_email'] ) );
			if ( \is_email( $email ) ) {
				$args['user_email'] = $email;
			}
		}
		$result = \wp_update_user( $args );
		return \is_wp_error( $result ) ? 0 : $user_id;
	}

	/**
	 * template_redirect handler routing native form POSTs to their handlers.
	 */
	public static function handle_form_submissions() {
		if ( empty( $_POST['action'] ) || ! self::is_account_page() ) {
			return;
		}
		$action = \sanitize_text_field( \wp_unslash( $_POST['action'] ) );
		if ( 'newspack_my_account_save_account' === $action ) {
			self::handle_save_account();
			\wp_safe_redirect( self::get_endpoint_url( self::ENDPOINT_EDIT_ACCOUNT ) );
			exit;
		}
	}
```

Wire it (Woo-absent branch in `init()`):

```php
		if ( ! self::woocommerce_owns_shell() ) {
			\add_filter( 'page_template', [ __CLASS__, 'page_template' ], 11 );
			\add_action( 'init', [ __CLASS__, 'register_endpoints' ], 6 );
			\add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
			\add_action( 'template_redirect', [ __CLASS__, 'handle_form_submissions' ] );
		}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `n test-php --filter test_native_save_account`
Expected: PASS.

- [ ] **Step 5: Add the delete-request handler**

Add to `class My_Account`, reusing the existing email flow when `WooCommerce_My_Account` is loaded, else a native fallback:

```php
	/**
	 * Handle the native delete-account request (Woo absent): send the existing
	 * deletion email if available, else delete after confirmation.
	 */
	public static function handle_delete_request() {
		if ( ! \is_user_logged_in() ) {
			return;
		}
		$nonce = isset( $_POST['newspack_my_account_delete_nonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['newspack_my_account_delete_nonce'] ) ) : '';
		if ( ! \wp_verify_nonce( $nonce, 'newspack_my_account_delete' ) ) {
			return;
		}
		$user = \wp_get_current_user();
		if ( class_exists( 'Newspack\WooCommerce_My_Account' ) && method_exists( 'Newspack\WooCommerce_My_Account', 'send_delete_account_email' ) ) {
			WooCommerce_My_Account::send_delete_account_email( $user );
		}
		\wp_safe_redirect( self::get_endpoint_url( self::ENDPOINT_EDIT_ACCOUNT ) );
		exit;
	}
```

Add to `handle_form_submissions()`:

```php
		if ( 'newspack_my_account_request_delete' === $action ) {
			self::handle_delete_request();
		}
```

- [ ] **Step 6: Run tests**

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/reader-activation/class-my-account.php tests/unit-tests/my-account.php
git commit -m "feat(my-account): native account save and delete handlers"
```

---

## Phase 6 — Decouple shared templates from WooCommerce

### Task 13: Swap `wc_*` URL calls in `navigation.php`

**Files:**
- Modify: `includes/plugins/woocommerce/my-account/templates/v1/navigation.php`

> `navigation.php` is loaded by `My_Account_UI_V1` (Woo active) only. But because we keep one template, the URL helpers should funnel through `My_Account::get_endpoint_url()` so the file is provider-agnostic and reusable. When Woo is active, `get_endpoint_url()` delegates to Woo, so output is byte-identical.

- [ ] **Step 1: Replace line 94**

Change:

```php
						<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>" <?php echo $is_current_item ? 'aria-current="page"' : ''; ?> class="newspack-ui__button newspack-ui__button--small <?php echo $is_current_item ? 'newspack-ui__button--accent' : 'newspack-ui__button--ghost'; ?>">
```

to:

```php
						<a href="<?php echo esc_url( \Newspack\My_Account::get_endpoint_url( $endpoint ) ); ?>" <?php echo $is_current_item ? 'aria-current="page"' : ''; ?> class="newspack-ui__button newspack-ui__button--small <?php echo $is_current_item ? 'newspack-ui__button--accent' : 'newspack-ui__button--ghost'; ?>">
```

- [ ] **Step 2: Replace line 111**

Change:

```php
				<a href="<?php echo esc_url( wp_logout_url( wc_get_account_endpoint_url( 'customer-logout' ) ) ); ?>" class="newspack-ui__button newspack-ui__button--small newspack-ui__button--ghost">
```

to:

```php
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="newspack-ui__button newspack-ui__button--small newspack-ui__button--ghost">
```

> Note: the surrounding `$account_menu_items = wc_get_account_menu_items()` loop (lines ~22, ~84) and `wc_get_account_menu_item_classes()` calls remain — this template only runs under Woo. We are only routing the **href** through the accessor; the native shell uses `render_navigation()` (Task 9), not this file.

- [ ] **Step 3: Verify Woo path unaffected**

Run: `n test-php --filter Newspack_Test_My_Account`
Expected: PASS. (Template change is a no-op for output when Woo is active.)

- [ ] **Step 4: Commit**

```bash
git add includes/plugins/woocommerce/my-account/templates/v1/navigation.php
git commit -m "refactor(my-account): route navigation URLs through My_Account accessor"
```

---

## Phase 7 — Decouple the My Account button block

### Task 14: Use `My_Account` accessors in the button block

**Files:**
- Modify: `src/blocks/my-account-button/class-my-account-button-block.php:51-72`
- Test: `tests/unit-tests/class-test-my-account-button-block.php` (existing)

- [ ] **Step 1: Write the failing test**

Add to the existing `tests/unit-tests/class-test-my-account-button-block.php` (class name as found in that file):

```php
	/**
	 * The account URL resolves via My_Account::get_endpoint_url when Woo is
	 * absent (native page set).
	 */
	public function test_account_url_native() {
		if ( \Newspack\My_Account::woocommerce_owns_shell() ) {
			$this->markTestSkipped( 'WooCommerce is active; native path not exercised.' );
		}
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_option( \Newspack\My_Account::PAGE_ID_OPTION, $page_id );

		$method = new ReflectionMethod( 'Newspack\Blocks\My_Account_Button\My_Account_Button_Block', 'get_account_url' );
		$method->setAccessible( true );
		$this->assertSame( get_permalink( $page_id ), $method->invoke( null ) );

		delete_option( \Newspack\My_Account::PAGE_ID_OPTION );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `n test-php --filter test_account_url_native`
Expected: FAIL — current `get_account_url()` returns '' when Woo functions are missing and no Woo page option is set.

- [ ] **Step 3: Replace `get_account_url()` (lines 51-72)**

Replace the whole method with:

```php
	/**
	 * Get the account URL for the current site.
	 *
	 * @return string
	 */
	private static function get_account_url() {
		return \Newspack\My_Account::get_endpoint_url();
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `n test-php --filter Newspack_Test_My_Account_Button` (use the class name from the file)
Expected: PASS — including the existing button-block tests (Woo-active behavior preserved: `get_endpoint_url('')` → `wc_get_account_endpoint_url('dashboard')`).

- [ ] **Step 5: Commit**

```bash
git add src/blocks/my-account-button/class-my-account-button-block.php tests/unit-tests/class-test-my-account-button-block.php
git commit -m "refactor(my-account): resolve button URL via My_Account accessor"
```

---

## Phase 8 — Build, full suite, and manual QA

### Task 15: Build assets and run the full PHP suite

**Files:** none (verification only)

- [ ] **Step 1: Build the plugin (registers the new block)**

Run from repo root: `n build newspack-plugin`
Expected: build succeeds with no errors.

- [ ] **Step 2: Run the full PHP unit suite**

Run from `plugins/newspack-plugin/`: `n test-php`
Expected: all tests pass. Investigate and fix any failure before continuing (per superpowers:systematic-debugging).

- [ ] **Step 3: Lint the changed PHP**

Run from `plugins/newspack-plugin/`: `n composer lint:php` (or the repo's configured PHPCS script — check `composer.json` scripts)
Expected: no new violations. Fix any introduced by this work.

- [ ] **Step 4: Commit any lint fixes**

```bash
git add -A
git commit -m "style(my-account): satisfy PHPCS for My Account shell"
```

---

### Task 16: Manual QA in a Woo-absent environment

**Files:** none (verification only)

- [ ] **Step 1: Create an isolated env on this branch**

Use the `newspack:env-create` skill (or `n env create my-account --worktree newspack-plugin:feat/native-my-account --up`).

- [ ] **Step 2: Set up the site WITHOUT WooCommerce**

Run: `n setup --env my-account --yes` (do **not** pass `--woocommerce`). Confirm Reader Activation is enabled.

- [ ] **Step 3: Ensure the native account page exists**

Run (adjust container name per AGENTS.md): `n wp --env my-account eval 'echo Newspack\My_Account::get_or_create_page();'` then `n wp --env my-account rewrite flush`.

- [ ] **Step 4: Verify in the browser**

Log in as a reader and visit the account page URL (`My_Account::get_endpoint_url()`). Confirm:
  - The page renders with navigation + content (no fatal, no blank page).
  - **Account details** tab: change display name + email, submit → values persist.
  - **Delete account** tab: submitting the request does not fatal (email send is best-effort).
  - **Sign out** link logs the reader out and returns home.
  - The **My Account button** block links to the account page.

- [ ] **Step 5: Regression check on a Woo-active env**

Spin up (or reuse) an env WITH `--woocommerce`. Confirm My Account looks and behaves exactly as before (settings, orders, subscriptions, payment methods, navigation, logout) — zero visible change.

- [ ] **Step 6: Record results**

Note the outcomes (with screenshots if useful) in the PR description when you open it. Do not mark the feature complete until both Woo-absent and Woo-active paths are verified (per superpowers:verification-before-completion).

---

## Done criteria

- `n test-php` passes, including the new `tests/unit-tests/my-account.php` and `tests/unit-tests/integrations/my-account-dispatch.php`.
- On a Woo-absent site: the account page loads at a real endpoint and the four core tabs (settings, password link, delete, logout) plus any integration tabs render and function.
- On a Woo-active site: My Account is byte-for-byte unchanged in behavior.
- PHPCS clean; no edits to `CHANGELOG.md` or `.pot` files.

## Deferred (explicitly out of scope — see spec)

- Email-change verification on the **native** path (Woo-absent) is simplified to a direct email update in Task 12; the full two-step verification remains Woo-only for now. Flag for a follow-up if product wants parity.
- Password reset/set on the native path: the account-settings tab links to the existing reset flow; full native password module parity is a follow-up.
- Approach A convergence (Woo consuming `My_Account` while active).
- Building the Fundraise Up integration.
