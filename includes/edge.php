<?php
/**
 * Edge bypass (ORBI-82).
 *
 * A static front end's edge function fetches WordPress pages server-side — today, the head of an
 * Optima Express listing, which only WordPress can build. Once a Frontend Site URL is set, those
 * same pages redirect to the front end (the point: the WordPress host stops being a crawlable
 * duplicate), so the edge would be sent straight back to itself.
 *
 * A request carrying `X-Soames-Edge: <secret>` skips the Soames front-end redirect and WordPress's
 * own redirect_canonical, and gets the page. Nothing else changes:
 *   - no secret configured → the header is ignored entirely;
 *   - a wrong secret → treated exactly like no header;
 *   - the secret never appears in any REST or GraphQL payload. The front end gets it from its
 *     own environment (Netlify: SOAMES_EDGE_SECRET), not from WordPress.
 *
 * A bypassed response is sent no-cache and X-Robots-Tag: noindex. The edge only lifts allowlisted
 * head tags out of it, so neither header reaches a visitor — but if a page cache or a crawler
 * ever gets hold of one, it neither persists nor indexes.
 */

defined( 'ABSPATH' ) || exit;

/** Shortest secret accepted. Long enough that guessing it is not a way in. */
const SOAMES_EDGE_SECRET_MIN_LENGTH = 32;

/**
 * Whether this request is the front end's edge, authenticated by the shared secret.
 *
 * Memoised: it's asked from several hooks per request and the answer can't change mid-request.
 */
function soames_is_edge_request() {
	static $is_edge = null;
	if ( null !== $is_edge ) {
		return $is_edge;
	}
	$secret = (string) get_option( 'soames_edge_secret', '' );
	$sent   = isset( $_SERVER['HTTP_X_SOAMES_EDGE'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_SOAMES_EDGE'] ) : '';
	// Constant-time compare; an unset secret never matches, even an empty header.
	$is_edge = $secret !== '' && $sent !== '' && hash_equals( $secret, $sent );
	return $is_edge;
}

/**
 * Settings sanitizer: blank (bypass off) or a token of at least the minimum length with no
 * whitespace — it travels in an HTTP header and is pasted between two admin UIs, where stray
 * spaces are the usual failure.
 */
function soames_sanitize_edge_secret( $value ) {
	$value = trim( (string) $value );
	if ( $value === '' ) {
		return '';
	}
	if ( strlen( $value ) < SOAMES_EDGE_SECRET_MIN_LENGTH || preg_match( '/[^\x21-\x7E]/', $value ) ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'soames_edge_secret',
				'invalid_edge_secret',
				sprintf( 'The edge secret must be at least %d characters, with no spaces. It was not changed.', SOAMES_EDGE_SECRET_MIN_LENGTH ),
				'error'
			);
		}
		return (string) get_option( 'soames_edge_secret', '' );
	}
	return $value;
}

// WordPress's canonical redirect would otherwise bounce the edge to the canonical URL — on a site
// whose Home is the front end, that's the front end again.
add_filter( 'redirect_canonical', function ( $redirect_url ) {
	return soames_is_edge_request() ? false : $redirect_url;
}, PHP_INT_MAX );

add_action( 'send_headers', function () {
	if ( ! soames_is_edge_request() ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex' );
} );
