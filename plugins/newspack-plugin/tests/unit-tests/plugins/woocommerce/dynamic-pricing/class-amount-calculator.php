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

	public function test_percent_of_base_multiplies_base_by_fraction() {
		$this->assertSame( 8.00, Amount_Calculator::calculate( Amount_Calculator::PERCENT_OF_BASE, 80, 10 ) );
		$this->assertSame( 0.00, Amount_Calculator::calculate( Amount_Calculator::PERCENT_OF_BASE, 80, 0 ) );
		$this->assertSame( 25.00, Amount_Calculator::calculate( Amount_Calculator::PERCENT_OF_BASE, 100, 25 ) );
	}

	public function test_discount_fixed_subtracts_and_clamps_at_zero() {
		$this->assertSame( 8.00, Amount_Calculator::calculate( Amount_Calculator::DISCOUNT_FIXED, 2, 10 ) );
		$this->assertSame( 0.00, Amount_Calculator::calculate( Amount_Calculator::DISCOUNT_FIXED, 100, 5 ) );
	}

	public function test_discount_percent_reduces_base_by_fraction() {
		$this->assertSame( 8.00, Amount_Calculator::calculate( Amount_Calculator::DISCOUNT_PERCENT, 20, 10 ) );
		$this->assertSame( 0.00, Amount_Calculator::calculate( Amount_Calculator::DISCOUNT_PERCENT, 100, 10 ) );
	}

	public function test_results_round_to_two_decimals() {
		$this->assertSame( 3.33, Amount_Calculator::calculate( Amount_Calculator::PERCENT_OF_BASE, 33.33, 10 ) );
	}

	public function test_supported_types_lists_all_four() {
		$types = Amount_Calculator::supported_types();
		$this->assertContains( Amount_Calculator::FIXED_PRICE, $types );
		$this->assertContains( Amount_Calculator::PERCENT_OF_BASE, $types );
		$this->assertContains( Amount_Calculator::DISCOUNT_FIXED, $types );
		$this->assertContains( Amount_Calculator::DISCOUNT_PERCENT, $types );
	}
}
