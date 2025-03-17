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
class Read_Only_Field extends Abstract_Field {
	/**
	 * Register the field.
	 *
	 * @param array $args Configuration for registering a field. See abstract class constructor for possible params.
	 */
	public function __construct( $args ) {

		parent::__construct( $args );

		if ( empty( $args['callback'] ) ) {
			$this->errors->add(
				'newspack_story_budget_invalid_field_configuration',
				__( 'Read-only fields must receive a callback function to fetch their value.', 'newspack-story-budget' )
			);
		}
	}

	/**
	 * Return an error message if attempting to update a read-only field value.
	 *
	 * @return WP_Error
	 */
	public function update_value() {
		return new \WP_Error(
			'newspack_story_budget_read_only_field',
			__( "Cannot update a read-only field's value.", 'newspack-story-budget' )
		);
	}

	/**
	 * Return an error message if attempting to delete a read-only field value.
	 *
	 * @return WP_Error
	 */
	public function delete_value() {
		return $this->update_value();
	}
}
