<?php
defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', function() {
	register_nav_menus( array(
		'header' => 'Header Menu',
		'footer' => 'Footer Menu',
	) );
} );

/**
 * Make the "Knowledge Base" panel available on Appearance → Menus (ORBI-60).
 *
 * WordPress hides it, and not because anything is misconfigured. The first time a user opens
 * the Menus screen, wp_initial_nav_menu_meta_boxes() (wp-admin/includes/nav-menu.php) walks
 * every registered panel and hides all but a hardcoded four:
 *
 *     array( 'add-post-type-page', 'add-post-type-post', 'add-custom-links', 'add-category' )
 *
 * Every custom post type is therefore hidden on first visit — `docs` included, despite
 * show_in_nav_menus => true — and the result is written straight to the user's
 * metaboxhidden_nav-menus meta. That list has no filter, so a plugin can't add itself to it.
 * The panel is still there under Screen Options, just unticked, which reads as "I can't add a
 * Knowledge Base article to a menu any more".
 *
 * Two details make this bite more than once:
 *   - it is stored PER USER, so a second admin account starts hidden again;
 *   - get_user_option() is blog-prefixed, so on multisite (which this install is) every site
 *     in the network gets its own copy, and a newly created site starts hidden again.
 *
 * So we un-hide it ONCE per user, then record that we've done so. One-time rather than forced
 * on every load, because after that the checkbox is the user's to control — a plugin that
 * re-ticks it on every page view would make it impossible to turn off.
 *
 * Hooked on admin_head-nav-menus.php deliberately: nav-menus.php calls
 * wp_initial_nav_menu_meta_boxes() at the top of the file, so by the time admin_head fires
 * core has already written its list and there is nothing to race. The panels themselves are
 * rendered later in the body, so the corrected value is what they read.
 */
add_action( 'admin_head-nav-menus.php', 'soames_unhide_docs_nav_menu_panel' );

function soames_unhide_docs_nav_menu_panel() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	// Already corrected for this user (and, on multisite, this site) — leave their choice be.
	if ( get_user_option( 'soames_navmenu_docs_unhidden', $user_id ) ) {
		return;
	}

	$hidden = get_user_option( 'metaboxhidden_nav-menus', $user_id );

	if ( is_array( $hidden ) && in_array( 'add-post-type-docs', $hidden, true ) ) {
		// update_user_option() is blog-prefixed by default, matching get_user_option() above.
		// That's what keeps this correct on multisite.
		update_user_option(
			$user_id,
			'metaboxhidden_nav-menus',
			array_values( array_diff( $hidden, array( 'add-post-type-docs' ) ) )
		);
	}

	update_user_option( $user_id, 'soames_navmenu_docs_unhidden', 1 );
}
