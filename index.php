<?php
declare(strict_types=1);

get_header();
?>
<main id="primary" class="site-main m3-home-layout">
    <?php node_the_breadcrumbs(); ?>

    <?php
    if ((is_home() || is_front_page()) && !is_paged()) {
        get_template_part( 'template-parts/hero-slider' );

        $spotlight_cats = function_exists('node_get_spotlight_categories') ? node_get_spotlight_categories() : [];

        if (!empty($spotlight_cats)) : ?>
            <section id="spotlight" class="special-features m3-surface m3-section-spacing" aria-labelledby="spotlight-title">
                <div class="m3-headlines__header" style="margin-bottom: 24px; padding: 0;">
                    <h2 id="spotlight-title" class="m3-section-title m3-headlines__title" style="margin-bottom: 0;">
                        <span class="material-symbols-outlined" aria-hidden="true">local_fire_department</span>
                        SPOTLIGHT <span class="m3-section-title__sub">特集</span>
                    </h2>
                    <a href="<?php echo esc_url( node_get_spotlight_url() ); ?>" class="m3-headlines__more m3-button m3-button--text">
                        すべて見る<span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                    </a>
                </div>
                <div class="special-features__pills">
                    <?php foreach ($spotlight_cats as $cat) : ?>
                        <a href="<?php echo esc_url($cat['url']); ?>"
                           class="m3-spotlight-badge m3-ripple-host"
                           style="background-color: <?php echo esc_attr($cat['color']); ?>; color:#fff;"
                           aria-label="<?php echo esc_attr($cat['name']); ?>特集へ">
                            <span class="material-symbols-outlined" aria-hidden="true">auto_awesome</span>
                            <?php echo esc_html($cat['name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif;

        get_template_part('template-parts/hot-posts');

        // HEADLINE（速報）: 横スクロール1段に固定し、LATEST をファーストビューへ引き上げる
        echo '<span id="headline" class="screen-reader-text" aria-hidden="true"></span>';
        get_template_part('template-parts/headline-carousel');
    }
    ?>

    <?php if (have_posts()) : ?>
        <?php
        $is_first_page       = (is_home() && !is_paged());
        $featured_card_limit = 4;
        // ホームの LATEST は6件で表示を打ち切り、残りは「すべて見る」へ集約する。
        $latest_limit        = 6;
        ?>
        <section<?php echo $is_first_page ? ' id="latest"' : ''; ?> class="l-card-grid m3-post-grid" aria-labelledby="<?php echo $is_first_page ? 'latest-title' : 'articles-title'; ?>">
            <?php if ($is_first_page) : ?>
                <div class="m3-surface m3-surface--latest m3-section-spacing">
                    <h2 id="latest-title" class="m3-section-title"><span class="material-symbols-outlined" aria-hidden="true">bolt</span> LATEST <span class="m3-section-title__sub">最新記事</span></h2>
                    <div class="l-card-grid__items l-card-grid__items--featured m3-post-grid__container m3-post-grid__container--featured">
            <?php else : ?>
                <div class="m3-surface m3-surface--articles m3-section-spacing">
                    
                    <div class="l-card-grid__items m3-post-grid__container is-articles-grid">
            <?php endif; ?>

            <?php
            while (have_posts()) : the_post();
                if ($is_first_page && $wp_query->current_post >= $latest_limit) {
                    break;
                }
                $card_class = ($is_first_page && $wp_query->current_post < $featured_card_limit) ? 'card-featured' : 'card-standard';
                get_template_part('template-parts/card', null, ['card_class' => $card_class]);
            endwhile;
            ?>
            <?php if ($is_first_page) : ?>
                </div>
                <a class="m3-latest-see-all m3-button m3-button--text" href="<?php echo esc_url(node_get_all_articles_url()); ?>">
                    <span class="m3-latest-see-all__text">すべて見る</span>
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                </a>
            <?php else : ?>
                </div>
            <?php endif; ?>
            </div>
            <?php if (get_next_posts_link()) : ?>
                <div class="m3-archive-pill-wrapper m3-section-spacing">
                    <?php if ($is_first_page) : ?>
                        <a href="<?php echo esc_url(node_get_all_articles_url()); ?>" class="m3-archive-pill-button m3-ripple-host" aria-label="全ての記事を見る">
                            <span class="m3-archive-pill-button__text">すべての記事を見る</span>
                            <div class="m3-archive-pill-button__icon"><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></div>
                        </a>
                    <?php else : ?>
                        <?php
                            $next_link = get_next_posts_page_link($wp_query->max_num_pages);
                        ?>
                        <a href="<?php echo esc_url($next_link); ?>" class="m3-archive-pill-button m3-ripple-host" aria-label="もっと見る">
                            <span class="m3-archive-pill-button__text">もっと見る</span>
                            <div class="m3-archive-pill-button__icon"><span class="material-symbols-outlined" aria-hidden="true">expand_more</span></div>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php else : ?>
        <section class="m3-no-posts m3-surface m3-section-spacing">
            <div class="m3-no-posts__content">
                <span class="material-symbols-outlined m3-no-posts__icon">sentiment_dissatisfied</span>
                <h2 class="m3-no-posts__title">記事が見つかりませんでした</h2>
                <p class="m3-no-posts__text">投稿された記事がまだないか、条件に一致する記事がありません。</p>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="m3-button m3-button--filled">トップへ戻る</a>
            </div>
        </section>
    <?php endif; ?>

</main>
<?php get_footer(); ?>
