<?php
defined( 'ABSPATH' ) || exit;

// Auto-rebuild the static front end (Netlify) when published content changes.
// The build fetches content from WordPress at build time, so any triggered build
// reflects the current WP state. Triggers are COALESCED via a single deferred
// wp-cron event so a burst of edits (or a bulk action) produces one build that
// runs AFTER the edits settle — capturing the final state rather than firing
// mid-burst and missing the last change. See ORBI-32.

const SOAMES_BUILD_EVENT = 'soames_build_site';
const SOAMES_BUILD_DELAY = 30; // seconds to wait for edits to settle before building

// ORBI-80. How late a build may fire before we say so in wp-admin. Normal worst case is
// ~90s (a 30s defer plus up to 60s of cron granularity), so this is comfortably clear of
// healthy timing and comfortably inside "the editor has given up and is refreshing the
// live site". A tighter threshold would fire on ordinary slow minutes and be dismissed
// permanently within a week, which is how a warning becomes furniture.
const SOAMES_BUILD_LATE_AFTER = 300; // 5 minutes

// Stop nagging about a late build nobody can act on any more. Only the LAST run is kept,
// so a subsequent on-time build clears the warning by itself; this caps the case where
// the next publish is a fortnight away.
const SOAMES_BUILD_LATE_STALE_AFTER = 604800; // 7 days

// Post types whose published state maps to something on the static site.
function soames_build_post_types() {
    return [ 'post', 'page', 'docs' ];
}

// Schedule (once) a deferred build. Repeated calls within the window coalesce:
// wp_next_scheduled() returns the pending event, so we don't stack duplicates.
function soames_schedule_build() {
    if ( '' === trim( (string) get_option( 'soames_build_hook_url', '' ) ) ) {
        return;
    }
    if ( ! wp_next_scheduled( SOAMES_BUILD_EVENT ) ) {
        $when = time() + SOAMES_BUILD_DELAY;
        if ( wp_schedule_single_event( $when, SOAMES_BUILD_EVENT ) ) {
            // ORBI-80: remember when this was DUE, so lateness can be measured at fire
            // time. Recorded only when we actually scheduled — a coalesced second edit
            // must not push the reference forward, or a build that fires an hour late
            // after a burst would measure as punctual.
            update_option( 'soames_build_scheduled_at', $when, false );
        }
    }
}

// Fire the build hook. Non-blocking so nothing in wp-admin waits on Netlify.
function soames_fire_build() {
    $url = trim( (string) get_option( 'soames_build_hook_url', '' ) );
    if ( '' === $url ) {
        return false;
    }
    wp_remote_post( $url, [
        'blocking' => false,
        'timeout'  => 5,
        'body'     => '{}',
        'headers'  => [ 'Content-Type' => 'application/json' ],
    ] );
    return true;
}

// ORBI-80. The cron callback — a thin wrapper that measures before delegating.
//
// WHY THIS IS SEPARATE FROM soames_fire_build(): "Deploy now" calls that function too,
// and it fires IMMEDIATELY, bypassing cron entirely. If the measurement lived inside
// soames_fire_build(), a manual deploy would (a) invent a huge bogus lateness by
// comparing against whatever was last scheduled, and (b) clear a genuinely pending
// scheduled_at — masking exactly the fault this instrument exists to catch. "Deploy now"
// already masks this class of fault for the user (ORBI-77); it must not mask it for the
// detector as well. Only the cron path measures.
function soames_run_scheduled_build() {
    $scheduled = (int) get_option( 'soames_build_scheduled_at', 0 );
    $fired     = time();

    // Measured HERE, at fire time, not read time. WordPress spawns wp-cron from incoming
    // requests, so on a headless install the admin page load that would display a warning
    // is itself the traffic that clears the condition — an overdue-right-now check reports
    // green precisely when somebody is there to read it. A lateness recorded at the moment
    // of firing already happened, and reading it later cannot change it.
    update_option( 'soames_build_last_run', [
        'fired_at'     => $fired,
        'scheduled_at' => $scheduled,
        'lateness'     => $scheduled ? max( 0, $fired - $scheduled ) : null,
    ], false );

    delete_option( 'soames_build_scheduled_at' );

    soames_fire_build();
}
add_action( SOAMES_BUILD_EVENT, 'soames_run_scheduled_build' );

// Trigger on any status change that affects the live site: something becomes
// published, an already-published post is updated, or one leaves published
// (unpublish/trash). Skip autosaves/revisions and irrelevant post types.
function soames_build_on_transition( $new_status, $old_status, $post ) {
    if ( ! in_array( $post->post_type, soames_build_post_types(), true ) ) {
        return;
    }
    if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
        return;
    }
    $affects_live = ( 'publish' === $new_status )
        || ( 'publish' === $old_status && 'publish' !== $new_status );
    if ( $affects_live ) {
        soames_schedule_build();
    }
}
add_action( 'transition_post_status', 'soames_build_on_transition', 10, 3 );

// Permanent deletion of a published item (bypassing the trash → transition path).
function soames_build_on_delete( $post_id, $post = null ) {
    if ( ! $post instanceof WP_Post ) {
        return;
    }
    if ( ! in_array( $post->post_type, soames_build_post_types(), true ) ) {
        return;
    }
    if ( 'publish' === $post->post_status ) {
        soames_schedule_build();
    }
}
add_action( 'after_delete_post', 'soames_build_on_delete', 10, 2 );

// ── Is the build actually firing on time? (ORBI-80) ───────────────────────────
//
// WHAT THIS IS NOT: a general "is wp-cron healthy" check. WordPress core already has
// one — WP_Site_Health::has_missed_cron() reports any event overdue by more than five
// minutes, and has_late_cron() anything overdue at all. Core's version is better than a
// reimplementation would be: it has two tiers and it relaxes to 15/60 minutes when
// DISABLE_WP_CRON is set. Duplicating it would put a second warning beside it.
//
// WHAT CORE CANNOT DO, and why this exists:
//   1. Core only ever inspects PENDING events, at read time, and keeps no memory. Once
//      an event fires, core has no idea it was ever late. It can never say "your last
//      build fired twelve minutes late" — and that is the measurement immune to the
//      observer effect described on soames_run_scheduled_build().
//   2. Core is not specific to the build. "A scheduled event has failed" may be
//      wp_version_check and have nothing to do with publishing.
//   3. Core is buried in Tools → Site Health, which is not where the editor who just
//      published is standing.
//
// So: report our own recorded lateness, prominently, and point at Site Health for
// WordPress's own view rather than restating it.

/**
 * Why the build hook looks unhealthy on this site, or '' when it looks fine.
 */
function soames_build_health_problem() {
    // Sites with no build hook have nothing to be late. On this network that is two of
    // the four subsites, and they deliberately have no cron entry either (ORBI-77
    // decision A) — warning there would be noise about a feature they do not use.
    if ( '' === trim( (string) get_option( 'soames_build_hook_url', '' ) ) ) {
        return '';
    }

    $now = time();

    // (a) A build is due and has not fired. This is the one pending-state case kept,
    // because a build that NEVER fires never records anything — fire-time measurement
    // structurally cannot catch a completely dead cron. Scoped to our own event and
    // compared against our own recorded timestamp, not the live cron array.
    $scheduled = (int) get_option( 'soames_build_scheduled_at', 0 );
    if ( $scheduled && ( $now - $scheduled ) > SOAMES_BUILD_LATE_AFTER ) {
        return sprintf(
            /* translators: %s: human-readable time difference, e.g. "12 mins". */
            __( 'A site build has been waiting %s and has not run.', 'soames' ),
            human_time_diff( $scheduled, $now )
        );
    }

    // (b) The last build fired, but late. Only the last run is kept, so an on-time build
    // clears this by itself.
    $last = get_option( 'soames_build_last_run', [] );
    if ( ! is_array( $last ) || empty( $last['lateness'] ) ) {
        return '';
    }
    $stale = ( $now - (int) ( $last['fired_at'] ?? 0 ) ) > SOAMES_BUILD_LATE_STALE_AFTER;
    if ( ! $stale && (int) $last['lateness'] > SOAMES_BUILD_LATE_AFTER ) {
        return sprintf(
            /* translators: %s: human-readable time difference, e.g. "12 mins". */
            __( 'The last site build ran %s later than scheduled.', 'soames' ),
            human_time_diff( 0, (int) $last['lateness'] )
        );
    }

    return '';
}

function soames_build_health_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $problem = soames_build_health_problem();
    if ( '' === $problem ) {
        return;
    }

    printf(
        '<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p><p>%s</p></div>',
        esc_html__( 'Soames:', 'soames' ),
        esc_html( $problem ),
        wp_kses_post( sprintf(
            /* translators: %s: link to the Site Health screen. */
            __( 'Publishing relies on WP-Cron running on schedule, and this site gets almost no traffic of its own. Check that the server cron entry is still installed — the recipe is in the plugin\'s README under "The server-side half". WordPress\'s own view is at %s.', 'soames' ),
            '<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Tools &rsaquo; Site Health', 'soames' ) . '</a>'
        ) )
    );
}
add_action( 'admin_notices', 'soames_build_health_notice' );

// ── Manual "Deploy now" (admin-post action, fires immediately) ─────────────────

function soames_handle_deploy_now() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.' );
    }
    check_admin_referer( 'soames_deploy_now' );

    $status = soames_fire_build() ? 'triggered' : 'nohook';

    wp_safe_redirect( add_query_arg(
        'soames_deploy',
        $status,
        wp_get_referer() ?: admin_url( 'admin.php?page=soames-settings' )
    ) );
    exit;
}
add_action( 'admin_post_soames_deploy_now', 'soames_handle_deploy_now' );

function soames_deploy_now_notice() {
    if ( ! isset( $_GET['soames_deploy'] ) ) {
        return;
    }
    $status = sanitize_text_field( wp_unslash( $_GET['soames_deploy'] ) );
    if ( 'triggered' === $status ) {
        echo '<div class="notice notice-success is-dismissible"><p>Netlify build triggered — the site will update in about a minute.</p></div>';
    } elseif ( 'nohook' === $status ) {
        echo '<div class="notice notice-warning is-dismissible"><p>No Netlify build hook URL is set. Add one in <strong>Soames &rsaquo; Settings</strong> first.</p></div>';
    }
}
add_action( 'admin_notices', 'soames_deploy_now_notice' );
