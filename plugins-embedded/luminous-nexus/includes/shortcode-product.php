<?php
/**
 * 商品カードショートコード - 完全版 (Amazon/楽天 別ディスクロージャー対応)
 *
 * @package Luminous_Nexus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 商品カードショートコード [product_card title="..." price="..." image_url="..." amazon_url="..." rakuten_url="..." asin="..." show_amazon_disclosure="1" show_rakuten_disclosure="1"]
 */
function luminous_nexus_product_card_shortcode($atts) {
    $atts = shortcode_atts([
        'title'                   => '',
        'price'                   => '',
        'image_url'               => '',
        'amazon_url'              => '',
        'rakuten_url'             => '',
        'asin'                    => '', // Amazon ASIN
        'show_amazon_disclosure'  => '', // "1" で表示
        'show_rakuten_disclosure' => '', // "1" で表示
    ], $atts, 'product_card');

    $amazon_id   = get_option('luminous_nexus_amazon_id');
    $rakuten_id  = get_option('luminous_nexus_rakuten_id');
    
    $disclosure_amazon  = get_option('luminous_nexus_disclosure_amazon', 'Amazonのアソシエイトとして、当メディアは適格販売により収入を得ています。');
    $disclosure_rakuten = get_option('luminous_nexus_disclosure_rakuten', '当メディアは、楽天アフィリエイト・プログラムの参加者です。');

    // Amazon URL の構築 (ASIN があれば優先)
    if (!empty($atts['asin'])) {
        $atts['amazon_url'] = "https://www.amazon.co.jp/dp/{$atts['asin']}";
        if ($amazon_id) {
            $atts['amazon_url'] .= "?tag={$amazon_id}";
        }
    } elseif (!empty($atts['amazon_url']) && $amazon_id && !str_contains($atts['amazon_url'], 'tag=')) {
        $separator = str_contains($atts['amazon_url'], '?') ? '&' : '?';
        $atts['amazon_url'] .= "{$separator}tag={$amazon_id}";
    }

    $has_amazon  = !empty($atts['amazon_url']);
    $has_rakuten = !empty($atts['rakuten_url']);

    if (!$has_amazon && !$has_rakuten) return '';

    ob_start();
    ?>
    <div class="m3-product-card-container m3-reveal">
        <div class="m3-product-card">
            <?php if (!empty($atts['image_url'])) : ?>
            <div class="m3-product-card__image">
                <img src="<?php echo esc_url($atts['image_url']); ?>"
                     alt="<?php echo esc_attr($atts['title']); ?>"
                     loading="lazy">
            </div>
            <?php endif; ?>

            <div class="m3-product-card__content">
                <?php if (!empty($atts['title'])) : ?>
                <h4 class="m3-product-card__title"><?php echo esc_html($atts['title']); ?></h4>
                <?php endif; ?>

                <?php if (!empty($atts['price'])) : ?>
                <div class="m3-product-card__price-wrapper">
                    <span class="m3-product-card__price-label">参考価格:</span>
                    <span class="m3-product-card__price"><?php echo esc_html($atts['price']); ?></span>
                </div>
                <?php endif; ?>

                <div class="m3-product-card__actions">
                    <?php if ($has_amazon) : ?>
                    <a href="<?php echo esc_url($atts['amazon_url']); ?>"
                       class="m3-product-btn m3-product-btn--amazon m3-ripple-host"
                       target="_blank"
                       rel="noopener noreferrer sponsored">
                        <img src="https://www.google.com/s2/favicons?domain=amazon.co.jp&sz=32" alt="" class="m3-product-btn__icon">
                        Amazonで見る
                    </a>
                    <?php endif; ?>

                    <?php if ($has_rakuten) : ?>
                    <a href="<?php echo esc_url($atts['rakuten_url']); ?>"
                       class="m3-product-btn m3-product-btn--rakuten m3-ripple-host"
                       target="_blank"
                       rel="noopener noreferrer sponsored">
                        <img src="https://www.google.com/s2/favicons?domain=rakuten.co.jp&sz=32" alt="" class="m3-product-btn__icon">
                        楽天市場で見る
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="m3-product-card__disclosures">
            <?php if ($atts['show_amazon_disclosure'] === '1' && !empty($disclosure_amazon)) : ?>
                <div class="m3-product-card__disclosure">
                    <span class="material-symbols-outlined" data-icon="info"></span>
                    <?php echo esc_html($disclosure_amazon); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($atts['show_rakuten_disclosure'] === '1' && !empty($disclosure_rakuten)) : ?>
                <div class="m3-product-card__disclosure">
                    <span class="material-symbols-outlined" data-icon="info"></span>
                    <?php echo esc_html($disclosure_rakuten); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
