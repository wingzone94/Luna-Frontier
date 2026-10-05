<?php
/**
 * Luminous Blocks oEmbed ハンドラ
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'node_register_block_category' ) ) {
    /**
     * Node 固有ブロックをまとめる「Node」ブロックカテゴリーをインサーターに追加する。
     */
    function node_register_block_category( $categories ) {
        foreach ( $categories as $category ) {
            if ( isset( $category['slug'] ) && 'node' === $category['slug'] ) {
                return $categories;
            }
        }
        array_unshift(
            $categories,
            [
                'slug'  => 'node',
                'title' => 'Luna',
                'icon'  => null,
            ]
        );
        return $categories;
    }
    add_filter( 'block_categories_all', 'node_register_block_category' );
}

if ( ! function_exists( 'node_register_m3_blocks' ) ) {
    function node_register_m3_blocks() {
        register_block_type('node/media-label', [
            'category'      => 'node',
            'editor_script' => 'node-editor-js',
            'editor_style'  => 'node-editor-css',
            'render_callback' => function($attributes) {
                return node_render_media_label($attributes['imageUrl'] ?? '', $attributes['labelText'] ?? 'AI生成');
            }
        ]);
        register_block_type('node/voting', [
            'category'      => 'node',
            'editor_script' => 'node-editor-js',
            'render_callback' => 'node_render_voting_refined'
        ]);
        register_block_type('node/sort-table', [
            'category'      => 'node',
            'editor_script' => 'node-editor-js',
            'render_callback' => 'node_render_sort_table_block'
        ]);
        register_block_type('node/embed', [
            'category'        => 'node',
            'editor_script'   => 'luminous-blocks-editor',
            'attributes'      => [
                'url' => [ 'type' => 'string', 'default' => '' ],
            ],
            'render_callback' => 'node_render_embed_block'
        ]);
        // node/notice — お知らせ / 注意 / 重要 / 補足 の静的コールアウトブロック。
        // 保存時に HTML 確定（render_callback なし）。フロントの配色はテーマの
        // src/styles/_notice.css（style.css にバンドル）が担当する。
        register_block_type('node/notice', [
            'category'      => 'node',
            'editor_script' => 'luminous-blocks-editor',
            'attributes'    => [
                'type'  => [ 'type' => 'string', 'default' => 'info' ],
                'title' => [ 'type' => 'string', 'default' => '' ],
                'shape' => [ 'type' => 'string', 'default' => 'rounded' ],
            ],
        ]);
    }
}

if ( ! function_exists( 'node_register_oembed_handlers' ) ) {
    function node_register_oembed_handlers() {
        wp_embed_register_handler('spotify', '#https?://open\.spotify\.com/(track|album|playlist|artist)/[a-zA-Z0-9]+#', function($matches) {
            return node_spotify_shortcode(['url' => $matches[0]]);
        });
        wp_embed_register_handler('apple-music', '#https?://music\.apple\.com/[a-z]{2}/(album|song|playlist)/[a-zA-Z0-9.-]+/[0-9]+#', function($matches) {
            return node_apple_music_shortcode(['url' => $matches[0]]);
        });
    }
}
