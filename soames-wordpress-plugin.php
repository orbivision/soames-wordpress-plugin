<?php
/**
 * Plugin Name:       Soames
 * Plugin URI:        https://soames.app
 * Description:       Site configuration, preview support, media assets, and WPGraphQL extensions for the Soames Astro theme.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Requires Plugins:  wp-graphql
 * Author:            Orbi Software
 * Author URI:        https://www.orbisoftware.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       soames
 */

defined( 'ABSPATH' ) || exit;

// Must run before WPGraphQL builds its schema (before graphql_register_types).
// Called directly here rather than in an init hook to ensure it's registered
// early enough for WPGraphQL to pick it up regardless of hook order.
add_post_type_support( 'page', 'excerpt' );

// ORBI-58: moved out of the companion theme's functions.php.
//
// Featured-image support is theme-level in WordPress — a post type declaring 'thumbnail'
// in its `supports` isn't enough on its own. The `docs` CPT declares it and preview.php
// reads get_post_thumbnail_id(), so without this the featured-image box disappears the
// moment someone switches away from the Soames theme. Unlike add_post_type_support above,
// add_theme_support has to run on after_setup_theme to be reliable from a plugin.
//
// The theme's add_theme_support('custom-logo') was deliberately NOT carried over: Soames
// serves its logo from its own soames_logo_id option (see includes/settings-api.php), and
// nothing in the plugin or the Astro theme reads the custom_logo theme mod.
add_action( 'after_setup_theme', function () {
	add_theme_support( 'post-thumbnails' );
} );

define( 'SOAMES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOAMES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SOAMES_PLUGIN_FILE', __FILE__ );

/**
 * The plugin version, read from the header above (ORBI-57).
 *
 * Deliberately NOT a second hardcoded literal. The header is what WordPress itself
 * reads, so it is the one place a release can't forget to update — and the release
 * workflow asserts the git tag matches it. Anything that needs the version at
 * runtime (asset cache-busting, future update checks) should use this constant so
 * there is never a second number to drift.
 *
 * get_file_data() is used rather than get_plugin_data() because the latter lives in
 * wp-admin/includes/plugin.php and isn't loaded on front-end requests.
 */
define(
	'SOAMES_PLUGIN_VERSION',
	get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version']
);

/**
 * Cache-busting version for a bundled asset.
 *
 * Released installs get the plugin version, so one bump busts every asset at once.
 * WP_DEBUG installs get the file mtime instead, because iterating on the editor JS
 * without touching the version otherwise serves stale script from the browser cache.
 * mtime is deliberately NOT used in production: it's the file-copy time, so it
 * differs between installs and changes on every redeploy even when nothing did.
 *
 * @param string $relative_path Path below the plugin root, e.g. 'assets/admin.js'.
 * @return string
 */
function soames_asset_version( $relative_path ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$file = SOAMES_PLUGIN_DIR . ltrim( $relative_path, '/' );
		if ( file_exists( $file ) ) {
			return (string) filemtime( $file );
		}
	}
	return SOAMES_PLUGIN_VERSION;
}

require_once SOAMES_PLUGIN_DIR . 'includes/admin.php';
require_once SOAMES_PLUGIN_DIR . 'includes/user-avatar.php';
require_once SOAMES_PLUGIN_DIR . 'includes/nav-menus.php';
require_once SOAMES_PLUGIN_DIR . 'includes/preview.php';
require_once SOAMES_PLUGIN_DIR . 'includes/frontend-redirect.php';
require_once SOAMES_PLUGIN_DIR . 'includes/cors.php';
require_once SOAMES_PLUGIN_DIR . 'includes/post-meta.php';
require_once SOAMES_PLUGIN_DIR . 'includes/settings-api.php';
require_once SOAMES_PLUGIN_DIR . 'includes/build-hook.php';
require_once SOAMES_PLUGIN_DIR . 'includes/blocks.php';
require_once SOAMES_PLUGIN_DIR . 'includes/docs-cpt.php';
require_once SOAMES_PLUGIN_DIR . 'includes/docs-ordering.php';
