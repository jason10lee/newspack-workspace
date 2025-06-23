<?php
/**
 * Test Budget
 *
 * @package Newspack_Story_Budget
 */

//phpcs:disable Squiz.Commenting.VariableComment.Missing

namespace Newspack_Story_Budget;

/**
 * Test Budget Class.
 */
class Test_Budget extends \WP_UnitTestCase {

	protected static $budgets = [];
	protected static $stories = [];

	/**
	 * WP setup before class.
	 */
	public static function wpSetUpBeforeClass() {
		self::$budgets = self::factory()->term->create_many(
			2,
			[
				'taxonomy' => Budgets::TAXONOMY,
			]
		);
		self::$stories = self::factory()->post->create_many(
			100,
			[
				'post_type' => 'post',
			]
		);
		foreach ( self::$stories as $i => $post_id ) {
			wp_set_post_terms( $post_id, [ self::$budgets[ $i % 2 ] ], Budgets::TAXONOMY );
		}
	}

	/**
	 * Test budget.
	 */
	public function test_new_budget() {
		$budget_id = self::$budgets[0];
		$budget = new Budget( $budget_id );
		$this->assertTrue( $budget->is_valid() );
		$this->assertEquals( $budget_id, $budget->id );
	}

	/**
	 * Test non budget.
	 */
	public function test_new_non_budget() {
		$budget = new Budget( 0 );
		$this->assertFalse( $budget->is_valid() );

		$tag = $this->factory->tag->create();
		$budget = new Budget( $tag );
		$this->assertFalse( $budget->is_valid() );
	}

	/**
	 * Test to array.
	 */
	public function test_to_array() {
		$budget_id = self::$budgets[0];
		$budget = new Budget( $budget_id );
		$this->assertEquals(
			[
				'id'          => $budget_id,
				'name'        => get_term( $budget_id, Budgets::TAXONOMY )->name,
				'slug'        => get_term( $budget_id, Budgets::TAXONOMY )->slug,
				'description' => get_term( $budget_id, Budgets::TAXONOMY )->description,
				'archived'    => false,
				'archive_at'  => '',
				'story_count' => 0,
				'order'       => 0,
			],
			$budget->to_array()
		);
	}

	/**
	 * Test archive.
	 */
	public function test_archive() {
		$budget_id = self::$budgets[0];
		$budget = new Budget( $budget_id );
		$this->assertFalse( $budget->archived );

		$budget->archive();
		$this->assertTrue( $budget->archived );
		$this->assertTrue( (bool) get_term_meta( $budget_id, 'archived', true ) );

		$budget->unarchive();
		$this->assertFalse( $budget->archived );
		$this->assertFalse( (bool) get_term_meta( $budget_id, 'archived', true ) );
	}

	/**
	 * Test get stories.
	 */
	public function test_get_stories() {
		$budget = new Budget( self::$budgets[0] );
		$stories = $budget->get_stories();
		$this->assertCount( 50, $stories );

		// Assert that returned items are stories.
		$this->assertContainsOnlyInstancesOf( 'Newspack_Story_Budget\Story', $stories );
	}

	/**
	 * Test setting and getting auto-archive date.
	 */
	public function test_set_get_auto_archive() {
		$budget_id = self::$budgets[0];
		$budget    = new Budget( $budget_id );

		$budget->unarchive();
		$this->assertFalse( $budget->archived );
		$this->assertEmpty( $budget->archive_at );

		$future_date = new \DateTime( '+3 days' );
		$result      = $budget->set_auto_archive( $future_date );
		$this->assertNotEmpty( $result );

		$refreshed_budget = new Budget( $budget_id );
		$this->assertNotEmpty( $refreshed_budget->archive_at );

		$stored_date = new \DateTime( $refreshed_budget->archive_at );
		$this->assertEquals(
			$future_date->format( 'c' ),
			$stored_date->format( 'c' )
		);
	}

	/**
	 * Test clearing auto-archive date.
	 */
	public function test_clear_auto_archive() {
		$budget_id = self::$budgets[1];
		$budget    = new Budget( $budget_id );

		$budget->unarchive();

		$future_date = new \DateTime( '+3 days' );
		$budget->set_auto_archive( $future_date );

		$this->assertNotEmpty( $budget->archive_at );

		$result = $budget->clear_auto_archive();
		$this->assertTrue( $result );

		$refreshed_budget = new Budget( $budget_id );
		$this->assertEmpty( $refreshed_budget->archive_at );
	}

	/**
	 * Test that archiving a budget clears its auto-archive date.
	 */
	public function test_archive_clears_auto_archive() {
		$budget_id = self::$budgets[0];
		$budget    = new Budget( $budget_id );

		$budget->unarchive();

		$future_date = new \DateTime( '+1 week' );
		$budget->set_auto_archive( $future_date );
		$this->assertNotEmpty( $budget->archive_at );

		$budget->archive();

		$refreshed_budget = new Budget( $budget_id );
		$this->assertTrue( $refreshed_budget->archived );
		$this->assertEmpty( $refreshed_budget->archive_at );
	}
}
