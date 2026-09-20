<?php

declare(strict_types=1);
/**
 * 商品リンクのURLを生成（将来的にアフィリエイトIDを付与しやすいよう独立）
 */
function node_generate_product_link($url, $type = 'amazon') {
    if (empty($url)) return '';
    return esc_url($url);
}

/**
 * 商品リンク ショートコード [m3_product]
 * [product_card] へ一本化済み。後方互換性のためエイリアスとして維持。
 * 既存記事で [m3_product] を使用していても正常に動作します。
 */
add_shortcode('m3_product', 'node_product_card_shortcode');
// --- ユーティリティ ---

function node_get_total_published_posts(): int {
    $cached = get_transient('node_total_published_posts');

    if (false !== $cached) {
        return (int) $cached;
    }

    $posts_query = new WP_Query(
        array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
            'posts_per_page'      => 1,
            'fields'              => 'ids',
        )
    );
    $total       = (int) $posts_query->found_posts;

    set_transient('node_total_published_posts', $total, 12 * HOUR_IN_SECONDS);

    return $total;
}

function node_delete_total_published_posts_transient(...$args): void {
    delete_transient('node_total_published_posts');
}

add_action('save_post', 'node_delete_total_published_posts_transient');
add_action('deleted_post', 'node_delete_total_published_posts_transient');
add_action('transition_post_status', 'node_delete_total_published_posts_transient');

function node_get_relative_date($post_id = null) {
	$resolved_post_id = (int) ( $post_id ?: get_the_ID() );

	return \NodeTheme\Controllers\TemplateController::getRelativeDate( $resolved_post_id );
}

/**
 * 追記日（最終更新日）の表示情報を返す。
 *
 * 手動メタ（_node_manual_modified_date、保存時の自動記録含む）があれば
 * 公開日と同日でも表示する（公開後数時間での訂正・追記を当日中に開示するため）。
 * メタがない場合は更新日が公開日と異なる日のときのみ表示する。
 *
 * display_short はカード等の狭い場所向けの短縮表記（公開年と同じ年なら年を省く）。
 *
 * @return array{datetime: string, display: string, display_short: string}|null 表示不要なら null
 */
function node_get_post_modified_display($post_id = null) {
	$resolved_post_id = (int) ( $post_id ?: get_the_ID() );

	return \NodeTheme\Controllers\TemplateController::getPostModifiedDisplay( $resolved_post_id );
}

function node_get_image_seed_color($attachment_id) {
    if (!$attachment_id) return null;
    $cached = get_post_meta($attachment_id, '_node_seed_color', true);
    if ($cached) return $cached;

    $file_path = get_attached_file($attachment_id);
    if (!$file_path || !file_exists($file_path)) return null;

    $info = getimagesize($file_path);
    if (!$info) return null;

    $image = null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $image = imagecreatefromjpeg($file_path); break;
        case IMAGETYPE_PNG:  $image = imagecreatefrompng($file_path); break;
        case IMAGETYPE_WEBP: $image = imagecreatefromwebp($file_path); break;
        case IMAGETYPE_GIF:  $image = imagecreatefromgif($file_path); break;
    }
    if (!$image) return null;

    $pixel = imagecreatetruecolor(1, 1);
    imagecopyresampled($pixel, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
    $rgb = imagecolorat($pixel, 0, 0);
    $hex = sprintf("#%02x%02x%02x", ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);

    update_post_meta($attachment_id, '_node_seed_color', $hex);
    imagedestroy($image);
    imagedestroy($pixel);
    return $hex;
}

function node_get_readable_text_color($hex_color) {
    $hex_color = sanitize_hex_color($hex_color);
    if (!$hex_color) {
        return '#ffffff';
    }

    $hex = ltrim($hex_color, '#');
    if (3 === strlen($hex)) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    $red   = hexdec(substr($hex, 0, 2));
    $green = hexdec(substr($hex, 2, 2));
    $blue  = hexdec(substr($hex, 4, 2));
    $yiq   = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

    return $yiq >= 150 ? '#2b1700' : '#ffffff';
}

function node_mix_hex_color($hex_color, $target_color = '#ffffff', $target_ratio = 0.5) {
    $hex_color    = sanitize_hex_color($hex_color);
    $target_color = sanitize_hex_color($target_color);

    if (!$hex_color || !$target_color) {
        return $hex_color ?: '#FF9900';
    }

    $target_ratio = max(0, min(1, (float) $target_ratio));
    $source_ratio = 1 - $target_ratio;

    $source = ltrim($hex_color, '#');
    $target = ltrim($target_color, '#');

    if (3 === strlen($source)) {
        $source = $source[0] . $source[0] . $source[1] . $source[1] . $source[2] . $source[2];
    }
    if (3 === strlen($target)) {
        $target = $target[0] . $target[0] . $target[1] . $target[1] . $target[2] . $target[2];
    }

    $mixed = [];
    for ($i = 0; $i < 3; $i++) {
        $source_value = hexdec(substr($source, $i * 2, 2));
        $target_value = hexdec(substr($target, $i * 2, 2));
        $mixed[] = (int) round(($source_value * $source_ratio) + ($target_value * $target_ratio));
    }

    return sprintf('#%02x%02x%02x', $mixed[0], $mixed[1], $mixed[2]);
}

function node_get_category_color($term) {
    $term_id = is_object($term) && isset($term->term_id) ? (int) $term->term_id : absint($term);
    if (!$term_id) {
        return '';
    }

    $saved_color = sanitize_hex_color(get_term_meta($term_id, '_m3_color', true));
    if ($saved_color) {
        return $saved_color;
    }

    $term_object = is_object($term) ? $term : get_term($term_id, 'category');
    if ($term_object && !is_wp_error($term_object) && !empty($term_object->description)) {
        if (preg_match('/#[a-fA-F0-9]{6}/', $term_object->description, $matches)) {
            $legacy_color = sanitize_hex_color($matches[0]);
            if ($legacy_color) {
                return $legacy_color;
            }
        }
    }

    return '';
}

function node_is_default_category_color($hex_color) {
    $hex_color = sanitize_hex_color($hex_color);

    return $hex_color && 0 === strcasecmp($hex_color, '#FF9900');
}

function node_get_category_label_props($term) {
    $custom_color = node_get_category_color($term);
    $is_default   = empty($custom_color) || node_is_default_category_color($custom_color);
    $color        = $is_default ? '#FF9900' : $custom_color;

    return array(
        'color'      => $color,
        'on_color'   => node_get_readable_text_color($color),
        'data_color' => $is_default ? '' : $color,
    );
}

function node_get_category_label_style($term_or_props) {
    $props = is_array($term_or_props) ? $term_or_props : node_get_category_label_props($term_or_props);

    return sprintf(
        '--category-color:%s;--category-on-color:%s;',
        esc_attr($props['color']),
        esc_attr($props['on_color'])
    );
}

function node_render_category_label($term, $args = array()) {
    $term = is_object($term) ? $term : get_term(absint($term), 'category');
    if (!$term || is_wp_error($term)) {
        return '';
    }

    $args = wp_parse_args(
        $args,
        array(
            'tag'   => 'a',
            'class' => 'm3-label--category',
            'href'  => true,
        )
    );

    $tag = in_array($args['tag'], array('a', 'span'), true) ? $args['tag'] : 'span';
    $label_props = node_get_category_label_props($term);
    $attrs = array(
        'class'            => trim($args['class']),
        'data-category-id' => $term->term_id,
        'style'            => node_get_category_label_style($label_props),
    );

    if (!empty($label_props['data_color'])) {
        $attrs['data-color'] = $label_props['data_color'];
    }

    if ('a' === $tag && $args['href']) {
        $attrs['href'] = get_category_link($term->term_id);
    }

    $attr_html = '';
    foreach ($attrs as $name => $value) {
        if ('' === $value || null === $value) {
            continue;
        }

        $attr_html .= ' ' . esc_attr($name) . '="';
        $attr_html .= 'href' === $name ? esc_url($value) : esc_attr($value);
        $attr_html .= '"';
    }

    return '<' . $tag . $attr_html . '>' . esc_html($term->name) . '</' . $tag . '>';
}
/**
 * 投稿・カテゴリのカラー設定を優先し、M3シードカラーを動的に生成する。
 * 優先順位: 投稿個別カラー > カテゴリカラー > アイキャッチ画像抽出色 > デフォルト
 * API は使用しない（保存済みメタから読み込むのみ）
 */
function node_generate_m3_colors() {
    // デフォルトカラー（Luminous Core ブランドカラー）
    $default_primary      = '#FF9900';
    $default_primary_dark = '#ffb85d';

    $seed_color      = '';
    $seed_color_dark = '';
    $article_accent      = $default_primary;
    $article_accent_dark = $default_primary_dark;

    // 個別記事ページおよび固定ページ: 投稿メタ → カテゴリメタ → アイキャッチ抽出色 の順に解決
    if (is_singular(['post', 'page'])) {
        $post_id = get_the_ID();

        // 1. 投稿個別カラー
        $post_color = get_post_meta($post_id, '_m3_primary_color', true);
        if (!empty($post_color)) {
            $seed_color = sanitize_hex_color($post_color);
            if (!empty($seed_color)) {
                $article_accent = $seed_color;
                $article_accent_dark = node_mix_hex_color($seed_color, '#ffffff', 0.36);
            }
        }

        // 2. カテゴリカラー
        if (empty($seed_color)) {
            $categories = node_get_post_categories_for_display( $post_id );
            if (!empty($categories)) {
                $cat_color = node_get_category_color($categories[0]);
                if (!empty($cat_color)) {
                    $seed_color = sanitize_hex_color($cat_color);
                }
            }
        }

        // 3. アイキャッチ画像から抽出した色
        if (empty($seed_color)) {
            $thumb_id = get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                $extracted = node_get_image_seed_color($thumb_id);
                if (!empty($extracted)) {
                    $seed_color = sanitize_hex_color($extracted);
                }
            }
        }
    }

    // アーカイブページ: カテゴリカラーを使用
    if (is_category()) {
        $cat_color = node_get_category_color(get_queried_object_id());
        if (!empty($cat_color)) {
            $seed_color = sanitize_hex_color($cat_color);
        }
    }

    // フォールバック: デフォルトカラー
    if (empty($seed_color)) {
        $seed_color = $default_primary;
    }
    if (empty($seed_color_dark)) {
        $seed_color_dark = node_mix_hex_color($seed_color ?: $default_primary, '#ffffff', 0.36);
    }
    $on_primary                 = node_get_readable_text_color($seed_color);
    $primary_container          = node_mix_hex_color($seed_color, '#fff4e5', 0.78);
    $on_primary_container       = node_get_readable_text_color($primary_container);
    $dark_on_primary            = node_get_readable_text_color($seed_color_dark);
    $dark_primary_container     = node_mix_hex_color($seed_color, '#1e1b16', 0.54);
    $dark_on_primary_container  = node_get_readable_text_color($dark_primary_container);
    $article_on_accent          = node_get_readable_text_color($article_accent);
    $article_accent_container   = node_mix_hex_color($article_accent, '#fff4e5', 0.78);
    $article_on_accent_container = node_get_readable_text_color($article_accent_container);
    $article_on_accent_dark     = node_get_readable_text_color($article_accent_dark);
    $article_accent_container_dark = node_mix_hex_color($article_accent, '#1e1b16', 0.54);
    $article_on_accent_container_dark = node_get_readable_text_color($article_accent_container_dark);
    ?>
    <style id="m3-dynamic-colors">
        /*
         * ここに出すのは「PHP でしか決まらない動的トークン」だけに限る（R2 で整理）。
         * surface / on-surface / surface-container-* / outline 系は色が固定なので
         * 正本は src/styles/_variables.css。以前は両方に定義があり、
         * このインラインが後勝ちで CSS 側を潰していたため、
         * _variables.css を編集しても色が変わらないという状態になっていた。
         * 静的な色をこのブロックへ戻さないこと。
         */
        :root {
            --md-sys-color-primary: <?php echo esc_attr($seed_color); ?>;
            --md-sys-color-on-primary: <?php echo esc_attr($on_primary); ?>;
            --md-sys-color-primary-container: <?php echo esc_attr($primary_container); ?>;
            --md-sys-color-on-primary-container: <?php echo esc_attr($on_primary_container); ?>;
            --node-article-accent: <?php echo esc_attr($article_accent); ?>;
            --node-article-on-accent: <?php echo esc_attr($article_on_accent); ?>;
            --node-article-accent-container: <?php echo esc_attr($article_accent_container); ?>;
            --node-article-on-accent-container: <?php echo esc_attr($article_on_accent_container); ?>;
        }
        [data-theme="dark"] {
            --md-sys-color-primary: <?php echo esc_attr($seed_color_dark); ?>;
            --md-sys-color-on-primary: <?php echo esc_attr($dark_on_primary); ?>;
            --md-sys-color-primary-container: <?php echo esc_attr($dark_primary_container); ?>;
            --md-sys-color-on-primary-container: <?php echo esc_attr($dark_on_primary_container); ?>;
            --node-article-accent: <?php echo esc_attr($article_accent_dark); ?>;
            --node-article-on-accent: <?php echo esc_attr($article_on_accent_dark); ?>;
            --node-article-accent-container: <?php echo esc_attr($article_accent_container_dark); ?>;
            --node-article-on-accent-container: <?php echo esc_attr($article_on_accent_container_dark); ?>;
        }
    </style>
    <?php
}
add_action('wp_head', 'node_generate_m3_colors');

/**
 * 表示用カテゴリから祖先カテゴリを除外する。
 * 例: SPOTLIGHT 親と BLEACH 子が両方付与されている場合、子のみ残す。
 *
 * @param array<int, WP_Term> $categories Post categories.
 * @return array<int, WP_Term>
 */
function node_deduplicate_post_categories( $categories ) {
    if ( empty( $categories ) || ! is_array( $categories ) ) {
        return array();
    }

    $categories = array_values(
        array_filter(
            $categories,
            static function ( $term ) {
                return $term && ! is_wp_error( $term ) && isset( $term->term_id );
            }
        )
    );

    if ( count( $categories ) <= 1 ) {
        return $categories;
    }

    $filtered = array();

    foreach ( $categories as $cat ) {
        $is_ancestor = false;

        foreach ( $categories as $other ) {
            if ( (int) $cat->term_id === (int) $other->term_id ) {
                continue;
            }

            if ( term_is_ancestor_of( (int) $cat->term_id, (int) $other->term_id, 'category' ) ) {
                $is_ancestor = true;
                break;
            }
        }

        if ( ! $is_ancestor ) {
            $filtered[] = $cat;
        }
    }

    return $filtered;
}

/**
 * 記事に付与されたカテゴリのうち、フロント表示用に整理した一覧を返す。
 *
 * @param int|null $post_id Post ID.
 * @return array<int, WP_Term>
 */
function node_get_post_categories_for_display( $post_id = null ) {
    $post_id = $post_id ? (int) $post_id : (int) get_the_ID();
    if ( ! $post_id ) {
        return array();
    }

    $assigned_categories = get_the_category( $post_id );
    $categories          = node_deduplicate_post_categories( $assigned_categories );
    $primary_id = absint( get_post_meta( $post_id, '_node_primary_category', true ) );
    if ( ! $primary_id ) {
        return $categories;
    }

    foreach ( $categories as $index => $category ) {
        if ( (int) $category->term_id !== $primary_id ) {
            continue;
        }

        if ( 0 === $index ) {
            return $categories;
        }

        unset( $categories[ $index ] );
        array_unshift( $categories, $category );
        return array_values( $categories );
    }

    foreach ( $assigned_categories as $category ) {
        if ( ! $category || is_wp_error( $category ) || ! isset( $category->term_id ) ) {
            continue;
        }

        if ( (int) $category->term_id === $primary_id ) {
            array_unshift( $categories, $category );
            return array_values( $categories );
        }
    }

    return $categories;
}

function node_get_primary_category( $post_id = null ): ?WP_Term {
    $post_id = $post_id ? (int) $post_id : (int) get_the_ID();
    if ( ! $post_id ) {
        return null;
    }

    $assigned_categories = get_the_category( $post_id );
    $categories          = node_deduplicate_post_categories( $assigned_categories );
    if ( empty( $categories ) ) {
        return null;
    }

    $primary_id = absint( get_post_meta( $post_id, '_node_primary_category', true ) );
    if ( $primary_id ) {
        foreach ( $assigned_categories as $category ) {
            if ( ! $category || is_wp_error( $category ) || ! isset( $category->term_id ) ) {
                continue;
            }

            if ( (int) $category->term_id === $primary_id ) {
                return $category;
            }
        }
    }

    return $categories[0];
}

function node_the_category_labels($post_id = null) {
    if (!$post_id) $post_id = get_the_ID();
    $categories = node_get_post_categories_for_display( $post_id );
    if (empty($categories)) return;
    
    $is_card = !is_single();
    // カード・シングルとも初期表示は3つまで。超過分は +N バッジ（index.phpと同仕様）。
    // シングルでは +N がトグルボタンになり、クリックで全カテゴリを表示する。
    $limit = 3;
    $count = count($categories);
    $display_cats = $is_card ? array_slice($categories, 0, $limit) : $categories;

    echo '<div class="m3-article__category-group' . ($is_card ? ' is-card' : '') . '">';
    if (!$is_card) {
        // PC(1001px〜)ではCSSで文字を隠しアイコン(📁)のみ表示。ホバー/フォーカスで
        // 上方向に「この記事に属しているカテゴリ表記です」の吹き出しを出す。
        echo '<span class="m3-article__category-label" tabindex="0" aria-label="CATEGORY: この記事に属しているカテゴリ表記です">'
            . '<span class="material-symbols-outlined" aria-hidden="true">folder</span>'
            . '<span class="m3-article__category-label-text">CATEGORY</span>'
            . '<span class="m3-category-info-bubble" role="tooltip" aria-hidden="true">この記事に属しているカテゴリ表記です</span>'
            . '</span>';
    }

    foreach ($display_cats as $index => $cat) {
        // シングルヒーローでは先頭（プライマリ）のみ塗り、以降はアウトラインピル
        $label_class = 'm3-label--category';
        if (!$is_card && $index > 0) {
            $label_class .= ' is-secondary';
        }
        if (!$is_card && $index >= $limit) {
            $label_class .= ' is-overflow';
        }
        echo node_render_category_label($cat, array('class' => $label_class));
    }

    if ($count > $limit) {
        $remaining = $count - $limit;
        if ($is_card) {
            echo '<span class="m3-label--category-more" title="さらに ' . $remaining . ' 件のカテゴリがあります">+' . $remaining . '</span>';
        } else {
            echo '<button type="button" class="m3-label--category-more m3-label--category-more--toggle" aria-expanded="false" title="残り ' . $remaining . ' 件のカテゴリを表示">+' . $remaining . '</button>';
        }
    }
    echo '</div>';
}

function node_render_tag_chip($tag) {
    $tag = is_object($tag) ? $tag : get_term(absint($tag), 'post_tag');
    if (!$tag || is_wp_error($tag)) {
        return '';
    }

    return sprintf(
        '<a href="%s" class="m3-filter-chip m3-article__tag-chip">#%s</a>',
        esc_url(get_tag_link($tag->term_id)),
        esc_html($tag->name)
    );
}

function node_the_tag_labels($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $post_tags = get_the_tags($post_id);
    if (!$post_tags) {
        return;
    }

    echo '<div class="m3-article__tags">';
    echo '<span class="m3-article__footer-label">';
    echo '<span class="material-symbols-outlined" aria-hidden="true">sell</span>';
    echo 'TAGS';
    echo '</span>';

    foreach ($post_tags as $tag) {
        echo node_render_tag_chip($tag);
    }

    echo '</div>';
}

function node_the_post_badges($post_id = null, $mode = 'compact', $types = ['ai', 'sponsor']) {
    if (!$post_id) $post_id = get_the_ID();
    
    // --- AI生成コンテンツの統合判定 ---
    $has_ai_media = get_post_meta($post_id, '_node_is_ai_generated', true) === '1';
    $has_ai_text  = get_post_meta($post_id, '_node_is_ai_text_generated', true) === '1';

    if (in_array('ai', $types) && ($has_ai_media || $has_ai_text)) {
        // 文言の決定
        if ($has_ai_media && $has_ai_text) {
            $ai_label = '生成されたメディア・テキストを含む';
        } elseif ($has_ai_text) {
            $ai_label = '生成された文章を含む';
        } else {
            $ai_label = '生成されたメディアを含む';
        }

        $ai_class = 'm3-label--ai m3-tooltip-target';
        if (!$has_ai_media && $has_ai_text) {
            $ai_class .= ' is-text-only';
        }

        $pos_attr = '';
        if ($mode === 'compact') {
            $ai_class .= ' m3-label--icon-only';
            $pos_attr = ' data-tooltip-pos="left"';
        } elseif ($mode === 'expressive') {
            $ai_class .= ' m3-article__ai-disclosure-expressive';
        }

        echo '<span class="' . esc_attr($ai_class) . '" data-tooltip="' . esc_attr($ai_label) . '" title="' . esc_attr($ai_label) . '" aria-label="' . esc_attr($ai_label) . '"' . $pos_attr . '>';
        if ($mode === 'expressive') {
            echo '<span class="m3-ai-disclosure__glow"></span>';
            echo '<span class="material-symbols-outlined m3-ai-disclosure__icon">auto_awesome</span>';
            echo '<span class="m3-ai-disclosure__text">' . esc_html($ai_label) . '</span>';
        } else {
            echo '<span class="material-symbols-outlined">auto_awesome</span>';
            if ($mode === 'full') {
                echo '<span class="m3-label__text">' . esc_html($ai_label) . '</span>';
            }
        }
        echo '</span>';
    }

    // スポンサーラベル
    if (in_array('sponsor', $types) && get_post_meta($post_id, '_node_is_sponsor', true) === '1') {
        $sponsor_text = get_post_meta($post_id, '_node_sponsor_text', true) ?: 'SPONSORED';
        
        if ($mode === 'full') {
            $sponsor_tooltip = get_post_meta($post_id, '_node_sponsor_tooltip', true) ?: 'この記事はスポンサー提供です。';
        } else {
            $sponsor_tooltip = 'この記事はスポンサー提供です。';
        }
        
        $sp_class = 'm3-label--sponsor m3-tooltip-target';
        $pos_attr = '';
        if ($mode === 'compact') {
            $sp_class .= ' m3-label--icon-only';
            $pos_attr = ' data-tooltip-pos="left"';
        }

        echo '<span class="' . esc_attr($sp_class) . '" data-tooltip="' . esc_attr($sponsor_tooltip) . '"' . $pos_attr . '>';
        echo '<span class="material-symbols-outlined">info</span>';
        if ($mode === 'full') {
            echo '<span class="m3-label__text">' . esc_html($sponsor_text) . '</span>';
        }
        echo '</span>';
    }
}

/**
 * カード右上に表示する、シリーズの現在回/全話数バナーを出力する。
 */
function node_the_series_banner($post_id = null, $extra_class = '') {
    if (!$post_id) $post_id = get_the_ID();
    if (!function_exists('node_series_get_position')) return;

    $series_position = node_series_get_position($post_id);
    if (!$series_position) return;

    $label = 'シリーズ「' . $series_position['term']->name . '」第' . $series_position['index'] . '回（全' . $series_position['total'] . '回）';
    $class = trim('m3-card__series-banner ' . $extra_class);
    $color = function_exists('node_series_get_color') ? node_series_get_color($post_id) : null;
    $style = $color ? ' style="--node-series-color: ' . esc_attr($color) . ';"' : '';

    echo '<span class="' . esc_attr($class) . '"' . $style . ' title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '">';
    echo '<span class="material-symbols-outlined" aria-hidden="true">auto_stories</span>';
    echo '<span>' . esc_html($series_position['index'] . ' / ' . $series_position['total']) . '</span>';
    echo '</span>';
}

/**
 * AI要約の短縮版を取得する
 */
function node_get_short_ai_summary($text, $length = 80) {
    if (empty($text)) return '';
    $text = strip_tags($text);
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . '...';
}

/* ==========================================================================
   広告エリアの制御
   ========================================================================== */
function node_the_ad_area($position) {
    // 広告コードは将来的に設定画面等から取得できるようにオプション設定を使用
    $ad_code = get_option('node_ad_code_' . $position, '');

    // 広告タグが設定されていない場合は非表示（出力しない）
    if (empty(trim($ad_code))) {
        return;
    }
    
    echo '<div class="m3-ad-area m3-ad-area--' . esc_attr($position) . '">' . do_shortcode($ad_code) . '</div>';
}

/**
 * template-parts/ad-{$slug}.php を、中身が実在するときだけ出力する。
 *
 * これらのテンプレートは「ここに AdSense のコードを貼る」用のひな形で、
 * 貼る前は HTML コメントしか入っていない。それでも `.m3-ad-area` の
 * 破線ボックス（min-height:120px）は描画されるため、未設定のサイトでは
 * フッター直前に空の枠だけが残る。読者が記事一覧を読み終えて次の行き先を
 * 探す位置にちょうど居座るので、回遊の妨げになる（2026-08-21 実機録画で確認）。
 *
 * オプション（node_the_ad_area）と違い、テンプレートに直接コードを
 * 貼り付ける運用も残したいので、出力を捕まえてから中身の有無で判定する。
 */
function node_the_ad_template($slug) {
    ob_start();
    get_template_part('template-parts/ad', $slug);
    $markup = (string) ob_get_clean();

    // HTML コメントを除いて何も残らなければ、ひな形のままとみなして出力しない。
    $content = preg_replace('/<!--.*?-->/s', '', $markup);
    if (trim(wp_strip_all_tags((string) $content)) === '' && !preg_match('/<(ins|iframe|img|script|amp-ad)\b/i', (string) $content)) {
        return;
    }

    echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- テンプレート由来の広告タグ
}

/* ==========================================================================
   記事の文字数と読了目安ランクの取得
   ========================================================================== */


/**
 * Engineが管理する公開記事の文字数分布を返す。
 *
 * @return array{count: int, median: int, p90: int}
 */
function node_get_article_length_distribution(): array {
	$engine_api = 'LuminousCore\\Engine\\get_article_length_distribution';

	if ( ! function_exists( $engine_api ) ) {
		return array( 'count' => 0, 'median' => 0, 'p90' => 0 );
	}

	return $engine_api();
}

/**
 * 文字数をブログ分布上の0〜100進捗へ変換する。
 * 中央値を60%、p90を100%に固定し、外れ値による基準の乱高下を防ぐ。
 */
function node_get_relative_article_length_progress(int $chars, array $distribution): int {
    $median = max(1, (int) ($distribution['median'] ?? 0));
    $p90 = max($median + 1, (int) ($distribution['p90'] ?? 0));

    if ($chars <= $median) {
        return max(0, min(60, (int) round(($chars / $median) * 60)));
    }

    return max(60, min(100, (int) round(60 + (($chars - $median) / ($p90 - $median)) * 40)));
}

/**
 * 記事の文字数と読了目安ランクを取得する (v0.9.1 Logic)
 */
function node_get_article_ranking_info($post_id = null) {
    if (!$post_id) $post_id = get_the_ID();
    $post_id = (int) $post_id;
    $metrics_api = 'LuminousCore\\Engine\\get_article_metrics';

    $article_metrics = function_exists($metrics_api)
        ? $metrics_api($post_id)
        : ['chars' => 0, 'reading' => 1, 'reading_seconds' => 30];
    $chars = (int) $article_metrics['chars'];
    /* 母数不足時は1.2.5の絶対閾値へ退避し、少数記事で基準が振れるのを防ぐ。 */
    $rank_thresholds = [
        'short'          => 1500,   // 〜1,500字: 短い
        'somewhat_short' => 3000,   // 〜3,000字: やや短い
        'standard'       => 6000,   // 〜6,000字: 標準
        'somewhat_long'  => 10000,  // 〜10,000字: やや長い（それ以上は長い）
    ];

    $distribution = node_get_article_length_distribution();
    $uses_relative_baseline = $distribution['count'] >= 20
        // 極短の検証投稿が過半数を占める等、ブログ本文の分布として不健全な場合は退避する。
        && $distribution['median'] >= 500
        && $distribution['p90'] > $distribution['median']
        && $distribution['p90'] <= ($distribution['median'] * 10);

    if ($uses_relative_baseline) {
        $progress = node_get_relative_article_length_progress($chars, $distribution);
        // 旧5段階の代表進捗（20/40/60/80/100）の中間点で区切る。
        // 中央値=60%は「標準」となり、色・ラベル・ゲージが同じ基準を語る。
        if ($progress < 30) {
            $rank = 'short';
        } elseif ($progress < 50) {
            $rank = 'somewhat_short';
        } elseif ($progress < 70) {
            $rank = 'standard';
        } elseif ($progress < 90) {
            $rank = 'somewhat_long';
        } else {
            $rank = 'long';
        }
    } elseif ($chars < $rank_thresholds['short']) {
        $rank = 'short';
    } elseif ($chars < $rank_thresholds['somewhat_short']) {
        $rank = 'somewhat_short';
    } elseif ($chars < $rank_thresholds['standard']) {
        $rank = 'standard';
    } elseif ($chars < $rank_thresholds['somewhat_long']) {
        $rank = 'somewhat_long';
    } else {
        $rank = 'long';
    }

    if ('short' === $rank) {
        // 短い = 薄緑（ライム寄りの黄緑）。やや短いのエメラルドと明確に色相を分ける。
        $label = '短い';
        $color = '#DCEDC8'; $on_color = '#33691E';
        $container_color = '#F3FAE4';
        $badge_color = '#7CB342';
        $badge_bg = '#EDF6D8';
    } elseif ('somewhat_short' === $rank) {
        // やや短い = エメラルド（青緑）。短いの薄緑と一目で判別できるようにする。
        $label = 'やや短い';
        $color = '#C8E6C9'; $on_color = '#004D40';
        $container_color = '#E4FAF3';
        $badge_color = '#009E7F';
        $badge_bg = '#CFF3E9';
    } elseif ('standard' === $rank) {
        $label = '標準';
        $color = '#E3F2FD'; $on_color = '#0D47A1';
        $container_color = '#E1F5FE';
        $badge_color = '#0067D8';
        $badge_bg = '#DCEBFF';
    } elseif ('somewhat_long' === $rank) {
        $label = 'やや長い';
        $color = '#FFF9C4'; $on_color = '#F57F17';
        $container_color = '#FFFDE7';
        $badge_color = '#DE7A00';
        $badge_bg = '#FFECCF';
    } else {
        $label = '長い';
        $color = '#FFDAD6'; $on_color = '#410002';
        $container_color = '#FFEBEE';
        $badge_color = '#CF2A2A';
        $badge_bg = '#FFE1DD';
    }

    $reading_seconds = max(30, (int) $article_metrics['reading_seconds']);
    $reading = max(1, (int) $article_metrics['reading']);

    /*
     * Preview 5: 円ゲージはランク固定値ではなく、実際の本文量と読了時間を反映する。
     *
     * 文字数は10,000字、読了時間は550字/分換算の10,000字相当（約18分11秒）を
     * 100%の基準とし、双方を同じスケールへ正規化して平均する。
     * これにより同じランク内でも記事ごとにヘッド位置が連続的に変化する一方、
     * 色とラベルは従来どおりブログ分布/絶対閾値による判定を維持する。
     */
    $gauge_max_chars = 10000;
    $gauge_max_seconds = (int) round(($gauge_max_chars / 550) * 60);
    $chars_progress = min(100, max(0, ($chars / $gauge_max_chars) * 100));
    $time_progress = min(100, max(0, ($reading_seconds / $gauge_max_seconds) * 100));
    $progress = (int) round(($chars_progress + $time_progress) / 2);

    // 200字超で表示されるバッジが完全な空円に見えないよう、最小2%だけ確保する。
    if ($chars > 200) {
        $progress = max(2, $progress);
    }

    return [
        'chars'           => $chars,
        'rank'            => $rank,
        'label'           => $label,
        'color'           => $color,
        'on_color'        => $on_color,
        'container_color' => $container_color,
        'badge_color'     => $badge_color,
        'badge_bg'        => $badge_bg,
        'progress'        => $progress,
        'reading'         => $reading,
        'reading_seconds' => $reading_seconds
    ];
}

/**
 * テーマの build.json（リリース毎のビルド識別子）を読む
 *
 * 同日リリースはバージョンを上げず node.zip だけ更新する運用のため、
 * バージョンとは独立にビルドを識別する手段としてリリース時に生成される。
 * 存在しない（旧ビルド・開発中）場合は null。
 */
function node_get_build_info() {
    static $cache  = null;
    static $loaded = false;

    if ( $loaded ) {
        return $cache;
    }
    $loaded = true;

    $path = get_template_directory() . '/build.json';
    if ( ! file_exists( $path ) ) {
        return $cache;
    }

    $data = json_decode( (string) file_get_contents( $path ), true );
    if ( is_array( $data ) && ! empty( $data['build_id'] ) ) {
        $cache = $data;
    }

    return $cache;
}

/**
 * 公開済み固定ページが存在する場合だけ、その正本URLを返す。
 */
function node_get_existing_page_url( string $path ): string {
    $slug = trim( $path, '/' );
    if ( '' === $slug ) {
        return '';
    }

    $page = get_page_by_path( $slug, OBJECT, 'page' );
    if ( ! $page instanceof WP_Post || 'publish' !== get_post_status( $page ) ) {
        return '';
    }

    return home_url( '/' . $slug . '/' );
}

/**
 * フッターメニューのフォールバック
 */
function node_footer_menu_fallback() {
    $privacy_url = node_get_existing_page_url( '/privacy-policy/' );
    $contact_url = node_get_existing_page_url( '/contact/' );

    echo '<ul class="m3-footer__links">';
    echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">ホーム</a></li>';
    if ( '' !== $privacy_url ) {
        echo '<li><a href="' . esc_url( $privacy_url ) . '">プライバシーポリシー</a></li>';
    }
    if ( '' !== $contact_url ) {
        echo '<li><a href="' . esc_url( $contact_url ) . '">お問い合わせ</a></li>';
    }
    echo '</ul>';
}
