<?php
/**
 * Soames documentation custom post type.
 *
 * Registers the `docs` CPT directly, replacing the dependency on weDocs. weDocs
 * injected editor scripts on every admin screen (and registered its blocks
 * twice), which broke the block editor for Pages/Posts — and on multisite it
 * could stay active per-site even when network-deactivated. Owning the CPT here
 * means docs work with zero third-party editor code: authors use the standard
 * block editor, and hierarchy + order come from the Page Attributes panel
 * (parent + menu order), which the Soames docs sidebar consumes.
 *
 * Exposed to WPGraphQL as Document / Documents — the names the Soames Astro
 * theme's getDocs() already queries. Uses the `docs` slug so documentation
 * authored previously (including under weDocs) is preserved and editable.
 *
 * NOTE: do not run this alongside weDocs — both register the `docs` post type
 * and would collide. weDocs should be deactivated/removed.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	$labels = array(
		'name'               => 'Documentation',
		'singular_name'      => 'Document',
		'menu_name'          => 'Documentation',
		'name_admin_bar'     => 'Document',
		'add_new'            => 'Add New',
		'add_new_item'       => 'Add New Document',
		'new_item'           => 'New Document',
		'edit_item'          => 'Edit Document',
		'view_item'          => 'View Document',
		'all_items'          => 'All Documents',
		'search_items'       => 'Search Documents',
		'parent_item_colon'  => 'Parent Document:',
		'not_found'          => 'No documents found.',
		'not_found_in_trash' => 'No documents found in Trash.',
	);

	register_post_type( 'docs', array(
		'labels'              => $labels,
		'public'              => true,
		'hierarchical'        => true, // parent/child nesting for the docs tree
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_nav_menus'   => true,
		'show_in_rest'        => true, // use the block editor
		'menu_position'       => 20,
		'menu_icon'           => 'dashicons-book',
		'supports'            => array( 'title', 'editor', 'page-attributes', 'thumbnail', 'excerpt', 'revisions' ),
		'has_archive'         => false,
		'rewrite'             => array( 'slug' => 'docs', 'with_front' => false ),
		// WPGraphQL exposure — names the Soames front end (getDocs) queries.
		'show_in_graphql'     => true,
		'graphql_single_name' => 'Document',
		'graphql_plural_name' => 'Documents',
	) );
} );
