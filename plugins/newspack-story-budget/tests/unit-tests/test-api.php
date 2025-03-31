<?php
/**
 * Test API
 *
 * @package Newspack_Story_Budget
 */

//phpcs:disable Squiz.Commenting.VariableComment.Missing

namespace Newspack_Story_Budget;

/**
 * Test API Class.
 */
class Test_API extends \WP_UnitTestCase {

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
	 * Setup.
	 */
	public function set_up() {
		parent::set_up();

		$this->administrator = $this->factory->user->create(
			[
				'role' => 'administrator',
			]
		);
		wp_set_current_user( $this->administrator );
	}

	/**
	 * Test get stories.
	 */
	public function test_get_stories() {
		$request = new \WP_REST_Request( 'GET', sprintf( '%s/stories', API::NAMESPACE ) );
		$response = API::get_stories( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 100, $data['stories'] );
		$this->assertEquals( 100, $data['total'] );
	}

	/**
	 * Test get stories as author.
	 */
	public function test_get_stories_as_author() {
		$author = $this->factory->user->create(
			[
				'role' => 'author',
			]
		);
		$post_id = self::factory()->post->create(
			[
				'post_author' => $author,
			]
		);
		$story = new Story( $post_id );
		$story->update_budgets( [ self::$budgets[0] ] );

		wp_set_current_user( $author );

		$request = new \WP_REST_Request( 'GET', sprintf( '%s/stories', API::NAMESPACE ) );
		$response = API::get_stories( $request );

		$data = $response->get_data();

		$this->assertCount( 1, $data['stories'] );
		$this->assertEquals( $post_id, $data['stories'][0]['id'] );
	}

	/**
	 * Test get stories search.
	 */
	public function test_get_stories_search() {
		$story = self::factory()->post->create(
			[
				'post_title' => 'Test Search String',
			]
		);
		wp_set_post_terms( $story, [ self::$budgets[0] ], Budgets::TAXONOMY );

		$request = new \WP_REST_Request( 'GET', sprintf( '%s/stories/search', API::NAMESPACE ) );
		$request->set_param( 's', 'Test Search String' );

		$response = API::get_stories_search( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 1, $data['story_ids'] );
		$this->assertEquals( 1, $data['total'] );
	}

	/**
	 * Test get story.
	 */
	public function test_get_story() {
		$story_id = self::$stories[0];
		$request = new \WP_REST_Request(
			'GET',
			sprintf( '%s/stories/%d', API::NAMESPACE, $story_id )
		);
		$request->set_param( 'id', $story_id );

		$response = API::get_story( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertEquals( $story_id, $data['id'] );
	}

	/**
	 * Test get budgets.
	 */
	public function test_get_budgets() {
		$request = new \WP_REST_Request( 'GET', sprintf( '%s/budgets', API::NAMESPACE ) );
		$response = API::get_budgets( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 2, $data['budgets'] );
		$this->assertEquals( 2, $data['total'] );
	}

	/**
	 * Test get budgets limit.
	 */
	public function test_get_budgets_limit() {
		$request = new \WP_REST_Request( 'GET', sprintf( '%s/budgets', API::NAMESPACE ) );
		$request->set_param( 'limit', 1 );

		$response = API::get_budgets( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 1, $data['budgets'] );
		$this->assertEquals( 2, $data['total'] );
	}

	/**
	 * Test get budget stories.
	 */
	public function test_get_budget_stories() {
		$budget_id = self::$budgets[0];
		$request = new \WP_REST_Request( 'GET', sprintf( '%s/budgets/%d/stories', API::NAMESPACE, $budget_id ) );
		$request->set_param( 'id', $budget_id );

		$response = API::get_budget_stories( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 50, $data['stories'] );
		$this->assertEquals( 50, $data['total'] );
	}

	/**
	 * Test get budget stories search.
	 */
	public function test_get_budget_stories_search() {
		$budget_id = self::$budgets[0];
		$story = self::factory()->post->create(
			[
				'post_title' => 'Test Search String',
			]
		);
		wp_set_post_terms( $story, [ $budget_id ], Budgets::TAXONOMY );

		$request = new \WP_REST_Request( 'GET', sprintf( '%s/budgets/%d/stories/search', API::NAMESPACE, $budget_id ) );
		$request->set_param( 'id', $budget_id );
		$request->set_param( 's', 'Test Search String' );

		$response = API::get_budget_stories_search( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();

		$this->assertCount( 1, $data['story_ids'] );
		$this->assertEquals( 1, $data['total'] );
	}
}
