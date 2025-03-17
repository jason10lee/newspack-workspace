<?php
/**
 * Class TestFields
 *
 * @package Newspack_Story_Budget
 */

use Newspack_Story_Budget\Fields;
use Newspack_Story_Budget\Fields\Editable_Field;
use Newspack_Story_Budget\Fields\Read_Only_Field;

/**
 * Class for testing fields and their functionality.
 */
class TestFields extends WP_UnitTestCase {
	/**
	 * Create a test post
	 *
	 * @param array $data Data used to create the post.
	 * @return int
	 */
	public static function create_post( $data = [] ) {
		$default = [
			'post_title'   => 'Test post',
			'post_content' => 'Test post content',
			'post_status'  => 'publish',
		];
		return wp_insert_post( wp_parse_args( $data, $default ) );
	}

	/**
	 * Test registering default fields.
	 */
	public function test_register_default_fields() {
		$all_fields = Fields::get_all_fields();
		$this->assertNotEmpty( $all_fields, 'Default fields have been registered.' );
	}

	/**
	 * Test an editable field.
	 */
	public function test_editable_field() {
		$post_id      = self::create_post();
		$field_config = [
			'name' => 'Editable',
			'type' => 'text',
		];

		$field = new Editable_Field( $field_config );
		$this->assertEquals( 'Editable', $field->get_name(), 'Field name is correct.' );
		$this->assertEquals( 'text', $field->get_type(), 'Field type is correct.' );
		$this->assertEquals( 'editable', $field->get_slug(), 'Field slug is generated if not provided.' );
		$this->assertTrue( $field->is_editable(), 'Field is editable.' );
		$this->assertEquals(
			$field->get_value( $post_id, 'default value' ),
			'default value',
			'Field returns default value before value is set.'
		);

		$field->update_value( $post_id, 'new value' );
		$this->assertEquals(
			$field->get_value( $post_id, 'default value' ),
			'new value',
			'Field returns set value.'
		);
	}

	/**
	 * Test a read-only field.
	 */
	public function test_read_only_field() {
		$post_id      = self::create_post();
		$field_config = [
			'callback' => 'get_the_title',
			'name'     => 'Read-Only',
		];

		$field = Fields::get_field( 'word-count' );

		$this->assertEquals( 'Length', $field->get_name(), 'Field name is correct.' );
		$this->assertFalse( $field->is_editable(), 'Field is not editable.' );
		$this->assertEquals(
			$field->get_value( $post_id ),
			3,
			'Field uses callback to get value.'
		);
		\wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'One more little word',
			]
		);

		$this->assertEquals(
			\get_post_meta( $post_id, $field::FIELD_PREFIX . $field->get_slug(), true ),
			4,
			'Read-only field values are stored as post meta on post update.'
		);
		$this->assertTrue(
			\is_wp_error( $field->update_value( $post_id, 'new value' ) ),
			'Cannot directly update a read-only field value.'
		);
	}

	/**
	 * Test invalid field configuration.
	 */
	public function test_invalid_field_configuration() {
		$select = new Editable_Field(
			[
				'name' => 'Invalid Type',
				'type' => 'foobar',
			]
		);
		$this->assertTrue( $select->has_errors(), 'Provided field type must be valid.' );
		$select = new Read_Only_Field(
			[
				'name' => 'Slug is too long',
				'slug' => 'a_very_long_string_that_is_too_long_' . \wp_generate_password( 191, false ),
			]
		);
		$this->assertTrue( $select->has_errors(), 'Provided field slug must not be too long.' );
		$select = new Editable_Field(
			[
				'name' => 'Select Dropdown',
				'type' => 'select',
			]
		);
		$this->assertTrue( $select->has_errors(), 'Select fields must have a list of available options.' );
		$select = new Editable_Field(
			[
				'name'   => 'Select Dropdown',
				'type'   => 'select',
				'values' => [
					'option1' => 'Option 1',
					'option2' => 'Option 2',
				],
			]
		);
		$this->assertFalse( $select->has_errors(), 'Select fields are registered with a list of available options.' );

		$read_only = new Read_Only_Field(
			[ 'name' => 'Read-Only' ]
		);
		$this->assertTrue( $read_only->has_errors(), 'Read-only fields must have a callback to get their value.' );

		$read_only = new Read_Only_Field(
			[
				'callback' => 'get_the_ID',
				'name'     => 'Read-Only',
			]
		);
		$this->assertFalse( $read_only->has_errors(), 'Read-only fields are registered with a callback to get their value.' );
	}
}
