<?php
declare(strict_types=1);

get_header();
?>

<main id="primary" class="site-main article-view m3-reveal m3-page-enter">
    <?php 
    // SEO: パンくずリスト
    node_the_breadcrumbs();
    
    while (have_posts()) : the_post();
        $current_multipage = max( 1, (int) get_query_var( 'page' ) );
        $is_primary_page   = ( 1 === $current_multipage );
    ?>

        <article id="post-<?php the_ID(); ?>" <?php post_class('m3-article'); ?> data-m3-multipage="<?php echo esc_attr( (string) $current_multipage ); ?>">
            <?php node_print_the_header(); ?>
            <?php get_template_part( 'template-parts/single/hero' ); ?>

            <?php
            $ai_summary = get_post_meta( get_the_ID(), '_node_ai_summary', true );
            $tone_color = get_post_meta( get_the_ID(), '_node_ai_tone_color', true );
            $keywords   = get_post_meta( get_the_ID(), '_node_ai_keywords', true );
            $ai_args    = array(
                'summary'    => $ai_summary,
                'mode'       => 'single',
                'tone_color' => $tone_color,
                'keywords'   => is_array( $keywords ) ? $keywords : array(),
            );
            if ( ! empty( $ai_summary ) && $is_primary_page ) {
                get_template_part( 'template-parts/ai-summary', null, $ai_args );
            }

            // プラグイン等からの拡張表示（Node Library等）
            luminous_after_article_header( get_the_ID() );
            ?>

            <div class="m3-article__body m3-reveal">
                <?php get_template_part( 'template-parts/single/inline-toc' ); ?>
                <?php the_content(); ?>
            </div>

            <div class="m3-article__body-footer-clear"></div>

            <?php get_template_part( 'template-parts/single/pagination' ); ?>

            <?php get_template_part( 'template-parts/single/footer' ); ?>
            <?php node_print_the_footer(); ?>

        </article>

        <?php get_template_part( 'template-parts/single/related' ); ?>

        <?php if ( comments_open() || get_comments_number() ) : ?>
            <section id="comments-section" class="m3-comments-section m3-reveal" aria-label="<?php esc_attr_e( 'コメント', 'node' ); ?>">
                <?php comments_template(); ?>
            </section>
        <?php endif; ?>

        <?php get_template_part( 'template-parts/single/toc' ); ?>

    <?php endwhile; ?>
</main>

<?php get_footer(); ?>
