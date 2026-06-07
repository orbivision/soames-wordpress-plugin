<?php
defined( 'ABSPATH' ) || exit;

// ── Site Assets submenu under the Soames menu (created by the theme) ──────────

add_action( 'admin_menu', function () {
    add_submenu_page(
        'soames-settings',
        'Site Assets',
        'Site Assets',
        'manage_options',
        'soames-site-assets',
        'soames_site_assets_page'
    );
}, 20 ); // priority 20 so the theme's menu registers first

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

add_action( 'admin_init', function () {
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
} );

// ── Site Assets settings page ─────────────────────────────────────────────────

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
