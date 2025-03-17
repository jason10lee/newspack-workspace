<?php
/**
 * Newspack Story Budget - class for handling fields.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

use Newspack_Story_Budget\Fields\Editable_Field;
use Newspack_Story_Budget\Fields\Read_Only_Field;

/**
 * Story budget fields.
 */
class Fields {
	/**
	 * Registered fields.
	 *
	 * @var array
	 */
	protected static $all_fields = [];

	/**
	 * Initializes default fields.
	 */
	public static function init() {

		self::register_fields();

		self::initialize_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	public static function initialize_hooks() {
		\add_action( 'save_post_post', [ __CLASS__, 'on_post_update' ] );
	}

	/**
	 * Register fields.
	 */
	public static function register_fields() {
		$default_fields_config = self::get_default_fields_config();

		/**
		 * Filters the story budget fields to register.
		 */
		$field_configs = apply_filters( 'newspack_story_budget_fields', array_merge( $default_fields_config, [] ) );

		foreach ( $field_configs as $field_config ) {
			if ( ! empty( $field_config['editable'] ) ) {
				$field = new Editable_Field( $field_config );
			} else {
				$field = new Read_Only_Field( $field_config );
			}

			if ( isset( self::$all_fields[ $field->get_slug() ] ) ) {
				Logger::error( sprintf( 'Field with slug %s already exists.', $field->get_slug() ) );
				continue;
			}

			// Don't register the field if creating it threw any errors.
			if ( $field->has_errors() ) {
				$field_errors = $field->get_errors();
				Logger::error( $field_errors->get_error_messages() );
				continue;
			}
			self::$all_fields[ $field->get_slug() ] = $field;
		}
	}

	/**
	 * Get config for default fields.
	 *
	 * @return array
	 */
	public static function get_default_fields_config() {
		return [
			// Editable fields.
			[
				'description' => __( 'The internal name for the story.', 'newspack-story-budget' ),
				'editable'    => true,
				'name'        => __( 'Story Name', 'newspack-story-budget' ),
				'slug'        => 'name',
				'type'        => 'text',
			],
			[
				'default'     => 'writing',
				'description' => __( 'The current editorial status of the story.', 'newspack-story-budget' ),
				'editable'    => true,
				'filterable'  => true,
				'name'        => __( 'Status', 'newspack-story-budget' ),
				'slug'        => 'status',
				'type'        => 'select',

				/**
				 * Filters the story budget statuses.
				 *
				 * @param array $statuses Keyed array of available statuses for story budget lines.
				 */
				'values'      => apply_filters(
					'newspack_story_budget_statuses',
					[
						'writing'   => __( 'Writing', 'newspack-story-budget' ),
						'editing'   => __( 'Editing', 'newspack-story-budget' ),
						'factcheck' => __( 'Fact-checking', 'newspack-story-budget' ),
						'approved'  => __( 'Approved', 'newspack-story-budget' ),
						'published' => __( 'Published', 'newspack-story-budget' ),
					]
				),
			],

			// Read-only fields.
			[
				'callback'    => [ __CLASS__, 'get_word_count' ],
				'description' => __( 'The word count of the story.', 'newspack-story-budget' ),
				'editable'    => false,
				'name'        => __( 'Length', 'newspack-story-budget' ),
				'slug'        => 'word-count',
				'type'        => 'number',
			],
			[
				'callback'    => [ __CLASS__, 'get_publish_date' ],
				'description' => __( 'The date the story was published online.', 'newspack-story-budget' ),
				'editable'    => false,
				'name'        => __( 'Publish date (online)', 'newspack-story-budget' ),
				'slug'        => 'publish-date-online',
				'type'        => 'number',
			],
		];
	}

	/**
	 * Get all registered fields.
	 */
	public static function get_all_fields() {
		return self::$all_fields;
	}

	/**
	 * Get a field by its slug.
	 *
	 * @param string $slug The slug for the field to get.
	 */
	public static function get_field( $slug ) {
		if ( ! isset( self::$all_fields[ $slug ] ) ) {
			return null;
		}
		return self::$all_fields[ $slug ];
	}

	/**
	 * Update stored field value when post is updated.
	 *
	 * @param int $post_id The post ID being updated.
	 */
	public static function on_post_update( $post_id ) {

		$fields = self::get_all_fields();

		foreach ( $fields as $field ) {
			if ( ! $field->get_callback() ) {
				continue;
			}
			$value = call_user_func( $field->get_callback(), $post_id );
			if ( ! empty( $value ) ) {
				\update_post_meta( $post_id, $field->get_post_meta_name(), $value );
			}
		}
	}

	/**
	 * Get the word count of the post's content.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return int The word count.
	 */
	public static function get_word_count( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$post = \get_post( $post_id );
		if ( ! $post ) {
			return 0;
		}
		return str_word_count( trim( \wp_strip_all_tags( $post->post_content ) ) );
	}

	/**
	 * Get the publish date of the post.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string The post's online publish date, if any.
	 */
	public static function get_publish_date( $post_id = null ) {
		if ( ! $post_id ) {
			$post_id = \get_the_ID();
		}
		$post = \get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		return get_the_date( null, $post );
	}
}
