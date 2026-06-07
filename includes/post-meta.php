<?php
defined( 'ABSPATH' ) || exit;

// ── Page excerpt support ──────────────────────────────────────────────────────
// Enables the excerpt field on Pages so WPGraphQL exposes it as excerptField.

add_action( 'init', function () {
    add_post_type_support( 'page', 'excerpt' );
} );

// ── Register soames_overlay_opacity post meta ─────────────────────────────────

add_action( 'init', function () {
    $args = [
        'type'              => 'string',
        'single'            => true,
        'default'           => '0.6',
        'show_in_rest'      => true,
        'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
        'sanitize_callback' => 'sanitize_text_field',
    ];
    register_post_meta( 'page', 'soames_overlay_opacity', $args );
    register_post_meta( 'post', 'soames_overlay_opacity', $args );
} );

// ── Metabox ───────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'soames_overlay_opacity_box',
        'Hero Overlay Opacity',
        'soames_overlay_opacity_render_meta_box',
        [ 'page', 'post' ],
        'side',
        'default'
    );
} );

function soames_overlay_opacity_render_meta_box( $post ) {
    $value   = get_post_meta( $post->ID, 'soames_overlay_opacity', true ) ?: '0.6';
    $options = [ '0.2', '0.3', '0.4', '0.5', '0.6', '0.7' ];
    wp_nonce_field( 'soames_overlay_opacity_save', 'soames_overlay_opacity_nonce' );
    echo '<label for="soames_overlay_opacity" style="display:block;margin-bottom:6px">Overlay opacity</label>';
    echo '<select id="soames_overlay_opacity" name="soames_overlay_opacity" style="width:100%;box-sizing:border-box">';
    foreach ( $options as $opt ) {
        $selected = selected( $value, $opt, false );
        echo "<option value=\"{$opt}\" {$selected}>{$opt}</option>";
    }
    echo '</select>';
}

add_action( 'save_post', function ( $post_id ) {
    if (
        ! isset( $_POST['soames_overlay_opacity_nonce'] ) ||
        ! wp_verify_nonce( $_POST['soames_overlay_opacity_nonce'], 'soames_overlay_opacity_save' ) ||
        ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
        ! current_user_can( 'edit_post', $post_id )
    ) return;

    if ( isset( $_POST['soames_overlay_opacity'] ) ) {
        update_post_meta( $post_id, 'soames_overlay_opacity', sanitize_text_field( $_POST['soames_overlay_opacity'] ) );
    }
} );

// ── WPGraphQL field ───────────────────────────────────────────────────────────

add_action( 'graphql_register_types', function () {
    foreach ( [ 'Page', 'Post' ] as $type ) {
        register_graphql_field( $type, 'overlayOpacity', [
            'type'        => 'String',
            'description' => 'Hero header overlay opacity (0.2–0.7)',
            'resolve'     => fn( $post ) => get_post_meta( $post->databaseId, 'soames_overlay_opacity', true ) ?: '0.6',
        ] );
    }
} );
