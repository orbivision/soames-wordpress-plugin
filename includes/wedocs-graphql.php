<?php
/**
 * Optional WPGraphQL support for the weDocs documentation plugin.
 *
 * weDocs (https://wordpress.org/plugins/wedocs/) registers a hierarchical
 * `docs` custom post type but does not expose it to WPGraphQL. Soames reads
 * everything over WPGraphQL, so this opts the `docs` post type into the schema
 * as `Document` / `documents`. Because `docs` is hierarchical and supports
 * `page-attributes`, WPGraphQL automatically exposes `parent`, child relations,
 * and `menuOrder` — which the Soames docs sidebar uses to build its nav tree.
 *
 * weDocs is OPTIONAL. This callback only mutates the args for the `docs` post
 * type, and `docs` only exists while weDocs is active. If weDocs is not
 * installed the callback never matches and is a silent no-op: it registers
 * nothing, declares no dependency on weDocs, and leaves the rest of the Soames
 * plugin untouched. `register_post_type_args` is a WordPress core filter, so
 * this is also harmless when WPGraphQL itself is absent — the extra args are
 * simply ignored.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'register_post_type_args',
	function ( $args, $post_type ) {
		// Only touch weDocs' `docs` CPT. No-op for every other post type, and
		// never runs at all when weDocs isn't installed (it owns this CPT).
		if ( 'docs' !== $post_type ) {
			return $args;
		}

		$args['show_in_graphql']     = true;
		$args['graphql_single_name'] = 'Document';
		$args['graphql_plural_name'] = 'Documents';

		return $args;
	},
	10,
	2
);
