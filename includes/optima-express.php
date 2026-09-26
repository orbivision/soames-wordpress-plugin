<?php
defined( 'ABSPATH' ) || exit;

// Optima Express (IDX) support for static Soames sites.
//
// A static site has no server at request time, so Optima Express's virtual pages can't be
// rendered by WordPress for its visitors. What the theme needs at BUILD time is:
//   - the Kestrel config the plugin would print in every page head (the activationToken is
//     public by design — Optima Express emits it in every page it renders), and
//   - the virtual-page URL patterns, so the build can emit one static shell per page type
//     plus a rewrite from each pattern to its shell.
//
// Both ride on the existing soames/v1/settings payload as `optimaExpress` — null unless
// Optima Express is active AND registered on this site. It's network-activated on a
// multisite, so "active" alone is true on every subsite and gates nothing.
//
// Only two Optima Express methods are called, both public: isActivated() and isKestrelAll().
// The URL patterns come from WordPress's own rewrite table, not from Optima Express classes,
// so they survive refactors of that plugin and already reflect an admin's customised slugs.

/**
 * Optima Express is active and registered on the current site.
 *
 * isActivated() is true iff this site has its own ihf_authentication_token, which is written
 * only when the site is registered with an activation key. Evaluated per request, never
 * cached: a token can be removed on deregistration and the gate has to follow.
 */
function soames_oe_enabled() {
	return class_exists( 'iHomefinderAdmin' ) && iHomefinderAdmin::getInstance()->isActivated();
}

/**
 * Optima Express render mode. Soames supports 'kestrel' (Kestrel for every page) only: the
 * legacy mode fetches each page body server-side at request time, which a static site can't do.
 */
function soames_oe_mode() {
	if ( ! class_exists( 'iHomefinderDisplayRules' ) ) {
		return 'unknown';
	}
	$rules = iHomefinderDisplayRules::getInstance();
	if ( $rules->isKestrelAll() ) {
		return 'kestrel';
	}
	return $rules->isKestrelDetail() ? 'kestrel-detail' : 'legacy';
}

/**
 * Translate the virtual-page rewrite rules into path patterns the static host can route.
 *
 * Optima Express registers each rule as ^base/([^/]+)/…$ → index.php?…&ihf-type=<type>&<var>=$matches[n].
 * Only that segment grammar is translated; anything else is counted in `skipped` so a future
 * rule shape shows up as a number rather than a silent gap.
 *
 * @return array{routes: array<int, array{type: string, path: string}>, skipped: int}
 */
function soames_oe_routes() {
	global $wp_rewrite;
	$routes  = [];
	$skipped = 0;

	foreach ( (array) $wp_rewrite->wp_rewrite_rules() as $regex => $target ) {
		if ( strpos( $target, 'ihf-type=' ) === false ) {
			continue;
		}
		// The non-pretty-permalink variants (index.php/…) are unreachable on a static host.
		if ( strpos( $regex, 'index.php/' ) !== false ) {
			continue;
		}
		$route = soames_oe_translate_rule( $regex, $target );
		if ( $route === null ) {
			$skipped++;
			continue;
		}
		$routes[] = $route;
	}

	return [ 'routes' => $routes, 'skipped' => $skipped ];
}

/**
 * One rewrite rule → { type, path }, or null when it isn't the simple segment grammar.
 * Public for the contract tests.
 */
function soames_oe_translate_rule( $regex, $target ) {
	$query = [];
	parse_str( (string) wp_parse_url( $target, PHP_URL_QUERY ), $query );
	$type = isset( $query['ihf-type'] ) ? (string) $query['ihf-type'] : '';
	if ( $type === '' ) {
		return null;
	}

	// $matches[n] → the query var it fills.
	$vars = [];
	foreach ( $query as $name => $value ) {
		if ( is_string( $value ) && preg_match( '/^\$matches\[(\d+)\]$/', $value, $m ) ) {
			$vars[ (int) $m[1] ] = $name;
		}
	}

	// The capture group itself contains a "/", so swap it for a placeholder before splitting
	// on "/" — exploding the raw pattern cuts ([^/]+) in half.
	$pattern = str_replace( '([^/]+)', "\0", preg_replace( '/^\^|\$$/', '', $regex ) );
	$capture = 0;
	$parts   = [];
	foreach ( explode( '/', $pattern ) as $segment ) {
		if ( $segment === "\0" ) {
			$capture++;
			if ( ! isset( $vars[ $capture ] ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $vars[ $capture ] ) ) {
				return null;
			}
			$parts[] = ':' . $vars[ $capture ];
		} elseif ( preg_match( '/^[A-Za-z0-9_-]+$/', $segment ) ) {
			$parts[] = $segment;
		} else {
			return null;
		}
	}
	if ( ! $parts ) {
		return null;
	}

	return [ 'type' => $type, 'path' => '/' . implode( '/', $parts ) ];
}

/**
 * The `optimaExpress` key of soames/v1/settings: null unless active and registered.
 */
function soames_oe_settings_payload() {
	if ( ! soames_oe_enabled() ) {
		return null;
	}
	$routes = soames_oe_routes();
	return [
		'mode'         => soames_oe_mode(),
		'kestrel'      => [
			'activationToken' => (string) iHomefinderAdmin::getInstance()->getActivationToken(),
			'platform'        => 'wordpress',
		],
		'routes'       => $routes['routes'],
		'skippedRules' => $routes['skipped'],
	];
}

// Registered but not in Kestrel mode: the static site would build no IDX pages. Say so where
// the admin can act on it, rather than let the build succeed with nothing.
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || ! soames_oe_enabled() ) {
		return;
	}
	if ( soames_oe_mode() === 'kestrel' ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>'
		. esc_html__( 'Soames: Optima Express is registered on this site but is not in Kestrel mode, so the static site will not build any IDX pages. Soames supports Kestrel mode only.', 'soames' )
		. '</p></div>';
} );
