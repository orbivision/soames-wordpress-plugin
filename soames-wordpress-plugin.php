<?php
/**
 * Plugin Name: Soames
 * Plugin URI:  https://soames.app
 * Description: Site configuration, preview support, media assets, and WPGraphQL extensions for the Soames Gatsby theme.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Author:      Orbi Software
 */

defined( 'ABSPATH' ) || exit;

define( 'SOAMES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOAMES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SOAMES_PLUGIN_DIR . 'includes/admin.php';
require_once SOAMES_PLUGIN_DIR . 'includes/preview.php';
require_once SOAMES_PLUGIN_DIR . 'includes/cors.php';
require_once SOAMES_PLUGIN_DIR . 'includes/post-meta.php';
require_once SOAMES_PLUGIN_DIR . 'includes/settings-api.php';
