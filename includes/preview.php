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

    // ORBI-85: only someone who can edit the post gets a token. Without this, an anonymous
    // `?p=<draft id>&preview=true` was issued one, and the REST route below (public by design —
    // the token IS the credential) handed back the draft's full content: any draft on the
    // network, by iterating IDs. Editors click Preview from wp-admin on this same host, so
    // their login cookie is on this request; anyone else is sent to log in, then back here.
    if ( ! is_user_logged_in() ) {
        auth_redirect();
    }
    if ( ! current_user_can( 'edit_post', $id ) ) {
        wp_die( esc_html__( 'You are not allowed to preview this item.', 'soames' ), '', [ 'response' => 403 ] );
    }

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
                // ORBI-52: dedicated hero title/caption; null when unset (the theme
                // falls back to `title` for the hero title, and shows no caption).
                'heroTitle'      => get_post_meta( $blog_page_id, 'soames_hero_title', true ) ?: null,
                'heroCaption'    => get_post_meta( $blog_page_id, 'soames_hero_caption', true ) ?: null,
                'guid'           => $bp_thumb_src ? $bp_thumb_src[0] : null,
                'overlayOpacity' => get_post_meta( $blog_page_id, 'soames_overlay_opacity', true ) ?: '0.6',
                // ORBI-85: same field as GraphQL heroBackgroundImage, so the preview's hero
                // resolves exactly as the built post's does (dedicated image, then `guid`).
                'heroBackgroundImage' => soames_preview_image_url( $blog_page_id, 'soames_hero_bg_id' ),
            ];
        }
    }

    return [
        'type'           => $data['type'],
        'title'          => get_the_title( $post ),
        'content'        => apply_filters( 'the_content', $post->post_content ),
        'excerpt'        => apply_filters( 'the_excerpt', $post->post_excerpt ),
        'date'           => get_the_date( 'F d, Y', $post ),
        // ORBI-52: null when unset — same contract as the GraphQL heroTitle/heroCaption.
        'heroTitle'      => get_post_meta( $post->ID, 'soames_hero_title', true ) ?: null,
        'heroCaption'    => get_post_meta( $post->ID, 'soames_hero_caption', true ) ?: null,
        'overlayOpacity' => get_post_meta( $post->ID, 'soames_overlay_opacity', true ) ?: '0.6',
        'featuredImage'  => $featured_image,
        'blogHero'       => $blog_hero,
        // ORBI-85: the two dedicated images the built pages use — the hero backdrop (any
        // type) and the post sidebar image (posts only). Null when unset, as in GraphQL.
        'heroBackgroundImage' => soames_preview_image_url( $post->ID, 'soames_hero_bg_id' ),
        'blogImage'           => $data['type'] === 'post' ? soames_preview_image_url( $post->ID, 'soames_blog_image_id' ) : null,
    ];
}

/** Full-size URL of the attachment whose ID is stored in `$meta_key`, or null. */
function soames_preview_image_url( $post_id, $meta_key ) {
    $id = (int) get_post_meta( $post_id, $meta_key, true );
    return $id ? ( wp_get_attachment_image_url( $id, 'full' ) ?: null ) : null;
}
