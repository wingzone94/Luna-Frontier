<?php
declare(strict_types=1);

/**
 * Template part for multipage post pagination (<!--nextpage-->) in single.php
 *
 * @package Node
 */

global $numpages, $page;

if ( (int) $numpages <= 1 ) {
    return;
}

$current_multipage = max( 1, (int) $page );

$get_multipage_url = static function ( int $page_number ): string {
    $link = _wp_link_page( $page_number );

    if ( preg_match( '/href=(["\'])(.*?)\1/', $link, $match ) ) {
        return html_entity_decode( $match[2], ENT_QUOTES, get_bloginfo( 'charset' ) );
    }

    return get_permalink();
};
?>
<div class="m3-article__pagination-container m3-reveal">
    <div class="m3-article__pagination-main-row">
        <div class="m3-article__pagination-row">
            <nav class="m3-article-pagination m3-pagination--split" aria-label="<?php esc_attr_e( '記事のページ', 'node' ); ?>">
                <span class="m3-pagination__label">
                    <span class="material-symbols-outlined m3-pagination__label-icon" aria-hidden="true">auto_stories</span>
                    PAGES
                </span>
                <div class="m3-pagination__controls">
                    <div class="m3-pagination__select-wrapper">
                        <select id="m3-page-selector" class="m3-pagination__select" aria-label="<?php esc_attr_e( 'ページを選択', 'node' ); ?>">
                            <?php for ( $i = 1; $i <= (int) $numpages; $i++ ) : ?>
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
                        $pagination_display_pages = array( 1, 2, 3, (int) $numpages );
                        $pagination_display_pages = array_values(
                            array_unique(
                                array_filter(
                                    $pagination_display_pages,
                                    static function ( $page_num ) use ( $numpages ) {
                                        return $page_num >= 1 && $page_num <= (int) $numpages;
                                    }
                                )
                            )
                        );
                        sort( $pagination_display_pages );
                        $last_rendered_page = 0;

                        if ( $current_multipage > 1 ) {
                            echo '<a href="' . esc_url( $first_url ) . '" class="m3-pagination__number m3-pagination__number--icon" aria-label="' . esc_attr__( '最初のページへ', 'node' ) . '"><span class="material-symbols-outlined" aria-hidden="true">first_page</span></a>';
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
                                echo '<span class="' . $class_attr . '" aria-current="page">' . esc_html( (string) $i ) . '</span>';
                            } else {
                                echo '<a href="' . esc_url( $get_multipage_url( $i ) ) . '" class="' . $class_attr . '" aria-label="' . esc_attr( sprintf( __( 'ページ %d へ', 'node' ), $i ) ) . '">' . esc_html( (string) $i ) . '</a>';
                            }

                            $last_rendered_page = $i;
                        }

                        $last_url = $get_multipage_url( (int) $numpages );

                        if ( $current_multipage < (int) $numpages ) {
                            echo '<a href="' . esc_url( $last_url ) . '" class="m3-pagination__number m3-pagination__number--icon" aria-label="' . esc_attr__( '最後のページへ', 'node' ) . '"><span class="material-symbols-outlined" aria-hidden="true">last_page</span></a>';
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
