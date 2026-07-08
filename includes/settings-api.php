<?php
defined( 'ABSPATH' ) || exit;

// REST endpoint the Soames Astro theme fetches at build time via
// getSoamesSettings() (src/lib/wp.ts → /wp-json/soames/v1/settings).
// WPGraphQL does not surface these custom Soames settings fields on
// GeneralSettings, so we expose them through a dedicated REST route.

add_action( 'rest_api_init', function () {
    register_rest_route( 'soames/v1', '/settings', [
        'methods'             => 'GET',
        'callback'            => 'soames_plugin_rest_settings',
        'permission_callback' => '__return_true',
    ] );
} );

function soames_plugin_rest_settings() {
    $logo_id      = (int) get_option( 'soames_logo_id' );
    $favicon_id   = (int) get_option( 'soames_favicon_id' );
    $blurb        = get_option( 'soames_contact_blurb', '' );
    $company_name = get_option( 'soames_company_name', '' );
    $docs_page_id = (int) get_option( 'soames_docs_page_id' );

    return [
        'logoUrl'         => $logo_id    ? wp_get_attachment_url( $logo_id )                                    : null,
        'logoAlt'         => $logo_id    ? (string) get_post_meta( $logo_id, '_wp_attachment_image_alt', true )  : null,
        'faviconUrl'      => $favicon_id ? wp_get_attachment_url( $favicon_id )                                  : null,
        'contactBlurb'    => $blurb !== '' ? $blurb                                                              : null,
        'companyName'     => $company_name !== '' ? $company_name                                                : null,
        'showCompanyName' => (bool) get_option( 'soames_show_company_name', 1 ),
        // The page chosen in Soames Settings → Knowledge Base page; drives the
        // /docs/ landing hero. null when unset (theme falls back to defaults).
        'docsPageId'      => $docs_page_id ?: null,
    ];
}
