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
		// Soames Settings → Optima Express page: hero image/caption/overlay for landing types.
		'heroPageId'   => (int) get_option( 'soames_idx_page_id' ) ?: null,
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

// ── Activation reports the front end's origin (interim; ORBI-82) ─────────────────────────────
//
// Kestrel refuses to render unless the page URL starts with the account's stored base URL, and
// for WordPress accounts that base is derived from the ajax URL Optima Express sends when it
// activates: admin_url('admin-ajax.php') — whose HOST comes from siteurl. On a headless site
// home (the public front end) and siteurl (WordPress) are different hosts, so the stored base is
// a host visitors never load and every IDX page on the front end fails the check. Taking that
// host from home is a known open issue in Optima Express itself; until it ships, this reports the
// ajax URL on home's origin instead.
//
// Deliberately narrow:
//   - only for 'admin-ajax.php', only when home and siteurl hosts differ, and only when the call
//     comes from Optima Express's getAjaxBaseUrl() — in 8.7.7 its single caller is the activation
//     request. wp-admin's own ajaxurl and every other admin_url() are untouched: moving those to
//     the front end would send admin AJAX cross-origin without WordPress's auth cookies.
//   - runs last (PHP_INT_MAX) so a domain-mapping admin_url filter can't undo it.
//   - fails safe: if Optima Express renames that method, this stops matching and activation
//     reports what it always did. Once Optima Express takes the host from home, the rewrite
//     produces the value it already has, so it becomes a no-op — remove it then.
//   - the reported endpoint should really exist: the static site proxies /wp-admin/admin-ajax.php
//     to WordPress (see the darst.app _redirects for the pattern).
//
// Takes effect at the next activation: re-save Optima Express's activation page.

// PHP_INT_MAX: runs after everything else on admin_url. A domain-mapping plugin (e.g. WordPress
// MU Domain Mapping's domain_mapping_adminurl, also at 10 and registered later) rewrites every
// admin_url() back to the admin domain, which undid this at the default priority on a live
// multisite. The scope is narrow enough that having the last word here is safe.
add_filter( 'admin_url', 'soames_oe_activation_ajax_url', PHP_INT_MAX, 2 );

function soames_oe_activation_ajax_url( $url, $path ) {
	if ( $path !== 'admin-ajax.php' || ! class_exists( 'iHomefinderUrlFactory' ) ) {
		return $url;
	}
	$home = wp_parse_url( home_url() );
	$site = wp_parse_url( site_url() );
	if ( empty( $home['host'] ) || empty( $site['host'] ) ) {
		return $url;
	}
	$home_host = strtolower( $home['host'] ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	$site_host = strtolower( $site['host'] ) . ( isset( $site['port'] ) ? ':' . $site['port'] : '' );
	if ( $home_host === $site_host || ! soames_oe_called_from_ajax_base_url() ) {
		return $url;
	}
	// home's full origin — scheme AND host — since that is what visitors load and what Kestrel
	// compares the page URL against.
	$u = wp_parse_url( $url );
	return ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . $home_host
		. ( isset( $u['path'] ) ? $u['path'] : '/wp-admin/admin-ajax.php' )
		. ( isset( $u['query'] ) ? '?' . $u['query'] : '' );
}

/** True when admin_url() was called by iHomefinderUrlFactory::getAjaxBaseUrl(). */
function soames_oe_called_from_ajax_base_url() {
	// admin_url → get_admin_url → apply_filters → this filter; the caller sits a few frames up.
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 ) as $frame ) {
		if ( isset( $frame['class'], $frame['function'] )
			&& $frame['class'] === 'iHomefinderUrlFactory'
			&& $frame['function'] === 'getAjaxBaseUrl' ) {
			return true;
		}
	}
	return false;
}

// ── Listing sitemap (ORBI-82 Phase 4) ─────────────────────────────────────────
//
// Optima Express has no sitemap of its own: iHomefinderAdmin::getSitemap() (private) asks the
// remote service for the account's listing URLs and hands them only to Google XML Sitemaps or
// Yoast. A static front end has neither, so this exposes the same list. It's the same request,
// made through Optima Express's own public requestor class, so its server-side credentials never
// leave WordPress, and with the same 1-hour cache. The response carries listing URLs and nothing
// else; they're public pages.

add_action( 'rest_api_init', function () {
	register_rest_route( 'soames/v1', '/optima-express/sitemap', [
		'methods'             => 'GET',
		'callback'            => 'soames_oe_rest_sitemap',
		'permission_callback' => '__return_true',
	] );
} );

/**
 * The remote sitemap response → [ [ 'loc' => string, 'lastmod' => string|null ], … ].
 *
 * The requestor decodes XML with SimpleXML and anything else with json_decode, so `url` may be
 * a SimpleXMLElement (iterating it yields each <url>), an array, or — JSON with one entry — a
 * single object. Returns null when the response isn't a sitemap at all; an empty urlset is [].
 */
function soames_oe_sitemap_normalize( $response ) {
	if ( ! is_object( $response ) || ! isset( $response->sitemap ) || ! isset( $response->sitemap->urlset ) ) {
		return null;
	}
	$urls = isset( $response->sitemap->urlset->url ) ? $response->sitemap->urlset->url : [];
	$list = ( is_array( $urls ) || $urls instanceof Traversable ) ? $urls : [ $urls ];
	$out  = [];
	foreach ( $list as $url ) {
		$loc = trim( (string) ( $url->loc ?? '' ) );
		if ( $loc === '' ) {
			continue;
		}
		$lastmod = trim( (string) ( $url->lastmod ?? '' ) );
		$out[]   = [ 'loc' => $loc, 'lastmod' => $lastmod !== '' ? $lastmod : null ];
	}
	return $out;
}

function soames_oe_rest_sitemap() {
	// Same gate as the settings payload: a subsite without a registered Optima Express has no
	// listings to list, and shouldn't make the remote request at all.
	if ( ! soames_oe_enabled() ) {
		return new WP_Error( 'soames_oe_not_enabled', 'Optima Express is not active and registered on this site.', [ 'status' => 404 ] );
	}
	$request = new iHomefinderRequestor();
	$request->addParameter( 'requestType', 'sitemap' )->setCacheExpiration( HOUR_IN_SECONDS );
	$remote = $request->remoteGetRequest();
	$urls   = soames_oe_sitemap_normalize( is_object( $remote ) ? $remote->getResponse() : null );
	if ( $urls === null ) {
		return new WP_Error( 'soames_oe_sitemap_unavailable', 'Optima Express returned no sitemap.', [ 'status' => 502 ] );
	}
	return [ 'count' => count( $urls ), 'urls' => $urls ];
}
