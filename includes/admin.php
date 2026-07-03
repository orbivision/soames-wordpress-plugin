<?php
defined( 'ABSPATH' ) || exit;

// ── Admin menu ────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_menu_page(
        'Soames Settings',
        'Soames',
        'manage_options',
        'soames-settings',
        'soames_settings_page',
        'dashicons-admin-site-alt3',
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

// ── Enqueue media picker on the Site Assets page ──────────────────────────────

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'soames_page_soames-site-assets' ) return;
    wp_enqueue_media();
    wp_enqueue_script(
        'soames-plugin-admin',
        SOAMES_PLUGIN_URL . 'assets/admin.js',
        [ 'jquery' ],
        '1.0.0',
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
    register_setting( 'soames_options', 'soames_docs_page_id', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
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
                    <th scope="row">
                        <label for="soames_docs_page_id">Documentation page</label>
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
                            use the default “Documentation” hero.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
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
