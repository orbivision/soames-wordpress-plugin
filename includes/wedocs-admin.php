<?php
/**
 * Optional weDocs admin/editor fix.
 *
 * weDocs (includes/Assets.php::admin_enqueue) hooks `admin_enqueue_scripts` and
 * enqueues its block bundle `wedocs-block-script` (plus `wedocs-admin-script`) on
 * EVERY admin screen, not just its own — only `wedocs-editor-script` is correctly
 * scoped to the docs editor. Loaded into the block editor for Pages/Posts, weDocs'
 * block script breaks the editor (no title field, can't insert blocks; it fails
 * silently, with no console error).
 *
 * Dequeue weDocs' globally-injected editor scripts on block-editor screens that
 * aren't the `docs` post type, so the Page/Post editor works again while weDocs
 * keeps full functionality on its own admin and docs screens.
 *
 * weDocs is OPTIONAL: wp_dequeue_script() is a no-op when a handle isn't enqueued
 * (e.g. weDocs not installed), so this is harmless either way. Runs at priority
 * 100 — after weDocs' own admin_enqueue (default priority 10) has queued them.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_enqueue_scripts',
	function () {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();

		// Only touch the block editor, and never the docs editor (weDocs needs
		// its scripts there). Leaves every other admin screen untouched.
		if ( ! $screen || ! $screen->is_block_editor() || 'docs' === $screen->post_type ) {
			return;
		}

		wp_dequeue_script( 'wedocs-block-script' );
		wp_dequeue_script( 'wedocs-admin-script' );
	},
	100
);
