<?php
/**
 * Newspack Story Budget - abstract class for a story budget field.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\Fields;

/**
 * Class for editable fields.
 */
class Editable_Field extends Abstract_Field {
	/**
	 * The type of the field's input UI, e.g. 'text', 'number', 'date', 'select', 'checkbox'.
	 *
	 * @var string
	 */
	protected $type = 'text';

	/**
	 * Whether the field is editable or read-only.
	 *
	 * @var bool
	 */
	protected $editable = true;

	/**
	 * Register an editable field.
	 *
	 * @param array $args Configuration for registering a field. See abstract class constructor for possible params.
	 */
	public function __construct( $args ) {

		parent::__construct( $args );

		$type = $this->set_type( $args['type'] );
		if ( \is_wp_error( $type ) ) {
			$this->errors->add( $type->get_error_code(), $type->get_error_message() );
		}

		if ( 'select' === $this->get_type() && empty( $args['values'] ) ) {
			$this->errors->add(
				'newspack_story_budget_invalid_field_configuration',
				__( 'A select field must have values.', 'newspack-story-budget' )
			);
		}
	}

	/**
	 * Get the field's type.
	 *
	 * @return string The field's type.
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Sets the field's UI input type.
	 *
	 * @param string $type The field's type.
	 */
	protected function set_type( $type ) {
		/**
		 * Filters the allowed editable field types.
		 *
		 * @param string[] $allowed_types The allowed field types for editable fields.
		 */
		$allowed_types = apply_filters( 'newspack_story_budget_editable_field_types', [ 'boolean', 'text', 'number', 'date', 'select', 'checkbox' ] );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			return new \WP_Error(
				'newspack_story_budget_invalid_field_configuration',
				sprintf(
					// Translators: 1: Field type passed in configuration, 2: Allowed field types.
					__( 'Invalid field type "%1$s". Must be one of: %2$s', 'newspack-story-budget' ),
					$type,
					implode( ', ', $allowed_types )
				)
			);
		}
		$this->type = $type;
		return $this->type;
	}

	/**
	 * Update the value of the field.
	 * Only editable fields can have their value updated directly.
	 *
	 * @param int   $post_id The post ID to update the value for.
	 * @param mixed $value The new value of the field.
	 *
	 * @return bool True if updated successfully, otherwise false.
	 */
	public function update_value( $post_id, $value ) {
		return $this->update_stored_value( $post_id, $value );
	}

	/**
	 * Reset the value of the field to null.
	 * After being reset, the field will effectively return a default value, if any.
	 *
	 * @param int $post_id The post ID to reset the value for.
	 *
	 * @return bool True if reset successfully, otherwise false.
	 */
	public function delete_value( $post_id ) {
		$updated = \delete_post_meta( $post_id, $this->get_post_meta_name() );
		if ( ! $updated ) {
			return false;
		}
		return true;
	}
}
