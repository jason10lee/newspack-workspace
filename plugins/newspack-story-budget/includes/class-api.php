<?php
/**
 * Newspack Story Budget API
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * API Class.
 */
class API {

	/**
	 * API Namespace
	 *
	 * @var string
	 */
	const NAMESPACE = 'newspack-story-budget/v1';

	/**
	 * Default limit of items to return.
	 */
	const DEFAULT_LIMIT = 1000;

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/stories',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_stories' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					'limit'  => [
						'description' => __( 'Number of stories to return.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
					'offset' => [
						'description' => __( 'Offset.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/meta',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_stories_meta' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/meta/batch',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'get_stories_meta_batch' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					'story_ids' => [
						'description' => __( 'Array of story IDs to fetch meta for.', 'newspack-story-budget' ),
						'type'        => 'array',
						'items'       => [
							'type' => 'integer',
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/search',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'get_stories_search' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					's' => [
						'description' => __( 'Search query.', 'newspack-story-budget' ),
						'type'        => 'string',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_story' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/(?P<id>\d+)/meta',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_story_meta' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/(?P<id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'update_story' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/stories/(?P<id>\d+)/(?P<slug>[\a-z]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'update_story_field' ],
				'permission_callback' => [ __CLASS__, 'stories_permission_callback' ],
				'args'                => [
					'id'    => [
						'description' => __( 'The post ID of the story to update.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
					'slug'  => [
						'description' => __( 'The slug of the field to update.', 'newspack-story-budget' ),
						'type'        => 'string',
					],
					'value' => [
						'description' => __( 'The value to update the field with.', 'newspack-story-budget' ),
						'type'        => 'mixed',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/fields',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_fields' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'fields' => [
						'description' => __( 'Array of field slugs to return. If not provided, all fields will be returned.', 'newspack-story-budget' ),
						'type'        => 'array',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/budgets',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_budgets' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					'limit'  => [
						'description' => __( 'Number of budgets to return.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
					'offset' => [
						'description' => __( 'Offset.', 'newspack-story-budget' ),
						'type'        => 'integer',
					],
				],
			]
		);
		// @TODO Add more routes for budget CRUD.

		register_rest_route(
			self::NAMESPACE,
			'/budgets/(?P<id>\d+)/stories',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_budget_stories' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
			]
		);
		register_rest_route(
			self::NAMESPACE,
			'/budgets/(?P<id>\d+)/stories/search',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'get_budget_stories_search' ],
				'permission_callback' => [ __CLASS__, 'permission_callback' ],
				'args'                => [
					's' => [
						'description' => __( 'Search query.', 'newspack-story-budget' ),
						'type'        => 'string',
					],
				],
			]
		);
	}

	/**
	 * Permission callback for non-story entities.
	 *
	 * @return bool
	 */
	public static function permission_callback() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission callback for stories.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return bool
	 */
	public static function stories_permission_callback( $request ) {
		// Check if the user can edit a single story.
		$id = $request->get_param( 'id' );
		if ( $id ) {
			return current_user_can( 'edit_post', $id );
		}

		// Check if the user can edit the stories in batch requests.
		$story_ids = $request->get_param( 'story_ids' );
		if ( $story_ids ) {
			foreach ( $story_ids as $story_id ) {
				if ( ! current_user_can( 'edit_post', $story_id ) ) {
					return false;
				}
			}
		}

		return current_user_can( 'edit_posts' );
	}

	/**
	 * Get stories.
	 *
	 * @param \WP_Rest_Request $request Request object.
	 *
	 * @return \WP_Rest_Response
	 */
	public static function get_stories( $request ) {
		$query_args = [
			'posts_per_page' => $request->get_param( 'limit' ) ?? self::DEFAULT_LIMIT,
			'offset'         => $request->get_param( 'offset' ) ?? 0,
		];

		// If the user is not an editor, filter the stories by the user's stories.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$stories = Budgets::get_stories( $query_args );

		return rest_ensure_response(
			[
				'stories' => array_map(
					function( $story ) {
						return $story->to_array( false );
					},
					$stories
				),
				'total'   => Budgets::$stories_query->found_posts,
			]
		);
	}

	/**
	 * Get stories meta.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_stories_meta( $request ) {
		return rest_ensure_response(
			[
				'can_edit' => current_user_can( 'edit_others_posts' ),
			]
		);
	}

	/**
	 * Get stories meta batch.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_stories_meta_batch( $request ) {
		$story_ids = $request->get_param( 'story_ids' );

		$results = [];
		foreach ( $story_ids as $story_id ) {
			$story = new Story( $story_id );
			if ( ! $story->is_valid() ) {
				continue;
			}
			$results[ $story_id ] = $story->get_metadata();
		}

		return rest_ensure_response( $results );
	}

	/**
	 * Get stories search.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_stories_search( $request ) {
		$query_args = [
			'fields'         => 'ids',
			'posts_per_page' => -1,
			's'              => $request->get_param( 's' ) ?? '',
		];

		// If the user is not an editor, filter the stories by the user's stories.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		return rest_ensure_response(
			[
				'story_ids' => Budgets::get_stories( $query_args ),
				'total'     => Budgets::$stories_query->found_posts,
			]
		);
	}

	/**
	 * Get story.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_story( $request ) {
		$story = new Story( $request->get_param( 'id' ) );
		if ( ! $story->is_valid() ) {
			return new \WP_Error( 'story_not_found', __( 'Story not found.', 'newspack-story-budget' ), [ 'status' => 404 ] );
		}

		// Refresh read-only field values.
		$story->refresh();

		return rest_ensure_response( $story->to_array() );
	}

	/**
	 * Get story meta.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_story_meta( $request ) {
		$story = new Story( $request->get_param( 'id' ) );
		if ( ! $story->is_valid() ) {
			return new \WP_Error( 'story_not_found', __( 'Story not found.', 'newspack-story-budget' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $story->get_metadata() );
	}

	/**
	 * Sanitize a value based on the field's type.
	 *
	 * @param \Newspack_Story_Budget\Fields\Abstract_Field $field The field to validate against.
	 * @param mixed                                        $value The value to validate.
	 *
	 * @return mixed The sanitized value, or null if the value can't be sanitized to the field's expected type.
	 */
	private static function sanitize_field_value( $field, $value ) {
		$type = $field->get_type();
		if ( is_array( $value ) ) {
			return array_values(
				array_filter(
					array_map(
						function( $single_value ) use ( $field ) {
							return self::sanitize_field_value( $field, $single_value );
						},
						$value
					),
					function( $value ) {
						return ! is_null( $value );
					}
				)
			);
		}
		if ( 'boolean' === $type ) {
			return \rest_sanitize_boolean( $value );
		}
		if ( 'number' === $type ) {
			return is_numeric( $value ) ? (float) $value : null;
		}
		if ( 'text' === $type || 'longtext' === $type ) {
			return \sanitize_text_field( $value );
		}

		// Date values are stored as UNIX timestamps.
		if ( 'date' === $type || 'datetime' === $type ) {
			if ( (int) $value === (int) (string) $value && (int) $value <= PHP_INT_MAX && (int) $value >= ~PHP_INT_MAX ) {
				return (int) $value;
			}
		}
		return null;
	}

	/**
	 * Update a story.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_story( $request ) {
		$story = new Story( $request->get_param( 'id' ) );
		if ( ! $story->is_valid() ) {
			return new \WP_Error(
				'story_not_found',
				sprintf(
					// translators: %d is the story ID.
					__( 'Story with ID "%d" not found.', 'newspack-story-budget' ),
					$request->get_param( 'id' )
				),
				[ 'status' => 404 ]
			);
		}
		$params  = $request->get_params();
		$payload = [];
		foreach ( $params as $key => $value ) {
			$field = Fields::get_field( $key );
			if ( ! $field || ! $field->is_editable() ) {
				continue;
			}
			$value = self::sanitize_field_value( $field, $value );
			if ( null === $value && null !== $request->get_param( $key ) ) {
				return new \WP_Error(
					'invalid_value',
					sprintf(
						// Translators: field data type.
						__( 'Invalid value for field type "%s".', 'newspack-story-budget' ),
						$field->get_type()
					)
				);
			}
			$payload[ $key ] = $value;
		}
		$result = $story->update( $payload );
		return rest_ensure_response( \is_wp_error( $result ) ? $result : $story->to_array() );
	}

	/**
	 * Update a story field.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_story_field( $request ) {
		$story = new Story( $request->get_param( 'id' ) );
		$field  = $request->get_param( 'slug' ) ? Fields::get_field( $request->get_param( 'slug' ) ) : null;
		$value = self::sanitize_field_value( $field, $request->get_param( 'value' ) );
		if ( ! $story->is_valid() ) {
			return new \WP_Error(
				'story_not_found',
				sprintf(
					// translators: %d is the story ID.
					__( 'Story with ID "%d" not found.', 'newspack-story-budget' ),
					$request->get_param( 'id' )
				)
			);
		}
		if ( empty( $field ) ) {
			return new \WP_Error(
				'missing_field',
				__( 'Missing field.', 'newspack-story-budget' )
			);
		}
		if ( null === $value && null !== $request->get_param( 'value' ) ) {
			return new \WP_Error(
				'invalid_value',
				sprintf(
					// Translators: field data type.
					__( 'Invalid value for field type "%s".', 'newspack-story-budget' ),
					$field->get_type()
				)
			);
		}

		$result = $story->update( [ $field->get_slug() => $value ] );
		return rest_ensure_response( \is_wp_error( $result ) ? $result : $story->to_array() );
	}

	/**
	 * Get story fields.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_fields( $request ) {
		$fields_to_get = $request->get_param( 'fields' ) ?? [];
		$fields = Fields::get_all_fields( true );
		if ( ! empty( $fields_to_get ) ) {
			$fields = array_values(
				array_filter(
					$fields,
					function( $field ) use ( $fields_to_get ) {
						return in_array( $field['slug'], $fields_to_get );
					}
				)
			);
		}
		return rest_ensure_response( array_values( $fields ) );
	}

	/**
	 * Get budgets.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_budgets( $request ) {
		$limit  = $request->get_param( 'limit' ) ?? self::DEFAULT_LIMIT;
		$offset = $request->get_param( 'offset' ) ?? 0;

		$budgets = array_map(
			function( $budget ) {
				return $budget->to_array();
			},
			Budgets::get_budgets()
		);
		$total   = count( $budgets );

		// Limit and offset.
		if ( $limit < count( $budgets ) ) {
			$budgets = array_slice( $budgets, $offset, $limit );
		}

		return rest_ensure_response(
			[
				'budgets' => $budgets,
				'total'   => $total,
			]
		);
	}

	/**
	 * Get budget stories.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_budget_stories( $request ) {
		$budget = new Budget( $request->get_param( 'id' ) );
		if ( ! $budget->is_valid() ) {
			return new \WP_Error( 'budget_not_found', __( 'Budget not found.', 'newspack-story-budget' ), [ 'status' => 404 ] );
		}

		$query_args = [];

		// If the user is not an editor, filter the stories by the user's stories.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$stories = $budget->get_stories( $query_args );

		return rest_ensure_response(
			[
				'stories' => array_map(
					function( $story ) {
						return $story->to_array();
					},
					$stories
				),
				'total'   => Budgets::$stories_query->found_posts,
			]
		);
	}

	/**
	 * Get budget stories search.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_budget_stories_search( $request ) {
		$budget = new Budget( $request->get_param( 'id' ) );
		if ( ! $budget->is_valid() ) {
			return new \WP_Error( 'budget_not_found', __( 'Budget not found.', 'newspack-story-budget' ), [ 'status' => 404 ] );
		}

		$query_args = [
			'fields'         => 'ids',
			'posts_per_page' => -1,
			's'              => $request->get_param( 's' ) ?? '',
		];

		// If the user is not an editor, filter the stories by the user's stories.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		return rest_ensure_response(
			[
				'story_ids' => $budget->get_stories( $query_args ),
				'total'     => Budgets::$stories_query->found_posts,
			]
		);
	}
}
