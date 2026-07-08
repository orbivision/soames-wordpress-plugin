<?php
/**
 * Drag-to-reorder for Knowledge Base (docs CPT) articles (ORBI-38).
 *
 * The docs CPT already supports `page-attributes` (a per-article "Order" field)
 * and the Astro theme already renders /docs/ in menu_order via buildDocTree().
 * This file only adds the admin UX for *setting* that order: drag-and-drop on the
 * Knowledge Base list screen plus a sortable "Order" column.
 *
 * Dragging reorders within an article's existing parent group; it does NOT
 * re-parent (that stays in the "Parent Article" dropdown). A reorder writes
 * menu_order directly and schedules a single (debounced) Netlify rebuild so the
 * live /docs/ grid picks up the new order.
 */

defined( 'ABSPATH' ) || exit;

// ── Enqueue the sortable script on the Knowledge Base list screen only ─────────

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'edit.php' !== $hook ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'docs' !== $screen->post_type ) {
		return;
	}
	wp_enqueue_script(
		'soames-docs-ordering',
		SOAMES_PLUGIN_URL . 'assets/docs-ordering.js',
		[ 'jquery', 'jquery-ui-sortable' ], // jquery-ui-sortable ships with WP core
		'1.0.0',
		true
	);
	wp_localize_script( 'soames-docs-ordering', 'SoamesDocsOrder', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'soames_reorder_docs' ),
	] );
} );

// ── Persist a new order (AJAX) ─────────────────────────────────────────────────

add_action( 'wp_ajax_soames_reorder_docs', function () {
	check_ajax_referer( 'soames_reorder_docs', 'nonce' );

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$order = isset( $_POST['order'] ) ? (array) wp_unslash( $_POST['order'] ) : [];
	$order = array_values( array_filter( array_map( 'absint', $order ) ) );
	if ( empty( $order ) ) {
		wp_send_json_error( 'empty' );
	}

	global $wpdb;

	// Bucket the incoming IDs by their existing parent, preserving the new order,
	// then assign menu_order 0,1,2,… within each sibling group. This reorders
	// siblings correctly and leaves parentage untouched (no drag re-parenting).
	$buckets = [];
	foreach ( $order as $id ) {
		$post = get_post( $id );
		if ( ! $post || 'docs' !== $post->post_type ) {
			continue;
		}
		$buckets[ (int) $post->post_parent ][] = $id;
	}

	foreach ( $buckets as $ids ) {
		foreach ( $ids as $position => $id ) {
			$wpdb->update( $wpdb->posts, [ 'menu_order' => $position ], [ 'ID' => $id ] );
			clean_post_cache( $id );
		}
	}

	// Reordering changes the live /docs/ ordering — schedule one (debounced) build.
	if ( function_exists( 'soames_schedule_build' ) ) {
		soames_schedule_build();
	}

	wp_send_json_success();
} );

// ── "Order" column on the Knowledge Base list screen ───────────────────────────

add_filter( 'manage_docs_posts_columns', function ( $columns ) {
	$out = [];
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'title' === $key ) {
			$out['menu_order'] = 'Order';
		}
	}
	// Fallback if there's no title column for some reason.
	if ( ! isset( $out['menu_order'] ) ) {
		$out['menu_order'] = 'Order';
	}
	return $out;
} );

add_action( 'manage_docs_posts_custom_column', function ( $column, $post_id ) {
	if ( 'menu_order' === $column ) {
		echo (int) get_post( $post_id )->menu_order;
	}
}, 10, 2 );

add_filter( 'manage_edit-docs_sortable_columns', function ( $columns ) {
	$columns['menu_order'] = 'menu_order';
	return $columns;
} );

// ── Default the list to menu_order so the drag order is what authors see ───────

add_action( 'pre_get_posts', function ( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-docs' !== $screen->id ) {
		return;
	}
	if ( ! $query->get( 'orderby' ) ) {
		$query->set( 'orderby', [ 'menu_order' => 'ASC', 'title' => 'ASC' ] );
	}
} );
