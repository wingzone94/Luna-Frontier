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

        <article id="post-<?php the_ID(); ?>" <?php post_class('m3-article'); ?> data-m3-multipage="<?php echo esc_attr( $current_multipage ); ?>">
<?php node_print_the_header(); ?>
<?php get_template_part('template-parts/single/hero'); ?>
            
            

            <?php
                        $ai_summary    = get_post_meta( get_the_ID(), '_node_ai_summary', true );
                        $tone_color    = get_post_meta( get_the_ID(), '_node_ai_tone_color', true );
                        $keywords      = get_post_meta( get_the_ID(), '_node_ai_keywords', true );
                        $ai_args = array(
                            'summary'    => $ai_summary,
                            'mode'       => 'single',
                            'tone_color' => $tone_color,
                            'keywords'   => is_array( $keywords ) ? $keywords : array(),
                        );
                        if ( ! empty( $ai_summary ) && $is_primary_page ) {
                            get_template_part( 'template-parts/ai-summary', null, $ai_args );
                        }

                        // プラグイン等からの拡張表示（Node Library等）
                        luminous_after_article_header(get_the_ID());
                    ?>
                    <div class="m3-article__body m3-reveal">
                <!-- 記事内目次コンテナ (JSでここに目次が挿入されます) -->
                <div id="m3-inline-toc" class="m3-inline-toc" style="display: none;">
                    <div class="m3-inline-toc__header">
                        <span class="material-symbols-outlined" aria-hidden="true">toc</span> 目次
                    </div>
                    <nav id="m3-inline-toc-content" class="m3-inline-toc__content" aria-label="記事内目次"></nav>
                </div>

                <?php the_content(); ?>
            </div>

            <div class="m3-article__body-footer-clear"></div>

            <?php
                global $numpages, $page;
                $current_multipage = max( 1, (int) $page );

                if ( $numpages > 1 ) :
                    $get_multipage_url = static function ( $page_number ) {
                        $link = _wp_link_page( (int) $page_number );

                        if ( preg_match( '/href=(["\'])(.*?)\1/', $link, $match ) ) {
                            return html_entity_decode( $match[2], ENT_QUOTES, get_bloginfo( 'charset' ) );
                        }

                        return get_permalink();
                    };
            ?>
                <div class="m3-article__pagination-container m3-reveal">
                    <div class="m3-article__pagination-main-row">
                        <div class="m3-article__pagination-row">
                                <nav class="m3-article-pagination m3-pagination--split" aria-label="記事のページ">
                                    <span class="m3-pagination__label">
                                        <span class="material-symbols-outlined m3-pagination__label-icon" aria-hidden="true">auto_stories</span>
                                        PAGES
                                    </span>
                                    <div class="m3-pagination__controls">
                                        <div class="m3-pagination__select-wrapper">
                                            <select id="m3-page-selector" class="m3-pagination__select" aria-label="ページを選択">
                                                <?php for ( $i = 1; $i <= $numpages; $i++ ) : ?>
                                                    <option value="<?php echo esc_url( $get_multipage_url( $i ) ); ?>" <?php selected( $i, $current_multipage ); ?>>
                                                        <?php echo esc_html( sprintf( '%1$d/%2$d', $i, $numpages ) ); ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                            <span class="material-symbols-outlined m3-select-chevron" aria-hidden="true">expand_more</span>
                                        </div>
                                        <div class="m3-pagination__numbers">
                                            <?php
                                            $first_url = $get_multipage_url( 1 );
                                            $pagination_display_pages = array( 1, 2, 3, $numpages );
                                            $pagination_display_pages = array_values(
                                                array_unique(
                                                    array_filter(
                                                        $pagination_display_pages,
                                                        static function ( $page_num ) use ( $numpages ) {
                                                            return $page_num >= 1 && $page_num <= $numpages;
                                                        }
                                                    )
                                                )
                                            );
                                            sort( $pagination_display_pages );
                                            $last_rendered_page = 0;

                                            if ( $current_multipage > 1 ) {
                                                echo '<a href="' . esc_url( $first_url ) . '" class="m3-pagination__number m3-pagination__number--icon" aria-label="最初のページへ"><span class="material-symbols-outlined" aria-hidden="true">first_page</span></a>';
                                            } else {
                                                echo '<span class="m3-pagination__number m3-pagination__number--icon is-disabled" aria-hidden="true"><span class="material-symbols-outlined">first_page</span></span>';
                                            }

                                            foreach ( $pagination_display_pages as $i ) {
                                                if ( $last_rendered_page > 0 && $i - $last_rendered_page > 1 ) {
                                                    echo '<span class="m3-pagination__ellipsis" aria-hidden="true">…</span>';
                                                }

                                                $is_current = ( $i === $current_multipage );
                                                $classes    = array( 'm3-pagination__number' );

                                                if ( $is_current ) {
                                                    $classes[] = 'is-current';
                                                } elseif ( $i >= 2 ) {
                                                    $classes[] = 'is-page-after-first';
                                                }

                                                $class_attr = esc_attr( implode( ' ', $classes ) );

                                                if ( $is_current ) {
                                                    echo '<span class="' . $class_attr . '" aria-current="page">' . esc_html( $i ) . '</span>';
                                                } else {
                                                    echo '<a href="' . esc_url( $get_multipage_url( $i ) ) . '" class="' . $class_attr . '" aria-label="' . esc_attr( sprintf( 'ページ %d へ', $i ) ) . '">' . esc_html( $i ) . '</a>';
                                                }

                                                $last_rendered_page = $i;
                                            }

                                            $last_url = $get_multipage_url( $numpages );

                                            if ( $current_multipage < $numpages ) {
                                                echo '<a href="' . esc_url( $last_url ) . '" class="m3-pagination__number m3-pagination__number--icon" aria-label="最後のページへ"><span class="material-symbols-outlined" aria-hidden="true">last_page</span></a>';
                                            } else {
                                                echo '<span class="m3-pagination__number m3-pagination__number--icon is-disabled" aria-hidden="true"><span class="material-symbols-outlined">last_page</span></span>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </nav>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php get_template_part('template-parts/single/footer'); ?>
            <?php node_print_the_footer(); ?>

        </article>

        <?php if ( ! function_exists( 'node_is_native_app' ) || ! node_is_native_app() ) : ?>
        <?php get_template_part('template-parts/single/related'); ?>

        <?php if (comments_open() || get_comments_number()) : ?>
        <section id="comments-section" class="m3-comments-section m3-reveal" aria-label="コメント">
            <?php
                comments_template();
            ?>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <?php get_template_part('template-parts/single/toc'); ?>

<?php endwhile; ?>
</main>

<?php get_footer(); ?>
