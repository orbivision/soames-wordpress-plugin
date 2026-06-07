<?php
defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'soames_preview_redirect' );

function soames_preview_redirect() {
    if ( ! isset( $_GET['preview'] ) || $_GET['preview'] !== 'true' ) return;

    $frontend_url = get_option( 'soames_frontend_url' );
    if ( ! $frontend_url ) return;

    $post_id = isset( $_GET['p'] )       ? intval( $_GET['p'] )       : 0;
    $page_id = isset( $_GET['page_id'] ) ? intval( $_GET['page_id'] ) : 0;
    $id      = $post_id ?: $page_id;
    $type    = $page_id ? 'page' : 'post';

    if ( ! $id ) return;

    $token = bin2hex( random_bytes( 16 ) );
    set_transient( 'soames_preview_' . $token, compact( 'id', 'type' ), 5 * MINUTE_IN_SECONDS );

    wp_redirect( rtrim( $frontend_url, '/' ) . '/preview/?token=' . $token, 302 );
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'soames/v1', '/preview', [
        'methods'             => 'GET',
        'callback'            => 'soames_rest_preview',
        'permission_callback' => '__return_true',
    ] );
} );

function soames_rest_preview( WP_REST_Request $request ) {
    $token = sanitize_text_field( $request->get_param( 'token' ) );

    if ( ! $token ) {
        return new WP_Error( 'no_token', 'Preview token required.', [ 'status' => 400 ] );
    }

    $data = get_transient( 'soames_preview_' . $token );
    if ( ! $data ) {
        return new WP_Error( 'expired', 'Preview token expired or invalid. Click Preview again in WordPress admin.', [ 'status' => 403 ] );
    }

    $post = get_post( $data['id'] );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] );
    }

    $featured_image = null;
    $thumbnail_id   = get_post_thumbnail_id( $post->ID );
    if ( $thumbnail_id ) {
        $src            = wp_get_attachment_image_src( $thumbnail_id, 'full' );
        $featured_image = [
            'sourceUrl' => $src ? $src[0] : null,
            'altText'   => (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
        ];
    }

    $blog_hero = null;
    if ( $data['type'] === 'post' ) {
        $blog_page_id = get_option( 'page_for_posts' );
        if ( $blog_page_id ) {
            $blog_page    = get_post( $blog_page_id );
            $bp_thumb_id  = get_post_thumbnail_id( $blog_page_id );
            $bp_thumb_src = $bp_thumb_id ? wp_get_attachment_image_src( $bp_thumb_id, 'full' ) : null;
            $blog_hero    = [
                'title'          => $blog_page ? get_the_title( $blog_page ) : null,
                'excerpt'        => $blog_page ? $blog_page->post_excerpt : null,
                'guid'           => $bp_thumb_src ? $bp_thumb_src[0] : null,
                'overlayOpacity' => get_post_meta( $blog_page_id, 'soames_overlay_opacity', true ) ?: '0.6',
            ];
        }
    }

    return [
        'type'           => $data['type'],
        'title'          => get_the_title( $post ),
        'content'        => apply_filters( 'the_content', $post->post_content ),
        'excerpt'        => apply_filters( 'the_excerpt', $post->post_excerpt ),
        'date'           => get_the_date( 'F d, Y', $post ),
        'overlayOpacity' => get_post_meta( $post->ID, 'soames_overlay_opacity', true ) ?: '0.6',
        'featuredImage'  => $featured_image,
        'blogHero'       => $blog_hero,
    ];
}
