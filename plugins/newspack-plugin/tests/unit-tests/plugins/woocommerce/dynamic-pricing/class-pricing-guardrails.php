<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Pricing_Guardrails.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Pricing_Guardrails;
use Newspack\Dynamic_Pricing\Bounds_Resolver;
use Newspack\Dynamic_Pricing\Pricing_Rule;
use Newspack\Dynamic_Pricing\Price_Decision;
use Newspack\Dynamic_Pricing\Pricing_Context;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Pricing_Guardrails extends WP_UnitTestCase {
	private Pricing_Guardrails $gr;

	public function set_up() {
		parent::set_up();
		$this->gr = new Pricing_Guardrails( new Bounds_Resolver() );
		delete_option( Bounds_Resolver::SITE_FLOOR_OPTION );
		delete_option( Bounds_Resolver::SITE_CEILING_OPTION );
	}

	public function test_compose_min_picks_lowest_amount() {
		$ctx = $this->ctx();
		$current = $this->decision( 8.0, 'pol_a' );
		$incoming = $this->decision( 5.0, 'pol_b' );
		$this->assertSame( 5.0, $this->gr->compose( $current, $incoming, $this->rule( 'min' ), $ctx )->amount );
	}

	public function test_compose_min_keeps_current_when_incoming_is_higher() {
		$ctx = $this->ctx();
		$current = $this->decision( 5.0, 'pol_a' );
		$incoming = $this->decision( 8.0, 'pol_b' );
		$this->assertSame( 5.0, $this->gr->compose( $current, $incoming, $this->rule( 'min' ), $ctx )->amount );
	}

	public function test_compose_priority_exclusive_replaces_and_locks() {
		$ctx = $this->ctx();
		$current = $this->decision( 5.0, 'pol_a' );
		$incoming = $this->decision( 12.0, 'pol_b' );
		$result = $this->gr->compose( $current, $incoming, $this->rule( 'priority_exclusive' ), $ctx );
		$this->assertSame( 12.0, $result->amount, 'priority_exclusive replaces current even when higher.' );
		$this->assertTrue( $result->is_locked, 'priority_exclusive sets is_locked.' );
	}

	public function test_compose_min_decision_after_priority_exclusive_does_not_clear_lock() {
		$ctx = $this->ctx();
		$locked = $this->decision( 12.0, 'pol_a' );
		$locked->is_locked = true;
		$incoming = $this->decision( 5.0, 'pol_b' );
		$result = $this->gr->compose( $locked, $incoming, $this->rule( 'min' ), $ctx );
		$this->assertTrue( $result->is_locked, 'Lock persists on subsequent min() decisions.' );
	}

	public function test_compose_returns_incoming_when_current_null() {
		$ctx = $this->ctx();
		$incoming = $this->decision( 7.0, 'pol_x' );
		$this->assertSame( 7.0, $this->gr->compose( null, $incoming, $this->rule( 'min' ), $ctx )->amount );
	}

	public function test_guard_clamps_below_floor() {
		update_option( Bounds_Resolver::SITE_FLOOR_OPTION, 2.0 );
		$ctx = $this->ctx();
		$d = $this->decision( 0.5, 'pol_a' );
		$this->assertSame( 2.0, $this->gr->guard( $d, $ctx )->amount );
	}

	public function test_guard_clamps_above_ceiling() {
		update_option( Bounds_Resolver::SITE_CEILING_OPTION, 50.0 );
		$ctx = $this->ctx();
		$d = $this->decision( 80.0, 'pol_a' );
		$this->assertSame( 50.0, $this->gr->guard( $d, $ctx )->amount );
	}

	private function ctx(): Pricing_Context {
		$p = $this->getMockBuilder( \WC_Product::class )->disableOriginalConstructor()->getMock();
		$p->method( 'get_id' )->willReturn( 1 );
		return new Pricing_Context( 'scheduled_step', $p, null, 10.0, [], null );
	}

	private function decision( float $amount, string $rule_id ): Price_Decision {
		$d = new Price_Decision( $amount, Price_Decision::DURABLE, 'test', 'Test', 'stepped_by_cycle', 1 );
		$d->rule_id = $rule_id;
		return $d;
	}

	private function rule( string $compose_mode ): Pricing_Rule {
		$p               = new Pricing_Rule();
		$p->id           = 'pol_x';
		$p->title        = 'Test';
		$p->strategy_id  = 'stepped_by_cycle';
		$p->compose_mode = $compose_mode;
		return $p;
	}
}
