<?php
defined( 'ABSPATH' ) || exit;

// ── Register hero post meta ───────────────────────────────────────────────────

add_action( 'init', function () {
    // Hero title (ORBI-52). Replaces the page/post title in the hero; the theme
    // falls back to the post title when this is empty, so the WP title stays free
    // for the browser/SEO title. Resolved to null in GraphQL when unset.
    // wp_kses_post, not sanitize_text_field: the theme parses this as HTML, so a
    // `<br>` (or inline formatting) must survive the save — stripping tags here
    // silently collapsed multi-line hero titles to one line.
    $title_args = [
        'type'              => 'string',
        'single'            => true,
        'default'           => '',
        'show_in_rest'      => true,
        'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
        'sanitize_callback' => 'wp_kses_post',
    ];
    register_post_meta( 'page', 'soames_hero_title', $title_args );
    register_post_meta( 'post', 'soames_hero_title', $title_args );

    // Hero caption (ORBI-52). Replaces the excerpt as the hero subhead. NO
    // fallback: empty means the theme renders no caption at all. wp_kses_post
    // because the theme parses this as HTML (inline formatting/links).
    $caption_args = [
        'type'              => 'string',
        'single'            => true,
        'default'           => '',
        'show_in_rest'      => true,
        'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
        'sanitize_callback' => 'wp_kses_post',
    ];
    register_post_meta( 'page', 'soames_hero_caption', $caption_args );
    register_post_meta( 'post', 'soames_hero_caption', $caption_args );

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

    // ORBI-64: dedicated blog image — stores the attachment ID. Shown in the single
    // post's sidebar above Recent Posts, where the featured image used to be. `post`
    // ONLY, deliberately: this is a blog concept, and registering it for `page` too
    // would put a meaningless control on every page. There is NO featured-image
    // fallback in the theme — the featured image is being freed up for other uses —
    // so 0 (unset) means no sidebar image renders at all.
    register_post_meta( 'post', 'soames_blog_image_id', [
        'type'              => 'integer',
        'single'            => true,
        'default'           => 0,
        'show_in_rest'      => true,
        'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
        'sanitize_callback' => 'absint',
    ] );
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

    // ORBI-64: posts only — see the register_post_meta note above.
    add_meta_box(
        'soames_blog_image_box',
        'Blog Image',
        'soames_blog_image_render_meta_box',
        'post',
        'side',
        'default'
    );
} );

function soames_hero_render_meta_box( $post ) {
    wp_nonce_field( 'soames_hero_save', 'soames_hero_nonce' );

    // Hero title (ORBI-52) — blank falls back to the page/post title in the theme.
    $hero_title = (string) get_post_meta( $post->ID, 'soames_hero_title', true );
    echo '<label for="soames_hero_title" style="display:block;margin-bottom:6px">Title</label>';
    printf(
        '<input type="text" id="soames_hero_title" name="soames_hero_title" value="%s" style="width:100%%;box-sizing:border-box" />',
        esc_attr( $hero_title )
    );
    echo '<p class="description" style="margin-bottom:14px">Optional. Defaults to the page title. HTML allowed — use <code>&lt;br&gt;</code> to split the title over two lines.</p>';

    // Hero caption (ORBI-52) — blank means no caption is rendered at all.
    $hero_caption = (string) get_post_meta( $post->ID, 'soames_hero_caption', true );
    echo '<label for="soames_hero_caption" style="display:block;margin-bottom:6px">Caption</label>';
    printf(
        '<textarea id="soames_hero_caption" name="soames_hero_caption" rows="3" style="width:100%%;box-sizing:border-box">%s</textarea>',
        esc_textarea( $hero_caption )
    );
    echo '<p class="description" style="margin-bottom:14px">Optional. Leave blank to show no caption. HTML allowed.</p>';

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

// ORBI-64: the blog image picker. Reuses the shared soames-media-upload /
// soames-media-clear + data-target wiring in assets/admin.js (data-target="X"
// drives #X_id and #X_preview), so the hidden input id/name must stay
// soames_blog_image_id and the preview soames_blog_image_preview.
function soames_blog_image_render_meta_box( $post ) {
    wp_nonce_field( 'soames_blog_image_save', 'soames_blog_image_nonce' );

    $img_id  = (int) get_post_meta( $post->ID, 'soames_blog_image_id', true );
    $img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
    printf(
        '<img id="soames_blog_image_preview" src="%s" style="max-width:100%%;height:auto;display:%s;margin-bottom:8px;" />',
        esc_url( $img_url ),
        $img_url ? 'block' : 'none'
    );
    printf(
        '<input type="hidden" id="soames_blog_image_id" name="soames_blog_image_id" value="%s" />',
        esc_attr( $img_id ?: '' )
    );
    echo '<button type="button" class="button soames-media-upload" data-target="soames_blog_image">'
        . ( $img_url ? 'Change image' : 'Select image' ) . '</button> ';
    printf(
        '<button type="button" class="button soames-media-clear" data-target="soames_blog_image" style="%s">Remove</button>',
        $img_url ? '' : 'display:none'
    );
    echo '<p class="description">Shown in the sidebar above <em>Recent Posts</em>, in place of the featured image. There is no fallback — leave this blank and the post shows no sidebar image.</p>';
}

add_action( 'save_post', function ( $post_id ) {
    if (
        ! isset( $_POST['soames_blog_image_nonce'] ) ||
        ! wp_verify_nonce( $_POST['soames_blog_image_nonce'], 'soames_blog_image_save' ) ||
        ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
        ! current_user_can( 'edit_post', $post_id )
    ) return;

    if ( isset( $_POST['soames_blog_image_id'] ) ) {
        // absint( '' ) === 0, which the GraphQL resolver treats as "unset" — so
        // clearing the picker really clears the image.
        update_post_meta( $post_id, 'soames_blog_image_id', absint( $_POST['soames_blog_image_id'] ) );
    }
} );

add_action( 'save_post', function ( $post_id ) {
    if (
        ! isset( $_POST['soames_hero_nonce'] ) ||
        ! wp_verify_nonce( $_POST['soames_hero_nonce'], 'soames_hero_save' ) ||
        ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
        ! current_user_can( 'edit_post', $post_id )
    ) return;

    // ORBI-52: write even when empty so clearing a field really clears it (an empty
    // caption is meaningful — it means "render no caption"). Both fields are HTML
    // (the theme parses them), hence wp_kses_post; wp_unslash first because $_POST
    // is slashed and kses parsing an escaped attribute quote (href=\"…\") would
    // strip the attribute.
    if ( isset( $_POST['soames_hero_title'] ) ) {
        update_post_meta( $post_id, 'soames_hero_title', trim( wp_kses_post( wp_unslash( $_POST['soames_hero_title'] ) ) ) );
    }
    if ( isset( $_POST['soames_hero_caption'] ) ) {
        update_post_meta( $post_id, 'soames_hero_caption', wp_kses_post( wp_unslash( $_POST['soames_hero_caption'] ) ) );
    }

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
        // ORBI-52: null when unset — the THEME owns the fallback chain (hero title
        // → post title → template default), the same way heroBackgroundImage leaves
        // the featured-image fallback to the theme's resolveHeroBg().
        register_graphql_field( $type, 'heroTitle', [
            'type'        => 'String',
            'description' => 'Hero header title. Null when unset; the theme falls back to the post title.',
            'resolve'     => fn( $post ) => get_post_meta( $post->databaseId, 'soames_hero_title', true ) ?: null,
        ] );

        register_graphql_field( $type, 'heroCaption', [
            'type'        => 'String',
            'description' => 'Hero header caption (HTML). Null when unset; the theme then renders no caption — there is deliberately no excerpt fallback.',
            'resolve'     => fn( $post ) => get_post_meta( $post->databaseId, 'soames_hero_caption', true ) ?: null,
        ] );

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

    // ORBI-64: Post only — there is no Page equivalent (see register_post_meta).
    // Deliberately NO featured-image fallback here or in the theme: null means the
    // sidebar renders no image. Returns a bare URL, matching heroBackgroundImage;
    // the theme sizes it with CSS rather than intrinsic width/height attributes,
    // which is what caused the overflow this story fixes.
    register_graphql_field( 'Post', 'blogImage', [
        'type'        => 'String',
        'description' => 'Dedicated blog image URL, shown in the single post sidebar above Recent Posts. Null when unset — there is deliberately no featured-image fallback.',
        'resolve'     => function ( $post ) {
            $id = (int) get_post_meta( $post->databaseId, 'soames_blog_image_id', true );
            return $id ? wp_get_attachment_image_url( $id, 'full' ) : null;
        },
    ] );
} );
