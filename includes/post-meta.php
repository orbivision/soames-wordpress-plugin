<?php
defined( 'ABSPATH' ) || exit;

// ── Register hero post meta ───────────────────────────────────────────────────

add_action( 'init', function () {
    // Overlay opacity (string, e.g. "0.6").
    $opacity_args = [
        'type'              => 'string',
        'single'            => true,
        'default'           => '0.6',
        'show_in_rest'      => true,
        'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
        'sanitize_callback' => 'sanitize_text_field',
    ];
    register_post_meta( 'page', 'soames_overlay_opacity', $opacity_args );
    register_post_meta( 'post', 'soames_overlay_opacity', $opacity_args );

    // Dedicated hero background image — stores the attachment ID. Takes priority
    // over the featured image in the theme; 0 (unset) → theme falls back to the
    // featured image, then a placeholder.
    $bg_args = [
        'type'              => 'integer',
        'single'            => true,
        'default'           => 0,
        'show_in_rest'      => true,
        'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
        'sanitize_callback' => 'absint',
    ];
    register_post_meta( 'page', 'soames_hero_bg_id', $bg_args );
    register_post_meta( 'post', 'soames_hero_bg_id', $bg_args );
} );

// ── Metabox ───────────────────────────────────────────────────────────────────

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'soames_hero_box',
        'Hero Header',
        'soames_hero_render_meta_box',
        [ 'page', 'post' ],
        'side',
        'default'
    );
} );

function soames_hero_render_meta_box( $post ) {
    wp_nonce_field( 'soames_hero_save', 'soames_hero_nonce' );

    // Hero background image picker (above the overlay opacity control).
    $bg_id  = (int) get_post_meta( $post->ID, 'soames_hero_bg_id', true );
    $bg_url = $bg_id ? wp_get_attachment_image_url( $bg_id, 'medium' ) : '';
    echo '<label style="display:block;margin-bottom:6px">Background image</label>';
    printf(
        '<img id="soames_hero_bg_preview" src="%s" style="max-width:100%%;height:auto;display:%s;margin-bottom:8px;" />',
        esc_url( $bg_url ),
        $bg_url ? 'block' : 'none'
    );
    printf(
        '<input type="hidden" id="soames_hero_bg_id" name="soames_hero_bg_id" value="%s" />',
        esc_attr( $bg_id ?: '' )
    );
    echo '<button type="button" class="button soames-media-upload" data-target="soames_hero_bg">'
        . ( $bg_url ? 'Change image' : 'Select image' ) . '</button> ';
    printf(
        '<button type="button" class="button soames-media-clear" data-target="soames_hero_bg" style="%s">Remove</button>',
        $bg_url ? '' : 'display:none'
    );
    echo '<p class="description" style="margin-bottom:14px">Hero header background. Falls back to the featured image if unset.</p>';

    // Overlay opacity.
    $value   = get_post_meta( $post->ID, 'soames_overlay_opacity', true ) ?: '0.6';
    $options = [ '0.2', '0.3', '0.4', '0.5', '0.6', '0.7' ];
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
        ! isset( $_POST['soames_hero_nonce'] ) ||
        ! wp_verify_nonce( $_POST['soames_hero_nonce'], 'soames_hero_save' ) ||
        ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
        ! current_user_can( 'edit_post', $post_id )
    ) return;

    if ( isset( $_POST['soames_overlay_opacity'] ) ) {
        update_post_meta( $post_id, 'soames_overlay_opacity', sanitize_text_field( $_POST['soames_overlay_opacity'] ) );
    }
    if ( isset( $_POST['soames_hero_bg_id'] ) ) {
        // absint( '' ) === 0, which the GraphQL resolver treats as "unset".
        update_post_meta( $post_id, 'soames_hero_bg_id', absint( $_POST['soames_hero_bg_id'] ) );
    }
} );

// ── Media picker on the post/page editor ──────────────────────────────────────
// The shared wp.media picker in assets/admin.js (data-target convention) is only
// enqueued on the Site Assets settings page, not the editor. Load it here so the
// Hero background image picker works in the post/page editor.

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->post_type, [ 'page', 'post' ], true ) ) return;

    wp_enqueue_media();
    wp_enqueue_script(
        'soames-plugin-admin',
        SOAMES_PLUGIN_URL . 'assets/admin.js',
        [ 'jquery' ],
        '1.0.0',
        true
    );
} );

// ── WPGraphQL fields ──────────────────────────────────────────────────────────

add_action( 'graphql_register_types', function () {
    foreach ( [ 'Page', 'Post' ] as $type ) {
        register_graphql_field( $type, 'overlayOpacity', [
            'type'        => 'String',
            'description' => 'Hero header overlay opacity (0.2–0.7)',
            'resolve'     => fn( $post ) => get_post_meta( $post->databaseId, 'soames_overlay_opacity', true ) ?: '0.6',
        ] );

        register_graphql_field( $type, 'heroBackgroundImage', [
            'type'        => 'String',
            'description' => 'Dedicated hero header background image URL. Falls back to the featured image, then a placeholder, in the theme when unset.',
            'resolve'     => function ( $post ) {
                $id = (int) get_post_meta( $post->databaseId, 'soames_hero_bg_id', true );
                return $id ? wp_get_attachment_image_url( $id, 'full' ) : null;
            },
        ] );
    }
} );
