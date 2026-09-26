<?php
defined( 'ABSPATH' ) || exit;

// Keep the REST API on the WordPress host when the public site lives elsewhere (ORBI-82).
//
// A headless site sets WordPress's "Site Address (URL)" (home) to the static front end and
// leaves "WordPress Address (URL)" (siteurl) on the WordPress host. That split is what makes
// Optima Express register the front end as the site's address (it identifies the site by
// home_url()). But WordPress builds rest_url() from home_url() too, so the block editor's
// wpApiSettings.root points at the front end — which has no /wp-json — and every editor
// request fails: the editor draws from preloaded data, then cannot load or save anything.
//
// Acts only when the HOSTS differ. A scheme-only mismatch (siteurl http://, home https:// on the
// same host — a common leftover on multisite installs) is left exactly as WordPress built it;
// rewriting it would push the REST root to http and give the editor mixed content, which is
// its own way of breaking the editor.

add_filter( 'rest_url', 'soames_rest_url_on_wordpress_host', 10, 4 );

/**
 * @param string $url     REST URL as WordPress built it (from home_url()).
 * @param string $path    REST route.
 * @param int    $blog_id Blog ID, or null for the current site.
 * @param string $scheme  Scheme argument.
 */
function soames_rest_url_on_wordpress_host( $url, $path = '', $blog_id = null, $scheme = 'rest' ) {
	$home = soames_url_origin( get_home_url( $blog_id ), true );
	$site = soames_url_origin( get_site_url( $blog_id ), true );
	if ( $home === null || $site === null || $home['host'] === $site['host'] ) {
		return $url;
	}

	// Only URLs built on home — anything else (another site's filter output) is left alone.
	$rest = soames_url_origin( $url, false );
	if ( $rest === null ) {
		return $url;
	}
	$prefix_ok = $home['path'] === '' || $rest['path'] === $home['path'] || strpos( $rest['path'], $home['path'] . '/' ) === 0;
	if ( $rest['host'] !== $home['host'] || ! $prefix_ok ) {
		return $url;
	}

	$tail = substr( $rest['path'], strlen( $home['path'] ) ) . $rest['suffix'];
	$new  = $site['scheme'] . '://' . $site['host'] . $site['path'] . '/' . ltrim( $tail, '/' ); // $tail keeps its own trailing slash
	// An admin page served over https must not be handed an http REST root.
	return is_ssl() ? set_url_scheme( $new, 'https' ) : $new;
}

/**
 * scheme, host[:port] (lowercased), path, and query/fragment suffix. $base trims the path's
 * trailing slash — right for home/siteurl bases, wrong for the REST URL itself (/wp-json/).
 */
function soames_url_origin( $url, $base ) {
	$p = wp_parse_url( (string) $url );
	if ( empty( $p['host'] ) ) {
		return null;
	}
	return [
		'scheme' => isset( $p['scheme'] ) ? $p['scheme'] : 'http',
		'host'   => strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ),
		'path'   => isset( $p['path'] ) ? ( $base ? rtrim( $p['path'], '/' ) : $p['path'] ) : '',
		'suffix' => ( isset( $p['query'] ) ? '?' . $p['query'] : '' ) . ( isset( $p['fragment'] ) ? '#' . $p['fragment'] : '' ),
	];
}
