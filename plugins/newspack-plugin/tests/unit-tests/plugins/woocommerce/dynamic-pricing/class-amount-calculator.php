<?php
/**
 * Tests for Newspack\Dynamic_Pricing\Amount_Calculator.
 *
 * @package Newspack\Tests
 */

use Newspack\Dynamic_Pricing\Amount_Calculator;

/**
 * @group Dynamic_Pricing
 */
class Newspack_Test_Amount_Calculator extends WP_UnitTestCase {
	public function test_fixed_price_returns_value_ignoring_base() {
		$this->assertSame( 8.00, Amount_Calculator::calculate( Amount_Calculator::FIXED_PRICE, 8, 10 ) );
		$this->assertSame( 8.00, Amount_Calculator::calculate( Amount_Calculator::FIXED_PRICE, 8, 0 ) );
	}

	public function test_percent_of_base_handles_discount_parity_and_surcharge() {
		// 80% of regular = a 20% discount.
		$this->assertSame( 8.00, Amount_Calculator::calculate( Amount_Calculator::PERCENT_OF_BASE, 80, 10 ) );
		// 100% of regular = parity (no change). Strategy abstain logic depends on this.
		$this->assertSame( 10.00, Amount_Calculator::calculate( Amount_Calculator::PERCENT_OF_BASE, 100, 10 ) );
		// 110% of regular = a 10% surcharge. This is why we kept percent_of_base
		// instead of the discount-framed variant: it expresses surcharges naturally.
		$this->assertSame( 11.00, Amount_Calculator::calculate( Amount_Calculator::PERCENT_OF_BASE, 110, 10 ) );
		// Zero base stays zero regardless of percentage.
		$this->assertSame( 0.00, Amount_Calculator::calculate( Amount_Calculator::PERCENT_OF_BASE, 80, 0 ) );
	}

	public function test_discount_fixed_subtracts_and_clamps_at_zero() {
		$this->assertSame( 8.00, Amount_Calculator::calculate( Amount_Calculator::DISCOUNT_FIXED, 2, 10 ) );
		$this->assertSame( 0.00, Amount_Calculator::calculate( Amount_Calculator::DISCOUNT_FIXED, 100, 5 ) );
	}

	public function test_results_round_to_two_decimals() {
		$this->assertSame( 3.33, Amount_Calculator::calculate( Amount_Calculator::PERCENT_OF_BASE, 33.33, 10 ) );
	}

	public function test_supported_types_lists_three_distinct_calc_types() {
		$types = Amount_Calculator::supported_types();
		$this->assertCount( 3, $types );
		$this->assertContains( Amount_Calculator::FIXED_PRICE, $types );
		$this->assertContains( Amount_Calculator::PERCENT_OF_BASE, $types );
		$this->assertContains( Amount_Calculator::DISCOUNT_FIXED, $types );
	}
}
