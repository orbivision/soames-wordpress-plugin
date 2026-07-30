<?php
/**
 * ORBI-53 — Local author profile pictures.
 *
 * WordPress core has no avatar upload: wp-admin/user-edit.php renders the Profile
 * Picture row as a read-only get_avatar() plus a "change it on Gravatar" link. This
 * adds a real media picker to the user profile and overrides Gravatar with the
 * chosen image.
 *
 * The override hooks pre_get_avatar_data, which get_avatar_data() applies before it
 * does anything else — so a single filter covers every consumer at once: the theme's
 * byline (WPGraphQL resolves `avatar { url }` through get_avatar_data), get_avatar()
 * in wp-admin, and comments. The theme needs no query change.
 *
 * CAVEAT — WPGraphQL nulls the avatar entirely when Settings > Discussion > "Show
 * Avatars" is off (its Avatar model treats that as private), so leave that on.
 */

defined( 'ABSPATH' ) || exit;

// Attachment ID of the picked image; kept only to repopulate the picker.
const SOAMES_AVATAR_ID_META  = 'soames_avatar_id';
// URL resolved at save time — this is what actually gets served. See the multisite
// note on soames_user_avatar_save() for why we don't resolve the ID at render time.
const SOAMES_AVATAR_URL_META = 'soames_avatar_url';

// ── Profile field ─────────────────────────────────────────────────────────────

// show_user_profile fires on your own profile, edit_user_profile when editing
// someone else's; the field is identical on both.
add_action( 'show_user_profile', 'soames_user_avatar_field' );
add_action( 'edit_user_profile', 'soames_user_avatar_field' );

function soames_user_avatar_field( $user ) {
    if ( ! current_user_can( 'edit_user', $user->ID ) ) return;

    $avatar_id  = (int) get_user_meta( $user->ID, SOAMES_AVATAR_ID_META, true );
    $avatar_url = (string) get_user_meta( $user->ID, SOAMES_AVATAR_URL_META, true );
    ?>
    <h2>Soames Profile Picture</h2>
    <table class="form-table">
        <tr>
            <th scope="row">Profile Picture</th>
            <td>
                <?php if ( $avatar_url ) : ?>
                    <img id="soames_user_avatar_preview" src="<?php echo esc_url( $avatar_url ); ?>" style="width:56px;height:56px;border-radius:50%;object-fit:cover;display:block;margin-bottom:8px;" />
                <?php else : ?>
                    <img id="soames_user_avatar_preview" src="" style="width:56px;height:56px;border-radius:50%;object-fit:cover;display:none;margin-bottom:8px;" />
                <?php endif; ?>
                <input type="hidden" id="soames_user_avatar_id" name="soames_user_avatar_id" value="<?php echo esc_attr( $avatar_id ?: '' ); ?>" />
                <?php wp_nonce_field( 'soames_user_avatar', 'soames_user_avatar_nonce' ); ?>
                <button type="button" class="button soames-media-upload" data-target="soames_user_avatar">
                    <?php echo $avatar_url ? 'Change picture' : 'Select picture'; ?>
                </button>
                <?php if ( $avatar_url ) : ?>
                    <button type="button" class="button soames-media-clear" data-target="soames_user_avatar" style="margin-left:4px;">Remove</button>
                <?php endif; ?>
                <p class="description">
                    Shown in the author byline at the end of your blog posts. Replaces the
                    Gravatar image above — no Gravatar account needed. Use a square image;
                    it is displayed as a small circle.
                </p>
                <?php if ( ! get_option( 'show_avatars' ) ) : ?>
                    <?php // Verified against WPGraphQL: with avatars off its Avatar model reports
                          // the avatar private and `avatar { url }` resolves to null, so the byline
                          // image disappears even with a picture set here. Warn rather than fail
                          // silently. ?>
                    <p class="description" style="color:#b32d2e;">
                        <strong>Avatars are currently turned off</strong> for this site, so this
                        picture will not appear on the website. Enable
                        <a href="<?php echo esc_url( admin_url( 'options-discussion.php' ) ); ?>">Settings &rarr; Discussion &rarr; Show Avatars</a>.
                    </p>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}

// Reuse the Site Assets media picker (assets/admin.js): its delegated handlers key
// off data-target and drive #<target>_id / #<target>_preview, which the markup above
// matches — so no JS change is needed here.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'profile.php' && $hook !== 'user-edit.php' ) return;
    wp_enqueue_media();
    wp_enqueue_script(
        'soames-plugin-admin',
        SOAMES_PLUGIN_URL . 'assets/admin.js',
        [ 'jquery' ],
        '1.0.0',
        true
    );
} );

// ── Save ──────────────────────────────────────────────────────────────────────

add_action( 'personal_options_update', 'soames_user_avatar_save' );
add_action( 'edit_user_profile_update', 'soames_user_avatar_save' );

function soames_user_avatar_save( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) return;
    if ( ! isset( $_POST['soames_user_avatar_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_key( $_POST['soames_user_avatar_nonce'] ), 'soames_user_avatar' ) ) return;

    $avatar_id = isset( $_POST['soames_user_avatar_id'] ) ? absint( $_POST['soames_user_avatar_id'] ) : 0;

    if ( ! $avatar_id ) {
        delete_user_meta( $user_id, SOAMES_AVATAR_ID_META );
        delete_user_meta( $user_id, SOAMES_AVATAR_URL_META );
        return;
    }

    // MULTISITE: usermeta is network-global but the media library is per-site, so an
    // attachment ID only resolves on the site it was uploaded to — elsewhere in the
    // network that same ID is a different post, or nothing. So resolve the URL here,
    // on the site where the picker ran, and serve that stored URL forever after.
    //
    // 'thumbnail' is 150×150 hard-cropped by default: ample for a 56px avatar at 2×,
    // and it avoids serving a multi-megabyte original. Falls back to full size when
    // no thumbnail exists (small or non-resizable uploads).
    //
    // One stored rendition serves every request, so an avatar asked for at >150px gets
    // the 150px crop rather than something larger. Fine for this stack: the biggest
    // consumer is wp-admin's own 96px get_avatar(), and the byline is 56px.
    $url = wp_get_attachment_image_url( $avatar_id, 'thumbnail' );
    if ( ! $url ) {
        $url = wp_get_attachment_url( $avatar_id );
    }
    if ( ! $url ) {
        delete_user_meta( $user_id, SOAMES_AVATAR_ID_META );
        delete_user_meta( $user_id, SOAMES_AVATAR_URL_META );
        return;
    }

    update_user_meta( $user_id, SOAMES_AVATAR_ID_META, $avatar_id );
    update_user_meta( $user_id, SOAMES_AVATAR_URL_META, esc_url_raw( $url ) );
}

// ── Gravatar override ─────────────────────────────────────────────────────────

// Setting $args['url'] short-circuits the whole Gravatar path in get_avatar_data();
// leaving $args untouched lets everyone without a local avatar fall through to it.
add_filter( 'pre_get_avatar_data', 'soames_user_avatar_data', 10, 2 );

function soames_user_avatar_data( $args, $id_or_email ) {
    if ( ! empty( $args['force_default'] ) ) return $args;

    $user_id = soames_resolve_avatar_user_id( $id_or_email );
    if ( ! $user_id ) return $args;

    $url = (string) get_user_meta( $user_id, SOAMES_AVATAR_URL_META, true );
    if ( '' === $url ) return $args;

    $args['url']           = $url;
    $args['found_avatar']  = true;

    return $args;
}

// get_avatar_data() accepts a user ID, WP_User, WP_Post, WP_Comment, email address,
// or a Gravatar hash. Only the forms that identify a local user are resolvable here;
// anything else returns 0 so the filter passes through to Gravatar.
function soames_resolve_avatar_user_id( $id_or_email ) {
    if ( is_numeric( $id_or_email ) ) {
        return absint( $id_or_email );
    }
    if ( $id_or_email instanceof WP_User ) {
        return (int) $id_or_email->ID;
    }
    if ( $id_or_email instanceof WP_Post ) {
        return (int) $id_or_email->post_author;
    }
    if ( $id_or_email instanceof WP_Comment ) {
        if ( ! empty( $id_or_email->user_id ) ) {
            return (int) $id_or_email->user_id;
        }
        if ( ! empty( $id_or_email->comment_author_email ) ) {
            $user = get_user_by( 'email', $id_or_email->comment_author_email );
            return $user ? (int) $user->ID : 0;
        }
        return 0;
    }
    if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
        $user = get_user_by( 'email', $id_or_email );
        return $user ? (int) $user->ID : 0;
    }
    return 0;
}
