<?php

declare(strict_types=1);
/**
 * SEO & Semantic HTML Functions
 *
 * 構造化データ (JSON-LD)、パンくずリスト、動的タイトル管理など
 * SEO対策の中核を担うロジック。
 *
 * @package Node
 */

/**
 * 1. 構造化データ (JSON-LD) の出力
 */
function node_seo_json_ld() {
    $payloads = [];

    // 1. Article Schema (Singular only)
    if ( is_singular() ) {
        $post_id = get_the_ID();
        $author_id = get_post_field( 'post_author', $post_id );
        
        $payloads[] = [
            '@context' => 'https://schema.org',
            '@type'    => 'Article',
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => wp_get_canonical_url( $post_id ) ?: get_permalink( $post_id ),
            ],
            'headline' => get_the_title(),
            'image'    => [ get_the_post_thumbnail_url( $post_id, 'large' ) ?: get_site_icon_url() ],
            'datePublished' => get_the_date( 'c' ),
            'dateModified'  => get_the_modified_date( 'c' ),
            'author' => [
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $author_id ),
                'url'   => get_author_posts_url( $author_id ),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
                'logo'  => [
                    '@type' => 'ImageObject',
                    'url'   => get_site_icon_url(),
                ],
            ],
            'description' => wp_trim_words( get_the_excerpt(), 160 ),
        ];
    }

    // 2. WebSite Schema (Home page only)
    if ( is_front_page() || is_home() ) {
        $payloads[] = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => get_bloginfo( 'name' ),
            'url'      => home_url( '/' ),
            // target は文字列ではなく EntryPoint で包む。
            // 素の文字列で書くと Google がテンプレートを普通のURLとみなして
            // `/?s={search_term_string}` を**そのままクロール**し、GSC の
            // 「noindex タグによって除外されました」に積み上がる（2026-08-08 実測、
            // NODE-1.3.md §23）。urlTemplate はプレースホルダを含むため
            // esc_url を通さない（`{` `}` が壊れる）。
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => home_url( '/?s={search_term_string}' ),
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    foreach ( $payloads as $payload ) {
        echo "\n" . '<script type="application/ld+json">' . json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
    }
}
add_action( 'wp_head', 'node_seo_json_ld' );

/**
 * OpenSearch description document support.
 */
function node_opensearch_xml_escape( $value ) {
    return function_exists( 'esc_xml' ) ? esc_xml( $value ) : esc_html( $value );
}

function node_opensearch_description_url(): string {
    return home_url( '/opensearch.xml' );
}

function node_opensearch_search_template(): string {
    return home_url( '/?s={searchTerms}' );
}

function node_output_opensearch_link() {
    echo '<link rel="search" type="application/opensearchdescription+xml" title="' . esc_attr( get_bloginfo( 'name' ) . ' Search' ) . '" href="' . esc_url( node_opensearch_description_url() ) . '">' . "\n";
}
add_action( 'wp_head', 'node_output_opensearch_link', 2 );

function node_maybe_render_opensearch_description() {
    $request_path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
    $opensearch_url = (string) wp_parse_url( node_opensearch_description_url(), PHP_URL_PATH );

    if ( untrailingslashit( $request_path ) !== untrailingslashit( $opensearch_url ) ) {
        return;
    }

    $site_name   = get_bloginfo( 'name' );
    $description = sprintf( '%s のサイト内検索', $site_name );

    status_header( 200 );
    nocache_headers();
    header( 'Content-Type: application/opensearchdescription+xml; charset=' . get_bloginfo( 'charset' ) );

    echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
    echo '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">' . "\n";
    echo "\t" . '<ShortName>' . node_opensearch_xml_escape( $site_name ) . '</ShortName>' . "\n";
    echo "\t" . '<Description>' . node_opensearch_xml_escape( $description ) . '</Description>' . "\n";
    echo "\t" . '<InputEncoding>UTF-8</InputEncoding>' . "\n";
    echo "\t" . '<Image height="16" width="16" type="image/x-icon">' . esc_url( get_site_icon_url( 16 ) ?: home_url( '/favicon.ico' ) ) . '</Image>' . "\n";
    echo "\t" . '<Url type="text/html" method="get" template="' . esc_attr( node_opensearch_search_template() ) . '" />' . "\n";
    echo '</OpenSearchDescription>' . "\n";
    exit;
}
add_action( 'template_redirect', 'node_maybe_render_opensearch_description', 0 );

/**
 * 2. 標準メタディスクリプションの出力
 */
function node_seo_meta_description() {
    if ( is_singular() ) {
        $desc = get_the_excerpt();

        if ( is_singular( 'post' ) ) {
            $current_page = max( 1, (int) get_query_var( 'page' ) );

            if ( $current_page > 1 ) {
                $content = get_post_field( 'post_content', get_queried_object_id() );
                $pages   = is_string( $content ) ? preg_split( '/<!--\s*nextpage\s*-->/i', $content ) : array();

                if ( isset( $pages[ $current_page - 1 ] ) ) {
                    $page_desc = strip_shortcodes( $pages[ $current_page - 1 ] );
                    $page_desc = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $page_desc ) ) );

                    if ( '' !== $page_desc ) {
                        $desc = wp_html_excerpt( $page_desc, 140, '…' );
                    }

                    $desc .= sprintf(
                        '（全%1$dページ中%2$dページ目）',
                        count( $pages ),
                        $current_page
                    );
                }
            }
        }
    } elseif ( is_category() || is_tag() || is_tax() ) {
        $desc = term_description();
    } else {
        $desc = get_bloginfo( 'description' );
    }

    $desc = wp_strip_all_tags( $desc );
    $desc = wp_trim_words( $desc, 160 );

    if ( $desc ) {
        echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
    }
}
add_action( 'wp_head', 'node_seo_meta_description', 1 );

/**
 * 3. パンくずリスト生成関数
 */
function node_the_breadcrumbs() {
    if ( is_front_page() || is_home() ) {
        return;
    }

    echo '<nav class="m3-breadcrumbs" aria-label="Breadcrumb">';
    echo '<ol class="m3-breadcrumbs__list" itemscope itemtype="https://schema.org/BreadcrumbList">';
    
    // Home
    echo '<li class="m3-breadcrumbs__item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
    echo '<a itemprop="item" href="' . esc_url( home_url( '/' ) ) . '"><span itemprop="name">ホーム</span></a>';
    echo '<meta itemprop="position" content="1" /></li>';

    if ( is_singular( 'node_library' ) ) {
        $library_url = get_post_type_archive_link( 'node_library' );
        echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
        echo '<li class="m3-breadcrumbs__item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
        echo '<a itemprop="item" href="' . esc_url( $library_url ) . '"><span itemprop="name">Luna Library</span></a>';
        echo '<meta itemprop="position" content="2" /></li>';
        echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
        echo '<li class="m3-breadcrumbs__item m3-breadcrumbs__item--current" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
        echo '<span itemprop="name">' . esc_html( get_the_title() ) . '</span>';
        echo '<meta itemprop="position" content="3" /></li>';
    } elseif ( is_singular() ) {
        $cat = node_get_primary_category();
        if ( $cat ) {
            echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
            echo '<li class="m3-breadcrumbs__item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
            echo '<a itemprop="item" href="' . esc_url( get_category_link( $cat->term_id ) ) . '"><span itemprop="name">' . esc_html( $cat->name ) . '</span></a>';
            echo '<meta itemprop="position" content="2" /></li>';
        }
        echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
        echo '<li class="m3-breadcrumbs__item m3-breadcrumbs__item--current" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
        echo '<span itemprop="name">' . esc_html( get_the_title() ) . '</span>';
        echo '<meta itemprop="position" content="3" /></li>';
    } elseif ( is_year() || is_month() || is_day() ) {
        $pos = 2;
        $y = (int) get_query_var( 'year' );
        $m = (int) get_query_var( 'monthnum' );
        $d = (int) get_query_var( 'day' );
        if ( is_month() || is_day() ) {
            echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
            echo '<li class="m3-breadcrumbs__item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
            echo '<a itemprop="item" href="' . esc_url( get_year_link( $y ) ) . '"><span itemprop="name">' . esc_html( $y . '年' ) . '</span></a>';
            echo '<meta itemprop="position" content="' . $pos++ . '" /></li>';
        }
        if ( is_day() ) {
            echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
            echo '<li class="m3-breadcrumbs__item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
            echo '<a itemprop="item" href="' . esc_url( get_month_link( $y, $m ) ) . '"><span itemprop="name">' . esc_html( $m . '月' ) . '</span></a>';
            echo '<meta itemprop="position" content="' . $pos++ . '" /></li>';
        }
        $current_label = is_year() ? $y . '年' : ( is_month() ? $m . '月' : $d . '日' );
        echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
        echo '<li class="m3-breadcrumbs__item m3-breadcrumbs__item--current" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
        echo '<span itemprop="name">' . esc_html( $current_label ) . '</span>';
        echo '<meta itemprop="position" content="' . $pos . '" /></li>';
    } elseif ( is_category() || is_tag() || is_tax() ) {
        echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
        echo '<li class="m3-breadcrumbs__item m3-breadcrumbs__item--current" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
        echo '<span itemprop="name">' . esc_html( single_term_title( '', false ) ) . '</span>';
        echo '<meta itemprop="position" content="2" /></li>';
    } elseif ( is_post_type_archive() ) {
        echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
        echo '<li class="m3-breadcrumbs__item m3-breadcrumbs__item--current" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
        echo '<span itemprop="name">' . esc_html( post_type_archive_title( '', false ) ) . '</span>';
        echo '<meta itemprop="position" content="2" /></li>';
    } elseif ( is_search() ) {
        echo '<li class="m3-breadcrumbs__separator"><span class="material-symbols-outlined">chevron_right</span></li>';
        echo '<li class="m3-breadcrumbs__item m3-breadcrumbs__item--current" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
        echo '<span itemprop="name">検索結果: ' . esc_html( get_search_query() ) . '</span>';
        echo '<meta itemprop="position" content="2" /></li>';
    }

    echo '</ol></nav>';
}

/**
 * 4. 動的アーカイブタイトル取得 (h1用)
 */
function node_get_archive_title() {
    if ( is_category() ) {
        return single_cat_title( '', false );
    } elseif ( is_tag() ) {
        return single_tag_title( '', false );
    } elseif ( is_author() ) {
        return get_the_author();
    } elseif ( is_year() ) {
        return get_the_date( 'Y年' );
    } elseif ( is_month() ) {
        return get_the_date( 'Y年m月' );
    } elseif ( is_day() ) {
        return get_the_date( 'Y年m月d日' );
    } elseif ( is_post_type_archive() ) {
        return post_type_archive_title( '', false );
    } elseif ( is_search() ) {
        return '検索結果: ' . get_search_query();
    } elseif ( is_home() ) {
        return 'Luminous Core';
    }
    return '記事一覧';
}
