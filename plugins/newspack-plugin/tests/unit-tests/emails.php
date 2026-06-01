<?php
/**
 * Tests Reader Revenue Emails.
 *
 * @package Newspack\Tests
 */

use Newspack\Plugin_Manager;
use Newspack\Emails;

/**
 * Tests Reader Revenue Emails.
 */
class Newspack_Test_Emails extends WP_UnitTestCase {
	/**
	 * Setup.
	 *
	 * Intentionally does NOT call `parent::set_up()` — the existing
	 * tests in this class depend on the absence of the parent's
	 * transaction lifecycle (tested empirically: adding parent::set_up
	 * breaks `test_emails_send_by_id`). Tests that need a user
	 * factory use the static accessor on `WP_UnitTestCase` rather
	 * than `$this->factory`.
	 */
	public function set_up() {
		reset_phpmailer_instance();
		add_filter(
			'newspack_email_configs',
			function ( $types ) {
				$types['test-email-config'] = [
					'name'        => 'test-email-config',
					'label'       => __( 'Test config', 'newspack' ),
					'description' => __( 'Email sent to test things.', 'newspack' ),
					'template'    => dirname( NEWSPACK_PLUGIN_FILE ) . '/includes/templates/reader-revenue-emails/receipt.php',
					'category'    => 'test',
				];
				return $types;
			}
		);
	}

	/**
	 * Teardown.
	 */
	public function tear_down() {
		reset_phpmailer_instance();
	}

	/**
	 * Get an email, by type.
	 *
	 * @param string $type Email type.
	 */
	private static function get_test_email( $type ) {
		return Emails::get_emails()[ $type ];
	}

	/**
	 * Email setup & defaults generation.
	 */
	public function test_emails_setup() {
		self::assertTrue(
			Emails::supports_emails(),
			'Emails are configured after Newspack Newsletters plugin is active.'
		);

		self::assertTrue(
			Emails::can_send_email( 'test-email-config' ),
			'Test email can now be sent.'
		);

		$emails     = Emails::get_emails( [ 'test-email-config' ] );
		$test_email = $emails['test-email-config'];
		self::assertEquals(
			'Test config',
			$test_email['label'],
			'Test email has the expected label'
		);
		self::assertEquals(
			'Thank you!',
			$test_email['subject'],
			'Test email has the expected subject'
		);
		self::assertStringContainsString(
			'<!doctype html>',
			$test_email['html_payload'],
			'Test email has the HTML payload'
		);
	}

	/**
	 * Email sending, with a template.
	 */
	public function test_emails_send_with_template() {
		$test_email = self::get_test_email( 'test-email-config' );

		$recipient    = 'tester@tests.com';
		$amount       = '$42';
		$placeholders = [
			[
				'template' => '*AMOUNT*',
				'value'    => $amount,
			],
		];
		$send_result  = Emails::send_email(
			'test-email-config',
			$recipient,
			$placeholders
		);

		self::assertTrue( $send_result, 'Email has been sent.' );

		$mailer = tests_retrieve_phpmailer_instance();

		self::assertContains(
			$recipient,
			$mailer->get_sent()->to[0],
			'Sent email has the expected recipient'
		);
		self::assertEquals(
			$test_email['subject'],
			$mailer->get_sent()->subject,
			'Sent email has the expected subject'
		);
		self::assertStringContainsString(
			'From: Test Blog <no-reply@example.org>',
			$mailer->get_sent()->header,
			'Sent email has the expected "From" header'
		);
		self::assertStringContainsString(
			$amount,
			$mailer->get_sent()->body,
			'Sent email contains the replaced placeholder content'
		);
	}

	/**
	 * Sending by email id.
	 */
	public function test_emails_send_by_id() {
		$test_email = self::get_test_email( 'test-email-config' );

		$send_result = Emails::send_email(
			$test_email['post_id'],
			'someone@example.com'
		);
		self::assertTrue( $send_result, 'Email has been sent.' );

		$send_result = Emails::send_email(
			9999,
			'someone@example.com'
		);
		self::assertFalse( $send_result, 'Non-existent email is not sent.' );
	}

	/**
	 * Email post status — auto-send vs. test-send distinction (NPPD-1547).
	 *
	 * Locks the contract that splits the two entry points:
	 *
	 * - AUTO-SEND (Emails::send_email + Emails::can_send_email) is
	 *   blocked for draft emails. Event-triggered sends (RAS, receipts,
	 *   renewal reminders, etc.) require the email to be in 'publish'
	 *   status — an inactive email must not fire on triggered events.
	 * - TEST-SEND (Emails::send_test_email) IS allowed for draft
	 *   emails. The admin's deliberate "Send test" operation is
	 *   semantically distinct from auto-trigger gating; before
	 *   NPPD-1547 the shared 'publish' === status guard incidentally
	 *   blocked test-send for inactive emails.
	 *
	 * Both assertions in one test makes the distinction explicit and
	 * locked: a future refactor that re-conflates the two paths
	 * fails this test against both contracts simultaneously.
	 */
	public function test_emails_status() {
		$test_email = self::get_test_email( 'test-email-config' );
		wp_update_post(
			[
				'ID'          => $test_email['post_id'],
				'post_status' => 'draft',
			]
		);

		// AUTO-SEND: draft is blocked. Unchanged from pre-NPPD-1547.
		self::assertFalse(
			Emails::can_send_email( 'test-email-config' ),
			'can_send_email() must return false for draft (auto-send gate).'
		);
		$auto_send_result = Emails::send_email( 'test-email-config', 'someone@example.com' );
		self::assertFalse( $auto_send_result, 'send_email() must not dispatch for draft (auto-send gate).' );

		// TEST-SEND: draft is allowed. The headline contract of NPPD-1547.
		$test_send_result = Emails::send_test_email( $test_email['post_id'], 'someone@example.com' );
		self::assertTrue(
			$test_send_result,
			'send_test_email() must return true for draft — the admin "Send test" operation is decoupled from the auto-send status gate.'
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * NPPD-1547 — Test-send entry point + recipient validation
	 * ------------------------------------------------------------------
	 * The split between auto-send and test-send is verified at the
	 * status-gate level by `test_emails_status` above. The tests
	 * below cover the rest of the test-send contract: prerequisite
	 * failures surface specific WP_Error codes (not the previous
	 * generic "Test email was not sent." string), and the
	 * api_send_test_email handler validates recipient format before
	 * the dispatch path.
	 */

	/**
	 * Headline contract: test-send dispatches for a draft email.
	 * Mirrors the second half of test_emails_status but isolates
	 * the assertion so failure pinpoints the test-send path.
	 */
	public function test_test_send_works_for_draft() {
		$test_email = self::get_test_email( 'test-email-config' );
		wp_update_post(
			[
				'ID'          => $test_email['post_id'],
				'post_status' => 'draft',
			]
		);

		$result = Emails::send_test_email( $test_email['post_id'], 'tester@example.com' );

		self::assertTrue( $result, 'send_test_email() must return true (not WP_Error) for a draft email with all other prereqs satisfied.' );

		// Verify the email actually reached the (mocked) mailer with
		// the correct recipient — guards against accidentally
		// short-circuiting before dispatch_email is called.
		$mailer = tests_retrieve_phpmailer_instance();
		self::assertContains( 'tester@example.com', $mailer->get_sent()->to[0] );
	}

	/**
	 * Recipient format validation lives at the api_send_test_email
	 * handler (not at send_test_email itself), so the test routes
	 * through the handler with a WP_REST_Request. Typo input like
	 * 'not-an-email' must return 400 + newspack_invalid_test_recipient,
	 * NOT silently reach wp_mail() and fail with the generic dispatch
	 * error.
	 */
	public function test_test_send_rejects_non_email_recipient() {
		$test_email = self::get_test_email( 'test-email-config' );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'post_id', $test_email['post_id'] );
		$request->set_param( 'recipient', 'not-an-email' );

		$response = Emails::api_send_test_email( $request );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'newspack_invalid_test_recipient', $response->get_error_code() );
		self::assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Supports_emails() returning false means Newspack_Newsletters is
	 * not active. send_test_email must return WP_Error with
	 * newspack_emails_unsupported.
	 *
	 * STRUCTURAL LIMITATION: supports_emails() uses class_exists() on
	 * Newspack_Newsletters; in the test bootstrap that class IS
	 * loaded, and PHP doesn't allow un-loading a class within a
	 * process. The false branch is not exercisable without either
	 * (a) adding a filter to supports_emails() for test seam (a
	 * production change with no callers outside tests), or (b)
	 * runkit/uopz extensions (not standard test infra here).
	 *
	 * Skipped with explicit acknowledgement of the gap. The branch
	 * is a one-line class_exists check with no other logic — value
	 * of testing the false case is low relative to the production-
	 * code-change cost of opening it up for mocking. Filed forward
	 * as a potential testability follow-up.
	 */
	public function test_test_send_blocked_when_no_newspack_newsletters() {
		$this->markTestSkipped( 'supports_emails() false branch is not exercisable without modifying production code (no filter on supports_emails) or runkit/uopz extensions.' );
	}

	/**
	 * A post WITHOUT EMAIL_HTML_META cannot be dispatched.
	 * serialize_email() guards this case by returning false when
	 * the meta is missing/empty; validate_send_prerequisites surfaces
	 * it as newspack_emails_post_not_resolvable.
	 *
	 * Uses its own throwaway post rather than the shared
	 * `test-email-config` so the missing-meta state doesn't leak
	 * into later tests in the file (this class deliberately doesn't
	 * call parent::set_up(), so WP_UnitTestCase's transaction
	 * rollback isn't in effect — DB changes survive across tests).
	 */
	public function test_test_send_blocked_when_missing_html_payload() {
		// Create a fresh email post with NO EMAIL_HTML_META — that's
		// the state under test.
		$post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'NPPD-1547 missing-html test',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META => 'test-email-config',
					// Intentionally no EMAIL_HTML_META.
				],
			]
		);

		$result = Emails::send_test_email( $post_id, 'tester@example.com' );

		// Clean up before any assertion can fail and short-circuit
		// the function — guarantees no leak even on assertion failure.
		wp_delete_post( $post_id, true );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'newspack_emails_post_not_resolvable', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Empty recipient is blocked at the helper level (independent of
	 * the api handler's is_email validation). Tests the
	 * send_test_email entry point directly so the assertion isolates
	 * the helper's behavior.
	 */
	public function test_test_send_blocked_when_recipient_empty() {
		$test_email = self::get_test_email( 'test-email-config' );

		$result = Emails::send_test_email( $test_email['post_id'], '' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'newspack_emails_empty_recipient', $result->get_error_code() );
		self::assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Non-admin users cannot call the test-send endpoint. The
	 * permission check is on the REST route's permission_callback
	 * (api_permissions_check), which requires manage_options. Test
	 * the callback directly with a subscriber-level user logged in
	 * — calling api_send_test_email via PHP doesn't pass through
	 * the route's permission_callback, but the callback itself is
	 * what the REST framework runs.
	 */
	public function test_test_send_blocked_when_non_admin() {
		// Don't use `$this->factory` — this class doesn't call
		// parent::set_up(), so factory isn't initialized. Direct
		// wp_insert_user avoids the dependency.
		$subscriber_id = wp_insert_user(
			[
				'user_login' => 'nppd1547_subscriber_' . uniqid(),
				'user_pass'  => 'test-password',
				'user_email' => 'sub-' . uniqid() . '@example.test',
				'role'       => 'subscriber',
			]
		);
		$prev_user = get_current_user_id();
		wp_set_current_user( $subscriber_id );

		$result = Emails::api_permissions_check( null );

		wp_set_current_user( $prev_user );
		wp_delete_user( $subscriber_id );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'newspack_rest_forbidden', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A post_id that doesn't exist surfaces as
	 * newspack_emails_post_not_resolvable (same error code as
	 * missing-HTML-payload, since both go through serialize_email's
	 * false return). The test name distinguishes the SCENARIO even
	 * though the error code is shared.
	 */
	public function test_test_send_blocked_when_post_missing() {
		$result = Emails::send_test_email( 9999999, 'tester@example.com' );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'newspack_emails_post_not_resolvable', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * REGRESSION LOCK: NPPD-1547's refactor extracted the send-path
	 * helper but MUST preserve auto-send's status gate. Without this
	 * lock-in, a future refactor that accidentally routes
	 * send_email's post-id branch through send_test_email's
	 * status-less path would silently start dispatching draft
	 * emails on triggered events — a much worse failure mode than
	 * the original bug (silent over-firing vs. silent under-firing).
	 */
	public function test_auto_send_still_blocked_for_draft() {
		$test_email = self::get_test_email( 'test-email-config' );
		wp_update_post(
			[
				'ID'          => $test_email['post_id'],
				'post_status' => 'draft',
			]
		);

		// Post-id path of send_email — the branch that shares
		// validate_send_prerequisites with send_test_email. Auto-send
		// must still apply the status check on top.
		$post_id_result = Emails::send_email( $test_email['post_id'], 'tester@example.com' );
		self::assertFalse( $post_id_result, 'send_email() post-id branch must remain status-gated.' );

		// String path of send_email — the dominant auto-trigger
		// surface. Status check is inline (not via the helper).
		$string_result = Emails::send_email( 'test-email-config', 'tester@example.com' );
		self::assertFalse( $string_result, 'send_email() string-name branch must remain status-gated.' );
	}
}
