<?php
defined( 'ABSPATH' ) || exit;

// ── Admin menu ────────────────────────────────────────────────────────────────

// Monochrome Soames brand mark for the top-level menu icon. Passed as a base64
// data-URI so it renders on its own (light gray, like a native icon); the scoped
// CSS in soames_admin_menu_icon_css() then masks it with currentColor so it adopts
// the menu's hover/current colors across admin color schemes.
function soames_admin_menu_icon_uri() {
    $svg = @file_get_contents( SOAMES_PLUGIN_DIR . 'assets/soames-mark-mono.svg' );
    if ( ! $svg ) {
        return 'dashicons-admin-site-alt3'; // fall back to the old globe if unreadable
    }
    return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

add_action( 'admin_menu', function () {
    add_menu_page(
        'Soames Settings',
        'Soames',
        'manage_options',
        'soames-settings',
        'soames_settings_page',
        soames_admin_menu_icon_uri(),
        60
    );
    // Override the auto-generated duplicate submenu title ("Soames" → "Settings").
    add_submenu_page(
        'soames-settings',
        'Soames Settings',
        'Settings',
        'manage_options',
        'soames-settings',
        'soames_settings_page'
    );
    add_submenu_page(
        'soames-settings',
        'Site Assets',
        'Site Assets',
        'manage_options',
        'soames-site-assets',
        'soames_site_assets_page'
    );
} );

// ── Force the Soames submenu into a deliberate (non-alphabetical) order ────────
// The Knowledge Base CPT submenu is injected by core (wp-admin/menu.php) before
// our admin_menu callback runs, so registration order alone can't place it last.
// Re-sort at a late priority once every item is present. $submenu entries are
// array( title, cap, slug, ... ), so the slug lives at index [2].
add_action( 'admin_menu', function () {
    global $submenu;
    if ( empty( $submenu['soames-settings'] ) ) return;
    $order = array( 'soames-settings', 'soames-site-assets', 'edit.php?post_type=docs' );
    usort( $submenu['soames-settings'], function ( $a, $b ) use ( $order ) {
        $ia = array_search( $a[2], $order, true );
        $ib = array_search( $b[2], $order, true );
        $ia = ( false === $ia ) ? PHP_INT_MAX : $ia;
        $ib = ( false === $ib ) ? PHP_INT_MAX : $ib;
        return $ia - $ib;
    } );
}, 999 );

// ── Recolor the Soames menu icon to match the admin scheme ────────────────────
// WP renders a data-URI SVG icon as a fixed-color background image. To make it
// behave like a native icon (gray at rest, white/blue on hover & current), hide
// that background and paint an ::before whose color WP already drives per-scheme,
// masked to the mark's shape. Scoped to the Soames menu id so nothing else changes.
add_action( 'admin_head', function () {
    $uri = soames_admin_menu_icon_uri();
    if ( 0 !== strpos( $uri, 'data:image/svg+xml' ) ) {
        return; // fell back to a dashicon; nothing to mask
    }
    $mask = "url('" . esc_attr( $uri ) . "') no-repeat center / 20px 20px";
    ?>
    <style id="soames-admin-menu-icon">
    #adminmenu #toplevel_page_soames-settings .wp-menu-image {
        background-image: none !important;
    }
    #adminmenu #toplevel_page_soames-settings .wp-menu-image::before {
        content: "";
        display: block;
        width: 100%;   /* fill the 36x34 icon box; the mask centers the 20px mark  */
        height: 100%;  /* so it lands on the same center as native dashicons       */
        padding: 0;    /* cancel WP's inherited div.wp-menu-image:before padding    */
        margin: 0;
        background-color: currentColor;
        -webkit-mask: <?php echo $mask; ?>;
                mask: <?php echo $mask; ?>;
    }
    </style>
    <?php
} );

// ── Enqueue media picker on the Site Assets page ──────────────────────────────

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'soames_page_soames-site-assets' ) return;
    wp_enqueue_media();
    wp_enqueue_script(
        'soames-plugin-admin',
        SOAMES_PLUGIN_URL . 'assets/admin.js',
        [ 'jquery' ],
        soames_asset_version( 'assets/admin.js' ),
        true
    );
} );

// ── Settings registration ─────────────────────────────────────────────────────

add_action( 'admin_init', 'soames_register_settings' );

function soames_register_settings() {
    register_setting( 'soames_options', 'soames_frontend_url', [
        'type'              => 'string',
        'sanitize_callback' => 'soames_sanitize_frontend_url',
        'default'           => '',
    ] );
    register_setting( 'soames_options', 'soames_frontend_redirect', [
        'type'              => 'string',
        // Checkboxes post nothing when unchecked, so absence means off. Default '1' keeps
        // upgrades from the companion theme behaving identically (ORBI-58).
        'sanitize_callback' => function ( $value ) { return $value ? '1' : '0'; },
        'default'           => '1',
    ] );
    register_setting( 'soames_options', 'soames_docs_page_id', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ] );
    register_setting( 'soames_options', 'soames_build_hook_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ] );
    register_setting( 'soames_assets_options', 'soames_logo_id', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ] );
    register_setting( 'soames_assets_options', 'soames_favicon_id', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ] );
    register_setting( 'soames_assets_options', 'soames_contact_blurb', [
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
        'default'           => '',
    ] );
    register_setting( 'soames_assets_options', 'soames_company_name', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ] );
    register_setting( 'soames_assets_options', 'soames_show_company_name', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 1,
    ] );
}

function soames_sanitize_frontend_url( $url ) {
    $url = esc_url_raw( trim( $url ) );
    if ( $url && ! wp_http_validate_url( $url ) ) {
        add_settings_error(
            'soames_frontend_url',
            'invalid_url',
            'Please enter a valid URL including http:// or https://.',
            'error'
        );
        return get_option( 'soames_frontend_url' );
    }
    return $url;
}

// ── Settings page (Frontend URL) ──────────────────────────────────────────────

function soames_settings_page() {
    if ( isset( $_GET['settings-updated'] ) ) {
        $errors = get_settings_errors( 'soames_frontend_url' );
        if ( empty( $errors ) ) {
            add_settings_error( 'soames_messages', 'soames_saved', 'Settings saved.', 'updated' );
        }
    }
    ?>
    <div class="wrap">
        <h1>Soames Settings</h1>
        <?php
        settings_errors( 'soames_messages' );
        settings_errors( 'soames_frontend_url' );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'soames_options' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="soames_frontend_url">Frontend Site URL</label>
                    </th>
                    <td>
                        <input
                            type="url"
                            id="soames_frontend_url"
                            name="soames_frontend_url"
                            value="<?php echo esc_attr( get_option( 'soames_frontend_url' ) ); ?>"
                            class="regular-text"
                            placeholder="https://example.com"
                        />
                        <p class="description">
                            Direct visits to this WordPress installation will be redirected to this URL.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Front-end redirection</th>
                    <td>
                        <label for="soames_frontend_redirect">
                            <input
                                type="checkbox"
                                id="soames_frontend_redirect"
                                name="soames_frontend_redirect"
                                value="1"
                                <?php checked( get_option( 'soames_frontend_redirect', '1' ), '1' ); ?>
                            />
                            Send visitors to the front-end site
                        </label>
                        <p class="description">
                            On by default. Posts, pages and Knowledge Base articles redirect to
                            their matching address on the front-end site; everything else goes to
                            its home page. Previews are never redirected — they open on the
                            front-end site's preview route.
                            <br />
                            Turn this off to make WordPress serve its own active theme again,
                            which is useful while debugging. It has no effect until a
                            <strong>Frontend Site URL</strong> is set above.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="soames_docs_page_id">Knowledge Base page</label>
                    </th>
                    <td>
                        <?php
                        wp_dropdown_pages( [
                            'name'              => 'soames_docs_page_id',
                            'id'                => 'soames_docs_page_id',
                            'selected'          => (int) get_option( 'soames_docs_page_id' ),
                            'show_option_none'  => '— None —',
                            'option_none_value' => 0,
                        ] );
                        ?>
                        <p class="description">
                            Sets the hero header (title, subhead, background image, overlay)
                            for the <code>/docs/</code> landing page. Leave as “— None —” to
                            use the default “Knowledge Base” hero.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="soames_build_hook_url">Netlify build hook URL</label>
                    </th>
                    <td>
                        <input
                            type="url"
                            id="soames_build_hook_url"
                            name="soames_build_hook_url"
                            value="<?php echo esc_attr( get_option( 'soames_build_hook_url' ) ); ?>"
                            class="regular-text"
                            placeholder="https://api.netlify.com/build_hooks/…"
                        />
                        <p class="description">
                            When set, publishing or updating content automatically rebuilds
                            the site (the change goes live about a minute after you publish).
                            Create a build hook in Netlify → Site configuration → Build &amp; deploy → Build hooks.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr />
        <h2>Manual deploy</h2>
        <p>Rebuild the site now — useful after changing menus or Site Assets, which don’t trigger an automatic build.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="soames_deploy_now" />
            <?php wp_nonce_field( 'soames_deploy_now' ); ?>
            <?php submit_button( 'Deploy now', 'secondary', 'submit', false ); ?>
        </form>
    </div>
    <?php
}

// ── Site Assets page (Logo, Favicon, Contact Blurb) ───────────────────────────

function soames_site_assets_page() {
    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'soames_assets_messages', 'soames_assets_saved', 'Settings saved.', 'updated' );
    }

    $logo_id     = (int) get_option( 'soames_logo_id' );
    $favicon_id  = (int) get_option( 'soames_favicon_id' );
    $logo_url    = $logo_id    ? wp_get_attachment_url( $logo_id )    : '';
    $favicon_url = $favicon_id ? wp_get_attachment_url( $favicon_id ) : '';
    ?>
    <div class="wrap">
        <h1>Soames Site Assets</h1>
        <?php settings_errors( 'soames_assets_messages' ); ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'soames_assets_options' ); ?>
            <table class="form-table">

                <tr>
                    <th scope="row">Logo</th>
                    <td>
                        <?php if ( $logo_url ) : ?>
                            <img id="soames_logo_preview" src="<?php echo esc_url( $logo_url ); ?>" style="max-height:80px;display:block;margin-bottom:8px;" />
                        <?php else : ?>
                            <img id="soames_logo_preview" src="" style="max-height:80px;display:none;margin-bottom:8px;" />
                        <?php endif; ?>
                        <input type="hidden" id="soames_logo_id" name="soames_logo_id" value="<?php echo esc_attr( $logo_id ?: '' ); ?>" />
                        <button type="button" class="button soames-media-upload" data-target="soames_logo">
                            <?php echo $logo_url ? 'Change logo' : 'Select logo'; ?>
                        </button>
                        <?php if ( $logo_url ) : ?>
                            <button type="button" class="button soames-media-clear" data-target="soames_logo" style="margin-left:4px;">Remove</button>
                        <?php endif; ?>
                        <p class="description">Displayed in the site header. Recommended width: 200px.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Favicon</th>
                    <td>
                        <?php if ( $favicon_url ) : ?>
                            <img id="soames_favicon_preview" src="<?php echo esc_url( $favicon_url ); ?>" style="max-height:48px;display:block;margin-bottom:8px;" />
                        <?php else : ?>
                            <img id="soames_favicon_preview" src="" style="max-height:48px;display:none;margin-bottom:8px;" />
                        <?php endif; ?>
                        <input type="hidden" id="soames_favicon_id" name="soames_favicon_id" value="<?php echo esc_attr( $favicon_id ?: '' ); ?>" />
                        <button type="button" class="button soames-media-upload" data-target="soames_favicon">
                            <?php echo $favicon_url ? 'Change favicon' : 'Select favicon'; ?>
                        </button>
                        <?php if ( $favicon_url ) : ?>
                            <button type="button" class="button soames-media-clear" data-target="soames_favicon" style="margin-left:4px;">Remove</button>
                        <?php endif; ?>
                        <p class="description">Browser tab icon. Use a PNG — 32×32px or 64×64px recommended.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="soames_company_name">Company name</label></th>
                    <td>
                        <input
                            type="text"
                            id="soames_company_name"
                            name="soames_company_name"
                            value="<?php echo esc_attr( get_option( 'soames_company_name', '' ) ); ?>"
                            class="regular-text"
                            placeholder="Acme Corp"
                        />
                        <p class="description">Displayed in the site header next to the logo. Leave blank to use the WordPress site title.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Show company name in header</th>
                    <td>
                        <input type="hidden" name="soames_show_company_name" value="0" />
                        <label>
                            <input
                                type="checkbox"
                                id="soames_show_company_name"
                                name="soames_show_company_name"
                                value="1"
                                <?php checked( 1, get_option( 'soames_show_company_name', 1 ) ); ?>
                            />
                            Show company name in the header alongside the logo
                        </label>
                        <p class="description">Uncheck for sites where the logo image already contains the company name.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="soames_contact_blurb">Contact blurb</label></th>
                    <td>
                        <textarea
                            id="soames_contact_blurb"
                            name="soames_contact_blurb"
                            rows="4"
                            class="large-text"
                        ><?php echo wp_kses_post( get_option( 'soames_contact_blurb', '' ) ); ?></textarea>
                        <p class="description">Displayed in the footer Contact column. Basic HTML (links, line breaks) is allowed.</p>
                    </td>
                </tr>

            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
