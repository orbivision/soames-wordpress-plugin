<?php
defined('ABSPATH') || exit;

add_filter('block_categories_all', 'soames_block_categories', 10, 2);

function soames_block_categories($categories) {
    return array_merge($categories, [
        ['slug' => 'soames', 'title' => 'Soames', 'icon' => null],
    ]);
}

add_action('init', 'soames_register_blocks');

function soames_register_blocks() {
    if (!function_exists('register_block_type')) {
        return;
    }
    soames_register_title_bar_block();
    soames_register_title_bar_lg_block();
    soames_register_icon_list_block();
    soames_register_feature_block();
    soames_register_gallery_menu_block();
    soames_register_video_block();
    soames_register_soundcloud_block();
    soames_register_text_list_block();

    add_action('enqueue_block_editor_assets', 'soames_enqueue_block_editor_assets');
}

function soames_enqueue_block_editor_assets() {
    // Ensure the media library frame is available for the Icon List image picker.
    wp_enqueue_media();

    $editor_js = SOAMES_PLUGIN_DIR . 'assets/js/soames-blocks.js';
    wp_enqueue_script(
        'soames-blocks-editor',
        SOAMES_PLUGIN_URL . 'assets/js/soames-blocks.js',
        ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'],
        // Version by file mtime so edits bust the browser cache automatically.
        file_exists($editor_js) ? filemtime($editor_js) : '1.0.0',
        true
    );
}

function soames_register_title_bar_block() {
    register_block_type('soames/title-bar', [
        'attributes' => [
            'title' => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attrs) {
            $title = esc_html($attrs['title'] ?? '');
            return '<div class="wp-block-soames-title-bar">' . $title . '</div>';
        },
    ]);
}

function soames_register_title_bar_lg_block() {
    register_block_type('soames/title-bar-lg', [
        'attributes' => [
            'title'      => ['type' => 'string', 'default' => ''],
            'subtitle'   => ['type' => 'string', 'default' => ''],
            'background' => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attrs) {
            return '<div class="wp-block-soames-title-bar-lg"'
                . ' data-title="'      . esc_attr($attrs['title']      ?? '') . '"'
                . ' data-subtitle="'   . esc_attr($attrs['subtitle']   ?? '') . '"'
                . ' data-background="' . esc_attr($attrs['background'] ?? '') . '">'
                . '</div>';
        },
    ]);
}

function soames_register_icon_list_block() {
    register_block_type('soames/icon-list', [
        'attributes' => [
            // ORBI-20: grouped rows; each item = { image, label, link, css }
            'items'  => ['type' => 'array',  'default' => []],
            // legacy comma fields, kept so pre-ORBI-20 blocks still render
            'images' => ['type' => 'string', 'default' => ''],
            'labels' => ['type' => 'string', 'default' => ''],
            'links'  => ['type' => 'string', 'default' => ''],
            'css'    => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attrs) {
            $items = $attrs['items'] ?? [];
            if (!empty($items) && is_array($items)) {
                // New format: JSON in data-items (comma-safe).
                return '<div class="wp-block-soames-icon-list"'
                    . ' data-items="' . esc_attr(wp_json_encode($items)) . '">'
                    . '</div>';
            }
            // Legacy fallback: positional comma-separated attributes.
            return '<div class="wp-block-soames-icon-list"'
                . ' data-images="' . esc_attr($attrs['images'] ?? '') . '"'
                . ' data-labels="' . esc_attr($attrs['labels'] ?? '') . '"'
                . ' data-links="'  . esc_attr($attrs['links']  ?? '') . '"'
                . ' data-css="'    . esc_attr($attrs['css']    ?? '') . '">'
                . '</div>';
        },
    ]);
}

function soames_register_feature_block() {
    register_block_type('soames/feature', [
        'attributes' => [
            'content' => ['type' => 'string', 'default' => ''],
            'image'   => ['type' => 'string', 'default' => ''],
            'title'   => ['type' => 'string', 'default' => ''],
            'css'     => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attrs) {
            $content = wp_kses_post($attrs['content'] ?? '');
            return '<div class="wp-block-soames-feature"'
                . ' data-image="' . esc_attr($attrs['image'] ?? '') . '"'
                . ' data-title="' . esc_attr($attrs['title'] ?? '') . '"'
                . ' data-css="'   . esc_attr($attrs['css']   ?? '') . '">'
                . $content
                . '</div>';
        },
    ]);
}

function soames_register_gallery_menu_block() {
    register_block_type('soames/gallery-menu', [
        'attributes' => [
            // ORBI-20: grouped rows; each item = { image, label, link, css }
            'items'  => ['type' => 'array',  'default' => []],
            // legacy comma fields, kept so pre-ORBI-20 blocks still render
            'images' => ['type' => 'string', 'default' => ''],
            'labels' => ['type' => 'string', 'default' => ''],
            'links'  => ['type' => 'string', 'default' => ''],
            'css'    => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attrs) {
            $items = $attrs['items'] ?? [];
            if (!empty($items) && is_array($items)) {
                return '<div class="wp-block-soames-gallery-menu"'
                    . ' data-items="' . esc_attr(wp_json_encode($items)) . '">'
                    . '</div>';
            }
            return '<div class="wp-block-soames-gallery-menu"'
                . ' data-images="' . esc_attr($attrs['images'] ?? '') . '"'
                . ' data-labels="' . esc_attr($attrs['labels'] ?? '') . '"'
                . ' data-links="'  . esc_attr($attrs['links']  ?? '') . '"'
                . ' data-css="'    . esc_attr($attrs['css']    ?? '') . '">'
                . '</div>';
        },
    ]);
}

function soames_register_video_block() {
    register_block_type('soames/video', [
        'attributes' => [
            'link'  => ['type' => 'string', 'default' => ''],
            'title' => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attrs) {
            return '<div class="wp-block-soames-video"'
                . ' data-link="'  . esc_attr($attrs['link']  ?? '') . '"'
                . ' data-title="' . esc_attr($attrs['title'] ?? '') . '">'
                . '</div>';
        },
    ]);
}

function soames_register_soundcloud_block() {
    register_block_type('soames/soundcloud', [
        'attributes' => [
            'bandName'   => ['type' => 'string', 'default' => ''],
            'siteLink'   => ['type' => 'string', 'default' => ''],
            'playlistId' => ['type' => 'string', 'default' => ''],
            'albumLink'  => ['type' => 'string', 'default' => ''],
            'albumName'  => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attrs) {
            return '<div class="wp-block-soames-soundcloud"'
                . ' data-band-name="'   . esc_attr($attrs['bandName']   ?? '') . '"'
                . ' data-site-link="'   . esc_attr($attrs['siteLink']   ?? '') . '"'
                . ' data-playlist-id="' . esc_attr($attrs['playlistId'] ?? '') . '"'
                . ' data-album-link="'  . esc_attr($attrs['albumLink']  ?? '') . '"'
                . ' data-album-name="'  . esc_attr($attrs['albumName']  ?? '') . '">'
                . '</div>';
        },
    ]);
}

function soames_register_text_list_block() {
    register_block_type('soames/text-list', [
        'attributes' => [
            'content' => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attrs) {
            $content = wp_kses_post($attrs['content'] ?? '');
            return '<div class="wp-block-soames-text-list">' . $content . '</div>';
        },
    ]);
}
