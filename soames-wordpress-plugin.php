<?php
/**
 * Plugin Name: Soames Site Assets
 * Plugin URI:  https://soames.app
 * Description: Configurable site assets (logo, favicon, contact blurb) for the Soames Gatsby theme.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Author:      Orbi Software
 */

defined( 'ABSPATH' ) || exit;

define( 'SOAMES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOAMES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SOAMES_PLUGIN_DIR . 'includes/admin.php';
require_once SOAMES_PLUGIN_DIR . 'includes/settings-api.php';
