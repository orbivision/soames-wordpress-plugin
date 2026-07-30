<?php
/**
 * Plugin Name: Soames
 * Plugin URI:  https://soames.app
 * Description: Site configuration, preview support, media assets, and WPGraphQL extensions for the Soames Astro theme.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Author:      Orbi Software
 */

defined( 'ABSPATH' ) || exit;

// Must run before WPGraphQL builds its schema (before graphql_register_types).
// Called directly here rather than in an init hook to ensure it's registered
// early enough for WPGraphQL to pick it up regardless of hook order.
add_post_type_support( 'page', 'excerpt' );

define( 'SOAMES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOAMES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SOAMES_PLUGIN_DIR . 'includes/admin.php';
require_once SOAMES_PLUGIN_DIR . 'includes/user-avatar.php';
require_once SOAMES_PLUGIN_DIR . 'includes/nav-menus.php';
require_once SOAMES_PLUGIN_DIR . 'includes/preview.php';
require_once SOAMES_PLUGIN_DIR . 'includes/cors.php';
require_once SOAMES_PLUGIN_DIR . 'includes/post-meta.php';
require_once SOAMES_PLUGIN_DIR . 'includes/settings-api.php';
require_once SOAMES_PLUGIN_DIR . 'includes/build-hook.php';
require_once SOAMES_PLUGIN_DIR . 'includes/blocks.php';
require_once SOAMES_PLUGIN_DIR . 'includes/docs-cpt.php';
require_once SOAMES_PLUGIN_DIR . 'includes/docs-ordering.php';
