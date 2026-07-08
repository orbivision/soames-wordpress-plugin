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
 * NAMING (ORBI-36): the human-facing labels read "Knowledge Base" / "Article",
 * but the post-type key stays `docs`, the rewrite slug stays `/docs/`, and the
 * GraphQL names stay Document / Documents — all for back-compat with existing
 * content and the Astro theme's getDocs(). Only the labels are cosmetic.
 *
 * NOTE: do not run this alongside weDocs — both register the `docs` post type
 * and would collide. weDocs should be deactivated/removed.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	$labels = array(
		'name'               => 'Knowledge Base',
		'singular_name'      => 'Article',
		'menu_name'          => 'Knowledge Base',
		'name_admin_bar'     => 'Article',
		'add_new'            => 'Add New',
		'add_new_item'       => 'Add New Article',
		'new_item'           => 'New Article',
		'edit_item'          => 'Edit Article',
		'view_item'          => 'View Article',
		'all_items'          => 'All Articles',
		'search_items'       => 'Search Articles',
		'parent_item_colon'  => 'Parent Article:',
		'not_found'          => 'No articles found.',
		'not_found_in_trash' => 'No articles found in Trash.',
	);

	register_post_type( 'docs', array(
		'labels'              => $labels,
		'public'              => true,
		'hierarchical'        => true, // parent/child nesting for the docs tree
		'show_ui'             => true,
		'show_in_menu'        => 'soames-settings', // nest under the Soames admin menu
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
