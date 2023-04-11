<?php
/**
 * Newspack Multi-branded site term meta parent class.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site;

/**
 * Newspack Multi-branded site meta parent class
 */
abstract class Meta {

	/**
	 * The meta type
	 *
	 * @var string
	 */
	public static $type = 'term';

	/**
	 * Initializes the Meta
	 */
	public static function init() {
		self::register_option();
	}

	/**
	 * Register the tem meta
	 *
	 * @return void
	 */
	public static function register_option() {
		$type   = static::get_schema()['type'] ?? 'string';
		$params = [
			'description'   => static::get_description(),
			'single'        => true,
			'show_in_rest'  => [
				'schema' => static::get_schema(),
			],
			'type'          => $type,
			'auth_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		];

		if ( 'term' === static::$type ) {
			$params['object_subtype'] = static::get_taxonomy();
		}

		register_meta(
			static::$type,
			static::get_key(),
			$params
		);
	}

	/**
	 * Get the taxonomy to register the meta to, if meta type is term
	 *
	 * @return string
	 */
	public static function get_taxonomy() {
		return Taxonomy::SLUG;
	}

	/**
	 * Gets the meta key
	 *
	 * @return string
	 */
	abstract public static function get_key();

	/**
	 * Gets the meta description
	 *
	 * @return string
	 */
	abstract public static function get_description();

	/**
	 * Gets the meta schema
	 *
	 * @return array
	 */
	abstract public static function get_schema();

}
