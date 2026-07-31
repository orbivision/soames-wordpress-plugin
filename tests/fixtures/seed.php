<?php
/**
 * ORBI-54 — e2e fixture seeder. Run inside wp-env:
 *
 *   npm run seed
 *
 * Idempotent: re-running updates the same fixtures in place (looked up by slug or
 * login) rather than piling up duplicates, so it's safe to run before every suite.
 *
 * This is the ORBI-53 verification script, generalized and kept. Everything the e2e
 * specs assert against is created here — if a spec needs new content, add it here
 * rather than creating it from the test, so the fixture stays one readable object.
 *
 * Fixture IDs are printed as JSON on the last line; the Playwright global setup
 * reads that so specs never hard-code post IDs.
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/image.php';

const SEED_AUTHOR_LOGIN = 'soames_e2e_author';
const SEED_BLOCKS_SLUG  = 'soames-e2e-all-blocks';
const SEED_HERO_SLUG    = 'soames-e2e-hero-page';
const SEED_PLAIN_SLUG   = 'soames-e2e-plain-user-post';
const SEED_DOCS_PARENT  = 'soames-e2e-guide';

const SEED_PREFIX = 'soames-e2e-';

/**
 * Delete every fixture this seeder owns, identified by the `soames-e2e-` slug prefix.
 *
 * Purge-then-create rather than upsert, because upserting can't be made reliable:
 * when a slug collides, WordPress silently appends `-2`, `-3`, … so the duplicate is
 * no longer findable by the slug the seeder knows. That's how a re-run left 9 docs
 * children behind instead of 3. Purging is unambiguous and lets a stale database
 * self-heal; fixture IDs change per run, which is why specs read them from JSON.
 */
function seed_purge(): void {
    foreach ( [ 'post', 'page', 'docs', 'attachment' ] as $type ) {
        $ids = get_posts( [
            'post_type'        => $type,
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ] );
        foreach ( $ids as $id ) {
            if ( 0 === strpos( (string) get_post_field( 'post_name', $id ), SEED_PREFIX ) ) {
                wp_delete_post( (int) $id, true );
            }
        }
    }
}

/** Create a solid-colour PNG attachment through the normal upload pipeline. */
function seed_attachment( string $filename, array $rgb, int $size = 400 ): int {
    $im = imagecreatetruecolor( $size, $size );
    imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
    ob_start();
    imagepng( $im );
    $bytes = ob_get_clean();
    imagedestroy( $im );

    $upload = wp_upload_bits( $filename, null, $bytes );
    if ( ! empty( $upload['error'] ) ) {
        fwrite( STDERR, "seed: upload failed for $filename: {$upload['error']}\n" );
        return 0;
    }
    $id = wp_insert_attachment( [
        'post_mime_type' => 'image/png',
        'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
        'post_status'    => 'inherit',
    ], $upload['file'] );
    wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
    return (int) $id;
}

/** Create a fixture post. Everything was purged first, so this is a plain insert. */
function seed_post( array $args ): int {
    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) {
        fwrite( STDERR, "seed: failed to insert {$args['post_name']}: " . $id->get_error_message() . "\n" );
        return 0;
    }
    return (int) $id;
}

seed_purge();

// ── Author with a local profile picture (ORBI-53) ─────────────────────────────

$avatar_id = seed_attachment( 'soames-e2e-avatar.png', [ 30, 90, 200 ] );
$hero_id   = seed_attachment( 'soames-e2e-hero.png', [ 200, 80, 40 ], 1200 );

$author = get_user_by( 'login', SEED_AUTHOR_LOGIN );
$author_id = $author ? $author->ID : wp_insert_user( [
    'user_login' => SEED_AUTHOR_LOGIN,
    'user_email' => 'soames-e2e-author@example.com',
    'user_pass'  => wp_generate_password(),
    'role'       => 'author',
] );
wp_update_user( [
    'ID'           => $author_id,
    'first_name'   => 'Ada',
    'last_name'    => 'Lovelace',
    // Deliberately DIFFERENT from first_name so the display-name-vs-firstName
    // distinction (ORBI-53) is actually observable in assertions.
    'display_name' => 'Ada Lovelace',
    'description'  => 'writes about headless WordPress.',
] );
update_user_meta( $author_id, 'soames_avatar_id', $avatar_id );
update_user_meta( $author_id, 'soames_avatar_url', wp_get_attachment_image_url( $avatar_id, 'thumbnail' ) );

// A second author with NO local avatar, so the Gravatar fall-through is testable.
$plain = get_user_by( 'login', 'soames_e2e_plain' );
$plain_id = $plain ? $plain->ID : wp_insert_user( [
    'user_login' => 'soames_e2e_plain',
    'user_email' => 'soames-e2e-plain@example.com',
    'user_pass'  => wp_generate_password(),
    'role'       => 'author',
] );
wp_update_user( [ 'ID' => $plain_id, 'display_name' => 'Plain Author' ] );
delete_user_meta( $plain_id, 'soames_avatar_id' );
delete_user_meta( $plain_id, 'soames_avatar_url' );

// ── A post containing every Soames block ─────────────────────────────────────
//
// Dynamic blocks (save: null) serialize as self-closing block comments, which is
// exactly what the editor writes. Values are intentionally awkward where the
// contract is fragile: commas inside labels (the reason data-items JSON exists at
// all), an ampersand, and HTML inside the Feature/Text List content.

$items_json = wp_json_encode( [
    [ 'image' => wp_get_attachment_url( $avatar_id ), 'label' => 'One, with a comma', 'link' => '/one/', 'css' => '' ],
    [ 'image' => wp_get_attachment_url( $hero_id ),   'label' => 'Two & three',      'link' => '/two/', 'css' => 'extra' ],
] );
$text_items_json = wp_json_encode( [
    [ 'content' => 'First item with <strong>bold</strong> text' ],
    [ 'content' => 'Second item, with a comma' ],
] );

// The Feature block keeps its body in a `content` ATTRIBUTE (a TextareaControl of
// HTML — ORBI-43), not as inner block content. Every Soames block is dynamic
// (save: null), so the editor serializes them SELF-CLOSING; giving one inner HTML
// makes the editor flag "unexpected or invalid content" and the render_callback emits
// an empty body, because it reads the attribute. Encode the attrs so the HTML is
// escaped correctly inside the block comment's JSON.
$feature_attrs = wp_json_encode( [
    'content' => '<p>Feature body with <strong>markup</strong>.</p>',
    'image'   => 'https://example.com/f.jpg',
    'title'   => 'Feature Title',
    'css'     => 'feat',
] );

$blocks = <<<HTML
<!-- wp:soames/title-bar {"title":"Seeded Title Bar"} /-->

<!-- wp:soames/title-bar-lg {"title":"Big Title","subtitle":"Sub, with comma","background":"https://example.com/bg.jpg"} /-->

<!-- wp:soames/icon-list {"items":$items_json,"size":"medium"} /-->

<!-- wp:soames/gallery-menu {"items":$items_json,"layout":"compact"} /-->

<!-- wp:soames/feature $feature_attrs /-->

<!-- wp:soames/video {"link":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","title":"Video Title"} /-->

<!-- wp:soames/soundcloud {"bandName":"Band, The","siteLink":"https://example.com","playlistId":"123456","albumLink":"https://example.com/album","albumName":"Album & Name"} /-->

<!-- wp:soames/text-list {"items":$text_items_json} /-->

<!-- wp:soames/icon-list {"images":"https://example.com/a.png,https://example.com/b.png","labels":"Legacy A,Legacy B","links":"/a/,/b/","css":"legacy"} /-->

<!-- wp:soames/text-list {"content":"<p>Legacy text list body</p>"} /-->
HTML;

$blocks_post_id = seed_post( [
    'post_type'    => 'post',
    'post_name'    => SEED_BLOCKS_SLUG,
    'post_title'   => 'Seeded: all Soames blocks',
    'post_status'  => 'publish',
    'post_author'  => $author_id,
    'post_content' => $blocks,
    'post_excerpt' => 'Fixture post exercising every Soames block.',
] );

// A post by the avatar-less author, for the Gravatar fall-through assertions.
$plain_post_id = seed_post( [
    'post_type'    => 'post',
    'post_name'    => SEED_PLAIN_SLUG,
    'post_title'   => 'Seeded: plain author post',
    'post_status'  => 'publish',
    'post_author'  => $plain_id,
    'post_content' => '<!-- wp:paragraph --><p>No local avatar here.</p><!-- /wp:paragraph -->',
] );

// ── A page with every hero field set (ORBI-41/52) ────────────────────────────

$hero_page_id = seed_post( [
    'post_type'    => 'page',
    'post_name'    => SEED_HERO_SLUG,
    'post_title'   => 'Seeded: hero page',
    'post_status'  => 'publish',
    'post_content' => '<!-- wp:paragraph --><p>Hero fixture.</p><!-- /wp:paragraph -->',
    'post_excerpt' => 'Hero page excerpt.',
] );
update_post_meta( $hero_page_id, 'soames_hero_title', 'Seeded Hero <br>Title' );
update_post_meta( $hero_page_id, 'soames_hero_caption', '<em>Seeded</em> caption' );
update_post_meta( $hero_page_id, 'soames_overlay_opacity', '0.35' );
update_post_meta( $hero_page_id, 'soames_hero_bg_id', $hero_id );

// A second page with NO hero meta, so the "null when unset" contract is testable.
$bare_page_id = seed_post( [
    'post_type'   => 'page',
    'post_name'   => 'soames-e2e-bare-page',
    'post_title'  => 'Seeded: bare page',
    'post_status' => 'publish',
] );
foreach ( [ 'soames_hero_title', 'soames_hero_caption', 'soames_overlay_opacity', 'soames_hero_bg_id' ] as $k ) {
    delete_post_meta( $bare_page_id, $k );
}

// ── A docs tree with explicit ordering (ORBI-38) ─────────────────────────────

$docs_parent_id = seed_post( [
    'post_type'    => 'docs',
    'post_name'    => SEED_DOCS_PARENT,
    'post_title'   => 'Seeded Guide',
    'post_status'  => 'publish',
    'post_content' => '<!-- wp:paragraph --><p>Guide root.</p><!-- /wp:paragraph -->',
    'menu_order'   => 0,
] );

// Created in reverse alphabetical order on purpose: if anything sorts by title
// instead of menu_order, these come back backwards and the test catches it.
$docs_children = [];
foreach ( [ [ 'zulu', 'Zulu Child', 1 ], [ 'alpha', 'Alpha Child', 2 ], [ 'mike', 'Mike Child', 3 ] ] as [$slug, $title, $order] ) {
    $docs_children[ $slug ] = seed_post( [
        'post_type'    => 'docs',
        'post_name'    => "soames-e2e-$slug",
        'post_title'   => $title,
        'post_status'  => 'publish',
        'post_parent'  => $docs_parent_id,
        'post_content' => "<!-- wp:paragraph --><p>$title body.</p><!-- /wp:paragraph -->",
        'menu_order'   => $order,
    ] );
}

// Avatars must stay ON: WPGraphQL reports the avatar private when show_avatars is
// off and returns null (see includes/user-avatar.php). Individual specs toggle this
// and restore it; make sure the baseline is a known state.
update_option( 'show_avatars', 1 );

// WPGraphQL blocks __schema/__type for unauthenticated requests by default. The
// suite introspects to prove the hero fields are REGISTERED (not merely returning
// values), so opt in — this is a throwaway dev environment.
$gql_settings = get_option( 'graphql_general_settings', [] );
$gql_settings = is_array( $gql_settings ) ? $gql_settings : [];
$gql_settings['public_introspection_enabled'] = 'on';
update_option( 'graphql_general_settings', $gql_settings );

// Pretty permalinks, so front-end assertions can use slugs.
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

echo wp_json_encode( [
    'authorId'     => (int) $author_id,
    'plainId'      => (int) $plain_id,
    'avatarId'     => (int) $avatar_id,
    'heroImageId'  => (int) $hero_id,
    'blocksPostId' => (int) $blocks_post_id,
    'plainPostId'  => (int) $plain_post_id,
    'heroPageId'   => (int) $hero_page_id,
    'barePageId'   => (int) $bare_page_id,
    'docsParentId' => (int) $docs_parent_id,
    'docsChildren' => $docs_children,
    'blocksSlug'   => SEED_BLOCKS_SLUG,
    'heroSlug'     => SEED_HERO_SLUG,
] ) . "\n";
