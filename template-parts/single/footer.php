<?php

declare(strict_types=1);
/**
 * Template part for displaying the article footer in single.php
 */
?>
<footer class="m3-article__footer">
    <?php if ( get_the_tags() ) : ?>
        <section class="m3-article__footer-section m3-article__tag-section" aria-label="タグ">
            <?php node_the_tag_labels(); ?>
        </section>
    <?php endif; ?>

    <?php
    ob_start();
    do_action('luminous_after_tags', get_the_ID());
    $node_linked_content = trim( (string) ob_get_clean() );

    if ( '' !== $node_linked_content ) :
    ?>
        <section class="m3-article__footer-section m3-article__library-section" aria-label="ゲーム・アプリ情報">
            <?php echo $node_linked_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </section>
    <?php endif; ?>

    <?php get_template_part('template-parts/social-share'); ?>
    
    <?php get_template_part('template-parts/card-writer'); ?>
    
    <?php 
    // ライター情報の後にコンテンツを挿入するアクション
    do_action('luminous_after_writer', get_the_ID()); 
    ?>

    <?php get_template_part('template-parts/single/series-nav'); ?>

    <!-- 前後の記事ナビゲーション -->
    <?php
    $prev_post = get_previous_post();
    $next_post = get_next_post();
    if ($prev_post || $next_post) :
    ?>
    <nav class="m3-post-navigation" aria-label="前後の記事">
        <?php if ($prev_post) : ?>
        <?php $prev_thumb = get_the_post_thumbnail_url($prev_post->ID, 'medium_large'); ?>
        <a href="<?php echo esc_url( get_permalink( $prev_post->ID ) ); ?>" class="m3-elevated-nav-card m3-ripple-host<?php echo $prev_thumb ? '' : ' m3-elevated-nav-card--plain'; ?>">
            <?php if ($prev_thumb) : ?>
                <img class="m3-elevated-nav-card__bg" src="<?php echo esc_url($prev_thumb); ?>" alt="" loading="lazy" decoding="async">
            <?php endif; ?>
            <div class="m3-elevated-nav-card__content">
                <span class="m3-elevated-nav-card__label">
                    <span class="material-symbols-outlined">arrow_back</span>
                    前の記事
                </span>
                <h4 class="m3-elevated-nav-card__title"><?php echo esc_html(get_the_title($prev_post->ID)); ?></h4>
            </div>
        </a>
        <?php endif; ?>
        
        <?php if ($next_post) : ?>
        <?php $next_thumb = get_the_post_thumbnail_url($next_post->ID, 'medium_large'); ?>
        <a href="<?php echo esc_url( get_permalink( $next_post->ID ) ); ?>" class="m3-elevated-nav-card m3-ripple-host<?php echo $next_thumb ? '' : ' m3-elevated-nav-card--plain'; ?>" style="text-align: right;">
            <?php if ($next_thumb) : ?>
                <img class="m3-elevated-nav-card__bg" src="<?php echo esc_url($next_thumb); ?>" alt="" loading="lazy" decoding="async">
            <?php endif; ?>
            <div class="m3-elevated-nav-card__content" style="align-items: flex-end;">
                <span class="m3-elevated-nav-card__label">
                    次の記事
                    <span class="material-symbols-outlined">arrow_forward</span>
                </span>
                <h4 class="m3-elevated-nav-card__title"><?php echo esc_html(get_the_title($next_post->ID)); ?></h4>
            </div>
        </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</footer>
