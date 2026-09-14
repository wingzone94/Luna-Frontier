<?php

declare(strict_types=1);
/**
 * Luna Frontier article card.
 *
 * Parent markup is preserved, with only Spectrum accent tokens added to the
 * card root. The tokens are consumed by Luna CSS and ignored by Node.
 *
 * @package LunaFrontier
 */

if ( luna_frontier_is_legacy_archive() ) {
    require get_template_directory() . '/template-parts/article-card.php';
    return;
}

$post_id             = get_the_ID();
$card_class          = $args['card_class'] ?? '';
$image_loading       = ($args['image_loading'] ?? 'lazy') === 'eager' ? 'eager' : 'lazy';
$image_fetchpriority = ($args['image_fetchpriority'] ?? '') === 'high' ? 'high' : '';
$card_color_style    = function_exists('luna_frontier_get_card_color_style') ? luna_frontier_get_card_color_style((int) $post_id) : '';

$has_image = has_post_thumbnail($post_id);
$thumbnail_attributes = [
    'alt'   => get_the_title(),
    'class' => 'm3-card__image c-card__image',
    'loading' => $image_loading,
    /*
     * sizes を自前で持つ。
     *
     * WordPress の既定は `auto, (max-width: 1538px) 100vw, 1538px`。100vw は
     * このカードでは正しくないうえ、`auto` と lazy が組み合わさると Chrome は
     * 「描画された幅」で候補を選ぶ。700px 以下の行レイアウトではサムネイルが
     * 105px しかないため、105×59 のビットマップを取得して 105×145 の枠へ
     * cover で 2.5 倍に引き伸ばしていた（実測 390px / DPR3 で 1/6 の解像度）。
     * ぼやけた面が並ぶので、アイキャッチが空白に見える。
     *
     * 実際の描画幅を書く。行では clamp(88px, 27vw, 116px)、グリッドでは
     * 3 列で最大 400px 前後。DPR は srcset 側が面倒を見る。
     */
    'sizes' => '(max-width: 700px) 116px, (max-width: 1024px) 45vw, 400px',
];
if ( '' !== $image_fetchpriority ) {
    $thumbnail_attributes['fetchpriority'] = $image_fetchpriority;
}
$thumbnail = $has_image ? get_the_post_thumbnail($post_id, 'large', $thumbnail_attributes) : '';

ob_start();
node_the_post_badges($post_id, 'compact');
$badges_html = trim(ob_get_clean());

$series_banner_html = '';
if (function_exists('node_the_series_banner')) {
    ob_start();
    node_the_series_banner($post_id);
    $series_banner_html = trim(ob_get_clean());
}

$modified = function_exists('node_get_post_modified_display') ? node_get_post_modified_display($post_id) : null;

$author_id   = (int) get_the_author_meta('ID');
$author_name = trim((string) get_the_author());
$author_url  = $author_id ? get_author_posts_url($author_id) : '';

$has_category = has_category('', $post_id);
$has_topline_labels = ('' !== $badges_html) || ('' !== $series_banner_html);
?>
<article id="post-<?php the_ID(); ?>" <?php post_class('m3-card c-card lf-card-spectrum ' . $card_class . ($has_image ? ' m3-card--has-image c-card--has-image' : ' m3-card--no-image c-card--no-image')); ?><?php echo $card_color_style ? ' style="' . esc_attr($card_color_style) . '"' : ''; ?>>

    <?php if ($has_image) : ?>
        <div class="m3-card__visual c-card__visual">
            <a href="<?php the_permalink(); ?>" class="m3-card__image-link c-card__image-link" aria-hidden="true" tabindex="-1">
                <?php echo $thumbnail; ?>
                <div class="m3-card__image-gradient c-card__image-gradient"></div>
            </a>
        </div>
    <?php endif; ?>

    <div class="m3-card__content c-card__content">
        <?php if ($has_category || $has_topline_labels) : ?>
            <div class="m3-card__topline c-card__topline">
                <?php if ($has_category) : ?>
                    <div class="m3-card__category-container c-card__category-container">
                        <?php
                        // 主カテゴリだけ塗り、以降は枠線（親のカード実装は全部塗りになる）。
                        if ( function_exists( 'luna_frontier_the_card_category_labels' ) ) {
                            luna_frontier_the_card_category_labels( $post_id );
                        } else {
                            node_the_category_labels();
                        }
                        ?>
                    </div>
                <?php endif; ?>

                <?php if ($has_topline_labels) : ?>
                    <div class="m3-card__labels m3-card__labels--inline c-card__labels c-card__labels--inline">
                        <?php echo $badges_html; ?>
                        <?php echo $series_banner_html; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <h3 class="m3-card__title c-card__title">
            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
        </h3>

        <div class="m3-card__footer c-card__footer">
            <div class="m3-card__writer c-card__writer">
                <?php if ('' !== $author_name && '' !== $author_url) : ?>
                    <a class="m3-card__writer-link c-card__writer-link" href="<?php echo esc_url($author_url); ?>" rel="author" aria-label="<?php echo esc_attr(sprintf('%sの記事一覧へ', $author_name)); ?>">
                        <?php echo get_avatar($author_id, 24, '', $author_name); ?>
                        <span class="m3-card__writer-name c-card__writer-name"><?php echo esc_html($author_name); ?></span>
                    </a>
                <?php else : ?>
                    <?php echo get_avatar($author_id, 24, '', $author_name); ?>
                    <span class="m3-card__writer-name c-card__writer-name"><?php echo esc_html($author_name); ?></span>
                <?php endif; ?>
            </div>

            <div class="m3-card__dates c-card__dates">
                <div class="m3-card__date c-card__date">
                    <span class="material-symbols-outlined">calendar_today</span>
                    <span>
                        <?php
                        $date_text = node_get_relative_date($post_id);
                        if (preg_match('/^(.+?)\s*（(.+)）$/u', $date_text, $date_parts)) {
                            echo esc_html($date_parts[1]);
                            echo '<span class="m3-card__date-rel c-card__date-rel">（' . esc_html($date_parts[2]) . '）</span>';
                        } else {
                            echo esc_html($date_text);
                        }
                        ?>
                    </span>
                </div>
                <?php if ($modified) : ?>
                    <div class="m3-card__date m3-card__date--modified c-card__date c-card__date--modified" title="<?php echo esc_attr(sprintf('追記 %s', $modified['display'])); ?>">
                        <span class="material-symbols-outlined">update</span>
                        <time datetime="<?php echo esc_attr($modified['datetime']); ?>">
                            <?php echo esc_html(sprintf('追記 %s', $modified['display_short'])); ?>
                        </time>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</article>
