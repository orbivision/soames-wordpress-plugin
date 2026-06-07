<?php
defined( 'ABSPATH' ) || exit;

// REST endpoint consumed by gatsby-node.js sourceNodes at build time.
// gatsby-source-wordpress does not surface custom WPGraphQL fields on
// GeneralSettings, so we expose Soames settings via a dedicated REST route.
// Gatsby's sourceNodes fetches this and creates a SoamesSettings node.

add_action( 'rest_api_init', function () {
    register_rest_route( 'soames/v1', '/settings', [
        'methods'             => 'GET',
        'callback'            => 'soames_plugin_rest_settings',
        'permission_callback' => '__return_true',
    ] );
} );

function soames_plugin_rest_settings() {
    $logo_id    = (int) get_option( 'soames_logo_id' );
    $favicon_id = (int) get_option( 'soames_favicon_id' );
    $blurb      = get_option( 'soames_contact_blurb', '' );

    return [
        'logoUrl'      => $logo_id    ? wp_get_attachment_url( $logo_id )                                    : null,
        'logoAlt'      => $logo_id    ? (string) get_post_meta( $logo_id, '_wp_attachment_image_alt', true )  : null,
        'faviconUrl'   => $favicon_id ? wp_get_attachment_url( $favicon_id )                                  : null,
        'contactBlurb' => $blurb !== '' ? $blurb                                                              : null,
    ];
}
