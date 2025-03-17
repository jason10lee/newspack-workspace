<?php
/**
 * Newspack Story Budget - abstract class for a story budget field.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\Fields;

use Newspack_Story_Budget\Fields;

/**
 * Abstract class to represent a single story budget field.
 */
abstract class Abstract_Field {
	/**
	 * The prefix for all field names when getting or setting as post meta.
	 */
	const FIELD_PREFIX = '_np_story_budget_';

	/**
	 * Whether the field is editable or read-only.
	 *
	 * @var bool
	 */
	protected $editable = false;

	/**
	 * Optional callback used to calculate the value of a field upon post save.
	 *
	 * @var callable|null
	 */
	protected $callback = null;

	/**
	 * Optional callback used to determine if the current user can edit the value of this field, if editable. Defaults to true.
	 *
	 * Note that the user must also be allowed to edit the post itself. This callback doesn't need to check that.
	 *
	 * @var callable|null
	 */
	protected $permission_callback = null;

	/**
	 * Optional description for the field, if editable.
	 *
	 * @var mixed
	 */
	protected $description = null;

	/**
	 * Optional static default value for the field, if editable.
	 *
	 * @var mixed
	 */
	protected $default = null;

	/**
	 * Errors that occurred during field initialization.
	 *
	 * @var \WP_Error
	 */
	protected $errors = null;

	/**
	 * Whether the field can be used to filter stories.
	 *
	 * @var bool
	 */
	protected $filterable = false;

	/**
	 * The human-readable name of the field.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * The unique slug for the field.
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * Optional possible values for the field. If given, the field's value must be one of these.
	 * Required if the field type is 'select'.
	 *
	 * @var mixed
	 */
	protected $values = [];

	/**
	 * Object contructor.
	 *
	 * @param array $args {
	 *    Configuration for initializing a field.
	 *    @type callable $callback?            Optional callback used to get the value of a read-only field.
	 *    @type callable $permission_callback? Optional callback used to determine if the current user can edit the field value.
	 *    @type mixed    $default?             Optional default value for the field, if editable.
	 *    @type string   $name                 The human-readable name of the field.
	 *    @type string   $slug?                The unique slug ID for the field. If not given, will be generated from the name.
	 *    @type string   $type                 The type of the field's data.
	 *    @type array    $values               Optional possible values for the field.
	 * }
	 */
	public function __construct( $args ) {
		$this->errors = new \WP_Error();

		$this->name                = \sanitize_text_field( $args['name'] );
		$this->description         = ! empty( $args['description'] ) ? \sanitize_text_field( $args['description'] ) : null;
		$this->slug                = ! empty( $args['slug'] ) ? \sanitize_title( $args['slug'] ) : \sanitize_title( $this->name );
		$this->filterable          = ! empty( $args['filterable'] ) ? true : false;
		$this->permission_callback = ! empty( $args['permission_callback'] ) && is_callable( $args['permission_callback'] ) ? $args['permission_callback'] : false;

		if ( ! empty( $args['callback'] ) && is_callable( $args['callback'] ) ) {
			$this->callback = $args['callback'];
		}

		if ( 191 < strlen( $this->get_post_meta_name() ) ) {
			$this->errors->add(
				'newspack_story_budget_field_slug_too_long',
				sprintf(
					// Translators: the field slug.
					__( 'The field slug "%s" is too long. Please use a shorter slug.', 'newspack-story-budget' ),
					$this->slug
				)
			);
		}
	}

	/**
	 * Whether the field encountered any errors while being initialized.
	 *
	 * @return bool
	 */
	public function has_errors() {
		return $this->errors->has_errors();
	}

	/**
	 * Get any errors that occurred while initializing the field.
	 *
	 * @return WP_Error Field registration errors.
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Get the field's name.
	 *
	 * @return string The field's name.
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get the prefixed post meta field name.
	 *
	 * @return string The name of the post meta field.
	 */
	public function get_post_meta_name() {
		return self::FIELD_PREFIX . $this->get_slug();
	}

	/**
	 * Get the field's slug.
	 *
	 * @return string The field's slug.
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * True if the field is editable, false if read-only.
	 *
	 * @return bool
	 */
	public function is_editable() {
		return $this->editable;
	}

	/**
	 * Get the field's callback.
	 *
	 * @return callable? The field's callback.
	 */
	public function get_callback() {
		return $this->callback;
	}

	/**
	 * Get the field's value.
	 *
	 * @param int   $post_id The post ID to get the value for. If not passed, return the default value, if any.
	 * @param mixed $default_value The default value to return if no post ID is passed or no value has been set.
	 *                             Allows the default value to be dynamic at the point of retrieval, if needed.
	 *
	 * @return mixed The field's value.
	 */
	public function get_value( $post_id, $default_value = null ) {
		$default = ! is_null( $default_value ) ? $default_value : $this->default;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return $default;
		}
		$value = \get_post_meta( $post_id, $this->get_post_meta_name(), true );

		// If we don't have a stored value but do have a callback to calculate the value, calculate it...
		if ( empty( $value ) && is_callable( $this->callback ) ) {
			$value = call_user_func( $this->callback, $post_id, $default_value );

			// ...then store the value as post meta for faster future retrieval.
			if ( ! empty( $value ) ) {
				$this->update_stored_value( $post_id, $value );
			}
		}
		return ! empty( $value ) ? $value : $default;
	}

	/**
	 * Update the value of the field stored as post meta.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value The new value of the field.
	 *
	 * @return bool True if updated successfully, otherwise false.
	 */
	protected function update_stored_value( $post_id, $value ) {
		$updated = \update_post_meta( $post_id, $this->get_post_meta_name(), $value );
		if ( ! $updated ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a user can edit this field.
	 *
	 * @param int $user_id The user ID.
	 * @return bool
	 */
	public function user_can_edit( $user_id ) {
		if ( ! $this->is_editable() ) {
			return false;
		}
		if ( ! $this->permission_callback || ! is_callable( $this->permission_callback ) ) {
			return true;
		}
		return call_user_func( $this->permission_callback, $user_id );
	}

	/**
	 * Whether the current user can edit the field.
	 *
	 * @return bool
	 */
	public function current_user_can_edit() {
		return $this->user_can_edit( get_current_user_id() );
	}
}
