<?php
/**
 * Newspack Story Budget Admin
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

/**
 * Admin Class.
 */
class Admin {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ __CLASS__, 'remove_admin_notices' ], -PHP_INT_MAX );
		add_action( 'all_admin_notices', [ __CLASS__, 'remove_admin_notices' ], -PHP_INT_MAX );
		add_action( 'network_admin_notices', [ __CLASS__, 'remove_admin_notices' ], -PHP_INT_MAX );
	}

	/**
	 * Register the admin page.
	 */
	public static function register_admin_page() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M19 4H9C8.46957 4 7.96086 4.21071 7.58579 4.58579C7.21071 4.96086 7 5.46957 7 6V7H5C4.46957 7 3.96086 7.21071 3.58579 7.58579C3.21071 7.96086 3 8.46957 3 9V17.25C3 17.6111 3.07113 17.9687 3.20933 18.3024C3.34753 18.636 3.5501 18.9392 3.80546 19.1945C4.06082 19.4499 4.36398 19.6525 4.69762 19.7907C5.03127 19.9289 5.38886 20 5.75 20H19C19.5304 20 20.0391 19.7893 20.4142 19.4142C20.7893 19.0391 21 18.5304 21 18V6C21 5.46957 20.7893 4.96086 20.4142 4.58579C20.0391 4.21071 19.5304 4 19 4ZM5.75 18.5C5.06 18.5 4.5 17.94 4.5 17.25V9C4.5 8.86739 4.55268 8.74021 4.64645 8.64645C4.74021 8.55268 4.86739 8.5 5 8.5H7C7 9.087 6.998 14.616 7 17.25C7 17.94 6.44 18.5 5.75 18.5ZM19.5 18C19.5 18.1326 19.4473 18.2598 19.3536 18.3536C19.2598 18.4473 19.1326 18.5 19 18.5H8.5V6C8.5 5.86739 8.55268 5.74021 8.64645 5.64645C8.74021 5.55268 8.86739 5.5 9 5.5H19C19.1326 5.5 19.2598 5.55268 19.3536 5.64645C19.4473 5.74021 19.5 5.86739 19.5 6V18ZM10.5 7.502H17.5V9.002H10.5V7.502ZM10.5 10.997H17.5V12.497H10.5V10.997ZM10.5 14.5H17.5V16H10.5V14.5Z" /></svg>';
		$icon = sprintf(
			'data:image/svg+xml;base64,%s',
			base64_encode( $svg )
		);
		add_menu_page(
			__( 'Story Budget', 'newspack-story-budget' ),
			__( 'Story Budget', 'newspack-story-budget' ),
			'edit_others_posts',
			'newspack-story-budget',
			[ __CLASS__, 'render_admin_page' ],
			$icon,
			6
		);
	}

	/**
	 * Enqueue assets.
	 */
	public static function enqueue_assets() {
		if ( ! isset( $_GET['page'] ) || 'newspack-story-budget' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_enqueue_script(
			'newspack-story-budget',
			plugin_dir_url( __DIR__ ) . 'dist/story-budget.js',
			[ 'wp-components', 'wp-data-controls' ],
			filemtime( NEWSPACK_STORY_BUDGET_PLUGIN_DIR . 'dist/story-budget.js' ),
			true
		);
		wp_localize_script(
			'newspack-story-budget',
			'newspackStoryBudget',
			[
				'apiNamespace' => API::NAMESPACE,
			]
		);
		wp_enqueue_style(
			'newspack-story-budget',
			plugin_dir_url( __DIR__ ) . 'dist/story-budget.css',
			[ 'wp-components' ],
			filemtime( NEWSPACK_STORY_BUDGET_PLUGIN_DIR . 'dist/story-budget.css' )
		);
	}

	/**
	 * Render the admin page.
	 */
	public static function render_admin_page() {
		?>
		<div id="newspack-story-budget-app"></div>
		<?php
	}

	/**
	 * Remove admin notices from the Story Budget page.
	 *
	 * @return array
	 */
	public static function remove_admin_notices() {
		if ( ! isset( $_GET['page'] ) || 'newspack-story-budget' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		remove_all_actions( current_action() );
	}
}
