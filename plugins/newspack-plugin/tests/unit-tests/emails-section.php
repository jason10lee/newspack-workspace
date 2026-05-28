<?php
/**
 * Tests for the unified email config schema and the wizard response builder.
 *
 * Slice 1 (NPPD-945) coverage — schema completeness across Newspack-side
 * providers, per-provider content, response shape, and the
 * apply_config_defaults() merge mechanism. The WC integration test bucket
 * lands with slice 2.
 *
 * @package Newspack\Tests
 */

use Newspack\Emails;
use Newspack\Reader_Activation_Emails;
use Newspack\Reader_Revenue_Emails;
use Newspack\Wizards\Newspack\Emails_Section;

/**
 * Tests the unified Emails config schema and the wizard response builder.
 */
class Newspack_Test_Emails_Section extends WP_UnitTestCase {
	/*
	 * ------------------------------------------------------------------
	 * Bucket A — Schema completeness
	 * ------------------------------------------------------------------
	 * Iterates every config from `newspack_email_configs` after defaults
	 * are applied. Catches provider classes that forget to declare the
	 * new fields, or that declare invalid values.
	 */

	/**
	 * Every config has the four new schema fields after defaults are applied.
	 */
	public function test_email_configs_have_all_required_fields() {
		$configs = Emails::get_email_configs();
		$this->assertNotEmpty( $configs, 'Expected at least one registered email config.' );

		foreach ( $configs as $type => $config ) {
			$this->assertArrayHasKey( 'trigger_description', $config, "Config '$type' is missing trigger_description." );
			$this->assertIsString( $config['trigger_description'], "Config '$type' trigger_description must be a string." );

			$this->assertArrayHasKey( 'recipient', $config, "Config '$type' is missing recipient." );
			$this->assertContains(
				$config['recipient'],
				[ 'reader', 'admin' ],
				"Config '$type' has an invalid recipient value."
			);

			$this->assertArrayHasKey( 'recommended', $config, "Config '$type' is missing recommended." );
			$this->assertIsBool( $config['recommended'], "Config '$type' recommended must be a bool." );

			$this->assertArrayHasKey( 'chip', $config, "Config '$type' is missing chip." );
			$this->assertContains(
				$config['chip'],
				[ 'auth-account', 'reader-revenue' ],
				"Config '$type' has an invalid chip value."
			);
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket B — Per-provider content
	 * ------------------------------------------------------------------
	 * Asserts each provider class registers entries with the expected
	 * field values. Catches data regressions when providers are touched.
	 */

	/**
	 * Reader-revenue provider: 3 entries chip='reader-revenue', recipient='reader', recommended=true.
	 */
	public function test_reader_revenue_provider_entries() {
		$configs = Emails::get_email_configs();

		$expected = [
			Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'] => 'Sent after a successful payment.',
			Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'] => 'Sent to new supporters after their first payment.',
			Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'] => 'Sent when a reader cancels their subscription.',
		];

		foreach ( $expected as $type => $trigger_description ) {
			$this->assertArrayHasKey( $type, $configs, "Reader-revenue type '$type' not registered." );
			$this->assertSame( 'reader-revenue', $configs[ $type ]['chip'], "Type '$type' should chip to reader-revenue." );
			$this->assertSame( 'reader', $configs[ $type ]['recipient'], "Type '$type' should target reader." );
			$this->assertTrue( $configs[ $type ]['recommended'], "Type '$type' should be recommended." );
			$this->assertSame( $trigger_description, $configs[ $type ]['trigger_description'] );
		}
	}

	/**
	 * Reader-activation provider: chip='auth-account', recipient='reader' for all entries.
	 * Recommended=true for the four core sign-in flows; false for the account-management flows.
	 *
	 * CHANGE_EMAIL and CHANGE_EMAIL_CANCEL are gated on WooCommerce_My_Account::is_email_change_enabled(),
	 * so they're only asserted when registered.
	 */
	public function test_reader_activation_provider_entries() {
		$configs = Emails::get_email_configs();

		$recommended_types     = [
			Reader_Activation_Emails::EMAIL_TYPES['VERIFICATION'],
			Reader_Activation_Emails::EMAIL_TYPES['MAGIC_LINK'],
			Reader_Activation_Emails::EMAIL_TYPES['OTP_AUTH'],
			Reader_Activation_Emails::EMAIL_TYPES['RESET_PASSWORD'],
		];
		$non_recommended_types = [
			Reader_Activation_Emails::EMAIL_TYPES['DELETE_ACCOUNT'],
			Reader_Activation_Emails::EMAIL_TYPES['NON_READER'],
		];
		$conditional_types     = [
			Reader_Activation_Emails::EMAIL_TYPES['CHANGE_EMAIL'],
			Reader_Activation_Emails::EMAIL_TYPES['CHANGE_EMAIL_CANCEL'],
		];

		foreach ( array_merge( $recommended_types, $non_recommended_types ) as $type ) {
			$this->assertArrayHasKey( $type, $configs, "Reader-activation type '$type' not registered." );
			$this->assertSame( 'auth-account', $configs[ $type ]['chip'], "Type '$type' should chip to auth-account." );
			$this->assertSame( 'reader', $configs[ $type ]['recipient'], "Type '$type' should target reader." );
			$this->assertNotEmpty( $configs[ $type ]['trigger_description'], "Type '$type' should have a trigger description." );
		}
		foreach ( $recommended_types as $type ) {
			$this->assertTrue( $configs[ $type ]['recommended'], "Type '$type' should be recommended." );
		}
		foreach ( $non_recommended_types as $type ) {
			$this->assertFalse( $configs[ $type ]['recommended'], "Type '$type' should NOT be recommended." );
		}
		// CHANGE_EMAIL pair only registers when WC My Account email change is enabled.
		foreach ( $conditional_types as $type ) {
			if ( ! isset( $configs[ $type ] ) ) {
				continue;
			}
			$this->assertSame( 'auth-account', $configs[ $type ]['chip'] );
			$this->assertSame( 'reader', $configs[ $type ]['recipient'] );
			$this->assertFalse( $configs[ $type ]['recommended'] );
		}
	}

	/**
	 * Group-subscription-invite provider: chip='reader-revenue' (paid product), not recommended.
	 */
	public function test_group_subscription_invite_provider_entry() {
		$configs = Emails::get_email_configs();
		$type    = 'group-subscription-invite';

		if ( ! isset( $configs[ $type ] ) ) {
			$this->markTestSkipped( 'group-subscription-invite config not registered in this environment.' );
		}
		$this->assertSame( 'reader-revenue', $configs[ $type ]['chip'] );
		$this->assertSame( 'reader', $configs[ $type ]['recipient'] );
		$this->assertFalse( $configs[ $type ]['recommended'] );
		$this->assertSame( 'Sent to invite a reader to join a group subscription.', $configs[ $type ]['trigger_description'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket C — Response shape of api_get_email_settings()
	 * ------------------------------------------------------------------
	 * Verifies the wizard endpoint response structure after the rewrite:
	 * top-level keys are correct, each row carries the new fields,
	 * registry_slug is the config key string, no view_category leakage,
	 * no WC-source rows in slice 1, and category sort grouping holds.
	 */

	/**
	 * Response has the expected top-level shape and rows carry the new fields.
	 */
	public function test_api_get_email_settings_response_shape() {
		$result = Emails_Section::api_get_email_settings();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'newspack_emails', $result );
		$this->assertArrayHasKey( 'post_type', $result );
		$this->assertIsArray( $result['newspack_emails'] );

		$required_row_fields = [
			'label',
			'registry_slug',
			'trigger_description',
			'recipient',
			'recommended',
			'chip',
			'source',
			'category',
			'status',
		];
		foreach ( $result['newspack_emails'] as $email ) {
			foreach ( $required_row_fields as $field ) {
				$this->assertArrayHasKey( $field, $email, "Row '{$email['label']}' is missing field '$field'." );
			}
		}
	}

	/**
	 * `view_category` is dead — the response builder never emits it.
	 */
	public function test_api_get_email_settings_omits_view_category() {
		$result = Emails_Section::api_get_email_settings();
		foreach ( $result['newspack_emails'] as $email ) {
			$this->assertArrayNotHasKey( 'view_category', $email );
		}
	}

	/**
	 * Slice 1 filters out WooCommerce-source rows. Slice 2 lifts this filter.
	 */
	public function test_api_get_email_settings_excludes_woocommerce_source() {
		$result = Emails_Section::api_get_email_settings();
		foreach ( $result['newspack_emails'] as $email ) {
			$this->assertNotSame( 'woocommerce', $email['source'] ?? 'newspack', 'Row should not be WC-sourced in slice 1.' );
		}
	}

	/**
	 * Sort order: reader-revenue first, then reader-activation, then everything else.
	 */
	public function test_api_get_email_settings_sort_order() {
		$result    = Emails_Section::api_get_email_settings();
		$group_map = [
			'reader-revenue'    => 0,
			'reader-activation' => 1,
		];

		$last_group = -1;
		foreach ( $result['newspack_emails'] as $i => $email ) {
			$group = $group_map[ $email['category'] ?? '' ] ?? 2;
			$this->assertGreaterThanOrEqual(
				$last_group,
				$group,
				"Email at index $i (category '{$email['category']}') is out of sort order."
			);
			$last_group = $group;
		}
	}

	/**
	 * The registry_slug field echoes the config key — just a string identifier, no registry concept.
	 */
	public function test_api_get_email_settings_registry_slug_is_config_key() {
		$result = Emails_Section::api_get_email_settings();
		foreach ( $result['newspack_emails'] as $email ) {
			$this->assertNotEmpty( $email['registry_slug'] );
			$this->assertSame( $email['type'], $email['registry_slug'] );
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket D — Default-merge mechanism
	 * ------------------------------------------------------------------
	 * Direct coverage of Emails::apply_config_defaults() — the public
	 * helper introduced in commit 1. Catches changes to the documented
	 * defaults and regressions in the merge logic.
	 */

	/**
	 * Partial config gets the documented defaults filled in.
	 */
	public function test_apply_config_defaults_fills_missing_fields() {
		$partial = [
			'name'     => 'test-email',
			'label'    => 'Test Email',
			'category' => 'reader-activation',
		];
		$merged  = Emails::apply_config_defaults( $partial );

		$this->assertSame( '', $merged['trigger_description'] );
		$this->assertSame( 'reader', $merged['recipient'] );
		$this->assertTrue( $merged['recommended'] );
		$this->assertSame( 'auth-account', $merged['chip'] );

		// Declared fields pass through unchanged.
		$this->assertSame( 'test-email', $merged['name'] );
		$this->assertSame( 'Test Email', $merged['label'] );
		$this->assertSame( 'reader-activation', $merged['category'] );
	}

	/**
	 * A config that declares the new fields keeps its values — defaults
	 * do not clobber explicit declarations.
	 */
	public function test_apply_config_defaults_preserves_declared_fields() {
		$full   = [
			'name'                => 'test-email',
			'trigger_description' => 'A specific trigger.',
			'recipient'           => 'admin',
			'recommended'         => false,
			'chip'                => 'reader-revenue',
		];
		$merged = Emails::apply_config_defaults( $full );

		$this->assertSame( 'A specific trigger.', $merged['trigger_description'] );
		$this->assertSame( 'admin', $merged['recipient'] );
		$this->assertFalse( $merged['recommended'] );
		$this->assertSame( 'reader-revenue', $merged['chip'] );
	}

	/**
	 * A third-party provider can register with no new fields at all and
	 * still get a complete config back out of Emails::get_email_configs().
	 */
	public function test_email_configs_filter_partial_provider_gets_defaults() {
		$type     = 'test-partial-third-party-config';
		$callback = function ( $configs ) use ( $type ) {
			$configs[ $type ] = [
				'name'     => $type,
				'category' => 'reader-activation',
				'label'    => 'Third-party',
			];
			return $configs;
		};

		add_filter( 'newspack_email_configs', $callback );
		$configs = Emails::get_email_configs();
		remove_filter( 'newspack_email_configs', $callback );

		$this->assertArrayHasKey( $type, $configs );
		$this->assertSame( '', $configs[ $type ]['trigger_description'] );
		$this->assertSame( 'reader', $configs[ $type ]['recipient'] );
		$this->assertTrue( $configs[ $type ]['recommended'] );
		$this->assertSame( 'auth-account', $configs[ $type ]['chip'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * Bucket E — Reader Activation gating
	 * ------------------------------------------------------------------
	 * Covers Emails_Section::filter_configs_by_ra_state(), the helper
	 * api_get_email_settings() uses to scope the visible config set when
	 * Reader Activation is disabled. Tests the helper directly because
	 * Reader_Activation::is_enabled() hard-returns true in the test
	 * environment (see class-reader-activation.php:1013), making the
	 * disabled branch unreachable through the public endpoint.
	 */

	/**
	 * When RA is enabled, all configs pass through unchanged.
	 */
	public function test_filter_configs_by_ra_state_passes_through_when_enabled() {
		$configs = [
			'receipt'                        => [ 'name' => 'receipt' ],
			'reader-activation-verification' => [ 'name' => 'reader-activation-verification' ],
			'group-subscription-invite'      => [ 'name' => 'group-subscription-invite' ],
		];
		$filtered = Emails_Section::filter_configs_by_ra_state( true, $configs );
		$this->assertSame( $configs, $filtered );
	}

	/**
	 * When RA is disabled, only reader-revenue types
	 * (Reader_Revenue_Emails::EMAIL_TYPES) survive the filter.
	 */
	public function test_filter_configs_by_ra_state_scopes_to_reader_revenue_when_disabled() {
		$configs = [
			Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'] => [ 'name' => 'receipt' ],
			Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'] => [ 'name' => 'welcome' ],
			Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'] => [ 'name' => 'cancellation' ],
			'reader-activation-verification'              => [ 'name' => 'reader-activation-verification' ],
			'reader-activation-magic-link'                => [ 'name' => 'reader-activation-magic-link' ],
			'group-subscription-invite'                   => [ 'name' => 'group-subscription-invite' ],
		];
		$filtered = Emails_Section::filter_configs_by_ra_state( false, $configs );

		// Reader-revenue types should survive.
		$this->assertArrayHasKey( Reader_Revenue_Emails::EMAIL_TYPES['RECEIPT'], $filtered );
		$this->assertArrayHasKey( Reader_Revenue_Emails::EMAIL_TYPES['WELCOME'], $filtered );
		$this->assertArrayHasKey( Reader_Revenue_Emails::EMAIL_TYPES['CANCELLATION'], $filtered );

		// Reader-activation and group-subscription types should be dropped.
		$this->assertArrayNotHasKey( 'reader-activation-verification', $filtered );
		$this->assertArrayNotHasKey( 'reader-activation-magic-link', $filtered );
		$this->assertArrayNotHasKey( 'group-subscription-invite', $filtered );

		// Exact count: 3 reader-revenue types only.
		$this->assertCount( 3, $filtered );
	}

	/**
	 * Empty configs in, empty configs out — regardless of RA state.
	 */
	public function test_filter_configs_by_ra_state_handles_empty_configs() {
		$this->assertSame( [], Emails_Section::filter_configs_by_ra_state( true, [] ) );
		$this->assertSame( [], Emails_Section::filter_configs_by_ra_state( false, [] ) );
	}
}
