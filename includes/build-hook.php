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
        wp_schedule_single_event( time() + SOAMES_BUILD_DELAY, SOAMES_BUILD_EVENT );
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
add_action( SOAMES_BUILD_EVENT, 'soames_fire_build' );

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
