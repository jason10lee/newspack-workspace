<?php
/**
 * Newspack Story Budget Story
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * Story Class.
 */
class Story {
	/**
	 * Story ID.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Story post object.
	 *
	 * @var \WP_Post
	 */
	public $post;

	/**
	 * Constructor.
	 *
	 * @param int|\WP_Post $post Story ID or post object.
	 */
	public function __construct( $post ) {
		if ( $post instanceof \WP_Post ) {
			$this->id   = $post->ID;
			$this->post = $post;
		} else {
			$this->id   = $post;
			$this->post = get_post( $post );
		}
	}

	/**
	 * Whether it's a valid story.
	 *
	 * @return bool
	 */
	public function is_valid() {
		return ! empty( $this->id ) && ! empty( $this->post ) && ! is_wp_error( $this->post );
	}

	/**
	 * Get random value.
	 *
	 * @return string
	 */
	protected static function get_random_value() {
		$values = [ 'Lorem', 'Ipsum', 'Dolor', 'Sit', 'Amet' ];
		return $values[ array_rand( $values ) ];
	}

	/**
	 * Get random value.
	 *
	 * @return string
	 */
	protected static function get_random_status() {
		$values = [ 'writing', 'editing', 'pitch', 'ready' ];
		return $values[ array_rand( $values ) ];
	}

	/**
	 * Get random date.
	 *
	 * @return string
	 */
	protected static function get_random_date() {
		return gmdate( 'Y-m-d', wp_rand( strtotime( '-2 weeks' ), time() ) );
	}

	/**
	 * Get story in array format.
	 *
	 * @return array
	 */
	public function to_array() {
		// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
		return [
			'id'          => $this->id,
			'title'       => get_the_title( $this->post ),
			'slug'        => get_post_field( 'post_name', $this->post ),
			'preview_url' => add_query_arg( 'newspack-story-preview', true, get_permalink( $this->id ) ),
			'budgets'     => wp_get_post_terms( $this->id, Budgets::TAXONOMY, [ 'fields' => 'ids' ] ),
			// @TODO Implement Fields.
			'image_count'            => wp_rand( 1, 10 ),
			'word_count'             => wp_rand( 500, 800 ),
			'length_in'              => wp_rand( 5, 15 ),
			'status'                 => self::get_random_status(),
			'print_rank'             => self::get_random_value(),
			'print_publication_date' => self::get_random_date(),
			'publication'            => self::get_random_value(),
			'print_page'             => self::get_random_value(),
			'locked'                 => (bool) wp_rand( 0, 1 ),
		];
		// phpcs:enable
	}
}
