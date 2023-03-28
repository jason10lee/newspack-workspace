<?php
/**
 * Plugin Name: Newspack Multi-branded site
 * Description: A plugin to allow your site to host multiple brands
 * Version: 0.1
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL3
 * Text Domain: newspack-multibranded-site
 * Domain Path: /languages/
 *
 * @package newspack-multibranded-site
 */

defined( 'ABSPATH' ) || exit;

// Define NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR.
if ( ! defined( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR' ) ) {
	define( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Define NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE.
if ( ! defined( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE' ) ) {
	define( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE', __FILE__ );
}

// Load language files.
load_plugin_textdomain( 'newspack-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

require_once __DIR__ . '/vendor/autoload.php';

Newspack_Multibranded_Site\Initializer::init();
