<?php
/**
 * Front-end redirection (ORBI-58).
 *
 * Sends visitors who land on this WordPress install over to the published Soames site.
 * This used to live in the companion theme's index.php; it moved here so Soames is one
 * artifact instead of two, the way Faust.js does it.
 *
 * MOVING IT HERE REMOVED A NATURAL BOUNDARY. As a theme, the redirect only ran when
 * WordPress got as far as rendering a template with that theme active. From a plugin it
 * runs on every front-end request on every install, so the bail-outs below are the whole
 * safety story and are covered by tests in tests/e2e/redirect.spec.ts. Anything that must
 * keep working — GraphQL above all, since the site build depends on it — has to be
 * excluded explicitly and asserted.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether front-end redirection is switched on.
 *
 * Default ON so upgrading from the companion theme changes nothing. An unset frontend URL
 * already means "do nothing", so the setting only matters to someone who has a URL set and
 * wants raw WordPress back for a while.
 */
function soames_frontend_redirect_enabled() {
	return get_option( 'soames_frontend_redirect', '1' ) !== '0';
}

/**
 * Slug the front end serves the blog under.
 *
 * Mirrors soames-astro-theme's integration.ts, WHICH IS THE POINT: that resolves the base
 * from WordPress's "Posts page" setting and falls back to 'blog'. The old theme hard-coded
 * '/blog', so on any install whose posts page was slugged something else — news, articles —
 * WordPress redirected posts to a URL the front end never generates. Both sides read the
 * same setting now, including the same fallback.
 */
function soames_posts_base_slug() {
	$page_for_posts = (int) get_option( 'page_for_posts' );
	if ( $page_for_posts ) {
		$slug = get_post_field( 'post_name', $page_for_posts );
		if ( $slug ) {
			return $slug;
		}
	}
	return 'blog';
}

/**
 * Where a given request should send the visitor, or '' to leave it alone.
 *
 * Split out from the hook so it is directly testable and so the mapping reads in one place.
 */
function soames_frontend_redirect_target() {
	$frontend = get_option( 'soames_frontend_url' );
	if ( ! $frontend ) {
		return '';
	}
	$base = rtrim( $frontend, '/' );

	// Single post → the blog base the front end actually uses.
	if ( is_singular( 'post' ) ) {
		$path = (string) wp_parse_url( get_permalink(), PHP_URL_PATH );
		return $base . '/' . soames_posts_base_slug() . $path;
	}

	// Any other single item — page, docs article, future CPT — keeps its path, because the
	// front end routes those by WordPress's own `uri`. The theme only handled is_page() and
	// sent everything else to the site root, so a /docs/<slug> article silently lost its
	// path and landed on the home page.
	if ( is_singular() ) {
		$path = (string) wp_parse_url( get_permalink(), PHP_URL_PATH );
		if ( $path ) {
			return $base . $path;
		}
	}

	// Archives, search, 404, the front page itself: nothing on the front end corresponds
	// one-to-one, so send them to its root.
	return $base . '/';
}

/**
 * Requests that must never be redirected.
 *
 * @return bool True to leave the request alone.
 */
function soames_frontend_redirect_should_skip() {
	// Not a front-end page view at all.
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return true;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return true;
	}
	if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
		return true;
	}

	// THE IMPORTANT ONE. WPGraphQL resolves its endpoint early, so this may never fire on a
	// /graphql request — but "may" is not good enough. A Soames install whose GraphQL
	// endpoint redirects is a total outage of the build pipeline, and it would present as a
	// broken site rather than a broken WordPress.
	if ( function_exists( 'is_graphql_http_request' ) && is_graphql_http_request() ) {
		return true;
	}

	// Previews belong to soames_preview_redirect() in preview.php, which runs on this same
	// hook at the default priority. This runs at 20 so that wins on ordering; the explicit
	// check means it still wins if either priority ever changes.
	if ( is_preview() || isset( $_GET['preview'] ) ) {
		return true;
	}

	// Machine-readable endpoints WordPress serves through the template layer.
	if ( is_robots() || is_feed() || is_trackback() ) {
		return true;
	}
	if ( get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) ) {
		return true;
	}
	if ( is_embed() ) {
		return true;
	}

	return false;
}

add_action( 'template_redirect', 'soames_frontend_redirect', 20 );

function soames_frontend_redirect() {
	if ( ! soames_frontend_redirect_enabled() ) {
		return;
	}
	if ( soames_frontend_redirect_should_skip() ) {
		return;
	}

	$target = soames_frontend_redirect_target();

	/**
	 * Last word on whether to redirect this request.
	 *
	 * The escape hatch for cases the bail-outs above don't know about — a verification file
	 * served through WordPress, a callback URL, a plugin with its own front-end route.
	 * Return '' (or a falsy value) to leave the request alone.
	 *
	 * @param string $target Absolute URL to redirect to, or '' for none.
	 */
	$target = apply_filters( 'soames_frontend_redirect_target', $target );

	if ( ! $target ) {
		return;
	}

	// 302 throughout, including the catch-all the theme sent as a 301. The destination is a
	// user-configurable setting: a 301 is cached hard by browsers and CDNs, so changing the
	// frontend URL later would strand everyone who ever hit the old one.
	wp_redirect( $target, 302 );
	exit;
}
