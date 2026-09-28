<?php

declare(strict_types=1);
/**
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep WordPress footnotes at the bottom of the currently displayed page.
 *
 * Split posts can otherwise show the generated footnote list before later pages.
 * The inline references stay in place, so tooltip-style UI can be layered on top
 * while the canonical bottom footnotes remain available for every page.
 */
function node_get_footnote_multipage_url( $page_number ) {
    $link = _wp_link_page( max( 1, (int) $page_number ) );

    if ( preg_match( '/href=(["\'])(.*?)\1/', $link, $match ) ) {
        return html_entity_decode( $match[2], ENT_QUOTES, get_bloginfo( 'charset' ) );
    }

    return get_permalink();
}

function node_extract_footnote_references_from_html( $html ) {
    $result = array(
        'ids'     => array(),
        'numbers' => array(),
    );

    if ( ! is_string( $html ) || '' === $html ) {
        return $result;
    }

    if ( ! preg_match_all( '#<sup\b(?=[^>]*\bdata-fn=(["\'])([^"\']+)\1)[^>]*>.*?</sup>#is', $html, $references, PREG_SET_ORDER ) ) {
        return $result;
    }

    foreach ( $references as $reference ) {
        $id = html_entity_decode( $reference[2], ENT_QUOTES, get_bloginfo( 'charset' ) );

        if ( ! in_array( $id, $result['ids'], true ) ) {
            $result['ids'][] = $id;
        }

        if ( preg_match( '#<a\b[^>]*>(\d+)</a>#is', $reference[0], $number_match ) ) {
            $result['numbers'][ $id ] = (int) $number_match[1];
        }
    }

    return $result;
}

function node_render_footnote_section( $groups, $current_page, $total_pages ) {
    $groups = array_filter(
        $groups,
        static function ( $group ) {
            return ! empty( $group['items'] );
        }
    );

    if ( empty( $groups ) ) {
        return '';
    }

    ksort( $groups, SORT_NUMERIC );

    $post_id       = get_the_ID();
    $section_id    = 'node-footnotes-' . $post_id;
    $current_count = isset( $groups[ (int) $current_page ]['count'] )
        ? (int) $groups[ (int) $current_page ]['count']
        : (int) reset( $groups )['count'];
    $article_count = array_sum( array_map( 'intval', array_column( $groups, 'count' ) ) );
    $is_tabbed     = count( $groups ) > 1;
    $classes       = 'node-footnotes ' . ( $is_tabbed ? 'node-footnotes--tabs' : 'node-footnotes--single' );
    $attributes    = ' data-node-footnotes' . ( $is_tabbed ? ' data-node-footnote-tabs' : '' );
    $info_id       = $section_id . '-info';

    $html  = sprintf( '<section class="%1$s"%2$s aria-label="脚注">', esc_attr( $classes ), $attributes );
    $html .= '<div class="node-footnotes__header">';
    $html .= '<span class="node-footnotes__info-label">脚注について</span>';
    $html .= sprintf(
        '<button class="node-footnotes__info-toggle" type="button" aria-label="脚注の説明を表示" aria-expanded="false" aria-controls="%1$s" data-footnote-info-toggle><span class="material-symbols-outlined" aria-hidden="true">info</span></button>',
        esc_attr( $info_id )
    );
    $html .= sprintf(
        '<span class="node-footnotes__info-panel" id="%1$s" hidden>脚注は本文の補足です。番号で切替、↩︎で戻ります。</span>',
        esc_attr( $info_id )
    );
    $html .= sprintf(
        '<div class="node-footnotes__meta" data-footnote-count="%1$d" data-footnote-total-count="%2$d"><span class="node-footnotes__meta-desktop">このページの脚注：%1$d件 / 記事全体：%2$d件</span><span class="node-footnotes__meta-mobile">ページ %1$d件 / 全体 %2$d件</span></div>',
        $current_count,
        $article_count
    );
    $html .= '</div>';

    if ( $is_tabbed ) {
        $html .= '<div class="node-footnotes__tabs" role="tablist" aria-label="脚注のページ">';
        foreach ( $groups as $page_number => $group ) {
            $is_current = (int) $page_number === (int) $current_page;
            $tab_id     = sprintf( '%1$s-tab-%2$d', $section_id, (int) $page_number );
            $panel_id   = sprintf( '%1$s-panel-%2$d', $section_id, (int) $page_number );
            $label      = (string) (int) $page_number;
            $aria_label = $is_current ? sprintf( '%dページ 現在', (int) $page_number ) : sprintf( '%dページ', (int) $page_number );

            $html .= sprintf(
                '<button class="node-footnotes__tab" id="%1$s" type="button" role="tab" aria-selected="%2$s" aria-controls="%3$s" tabindex="%4$s" aria-label="%5$s" data-footnote-tab="%6$d" data-footnote-count="%7$d"><span class="node-footnotes__tab-label">%8$s</span></button>',
                esc_attr( $tab_id ),
                $is_current ? 'true' : 'false',
                esc_attr( $panel_id ),
                $is_current ? '0' : '-1',
                esc_attr( $aria_label ),
                (int) $page_number,
                (int) $group['count'],
                esc_html( $label )
            );
        }
        $html .= '</div>';
    }

    $html .= '<div class="node-footnotes__panels">';
    foreach ( $groups as $page_number => $group ) {
        $is_current = (int) $page_number === (int) $current_page;
        $tab_id     = sprintf( '%1$s-tab-%2$d', $section_id, (int) $page_number );
        $panel_id   = sprintf( '%1$s-panel-%2$d', $section_id, (int) $page_number );
        $start_attr = $group['first_number'] > 1 ? ' start="' . esc_attr( (string) $group['first_number'] ) . '"' : '';
        $panel_attr = $is_tabbed
            ? sprintf(
                ' id="%1$s" role="tabpanel" aria-labelledby="%2$s" data-footnote-panel="%3$d"%4$s',
                esc_attr( $panel_id ),
                esc_attr( $tab_id ),
                (int) $page_number,
                $is_current ? '' : ' hidden'
            )
            : '';
        $list_class = 'wp-block-footnotes node-current-page-footnotes';

        if ( ! $is_current ) {
            $list_class .= ' node-other-page-footnotes';
        }

        $html .= sprintf( '<div class="node-footnotes__panel"%s>', $panel_attr );
        $html .= sprintf(
            '<ol class="%1$s" data-footnote-page="%2$d"%3$s>%4$s</ol>',
            esc_attr( $list_class ),
            (int) $page_number,
            $start_attr,
            implode( '', $group['items'] )
        );
        $html .= '</div>';
    }
    $html .= '</div></section>';

    return $html;
}

function node_reposition_current_page_footnotes( $content ) {
    if ( is_admin() || is_feed() || ! is_singular() || false === strpos( $content, 'data-fn=' ) ) {
        return $content;
    }

    if ( false !== strpos( $content, 'node-current-page-footnotes' ) ) {
        return $content;
    }

    $existing_footnotes = array();
    $content_without_footnotes = preg_replace_callback(
        '#<ol\b[^>]*\bclass=(["\'])[^"\']*\bwp-block-footnotes\b[^"\']*\1[^>]*>.*?</ol>#is',
        static function ( $matches ) use ( &$existing_footnotes ) {
            if ( preg_match_all( '#<li\b[^>]*\bid=(["\'])([^"\']+)\1[^>]*>(.*?)</li>#is', $matches[0], $items, PREG_SET_ORDER ) ) {
                foreach ( $items as $item ) {
                    $existing_footnotes[ html_entity_decode( $item[2], ENT_QUOTES, get_bloginfo( 'charset' ) ) ] = $item[3];
                }
            }

            return '';
        },
        $content
    );

    if ( ! is_string( $content_without_footnotes ) ) {
        return $content;
    }

    $current_references = node_extract_footnote_references_from_html( $content_without_footnotes );

    if ( empty( $current_references['ids'] ) ) {
        return $content_without_footnotes;
    }

    $footnotes = array();
    $raw_meta = get_post_meta( get_the_ID(), 'footnotes', true );
    if ( is_string( $raw_meta ) && '' !== $raw_meta ) {
        $decoded_meta = json_decode( $raw_meta, true );
        if ( is_array( $decoded_meta ) ) {
            foreach ( $decoded_meta as $footnote ) {
                if ( ! empty( $footnote['id'] ) && isset( $footnote['content'] ) ) {
                    $footnotes[ (string) $footnote['id'] ] = (string) $footnote['content'];
                }
            }
        }
    }

    $footnotes = array_merge( $existing_footnotes, $footnotes );

    global $page, $numpages;
    $current_page = max( 1, (int) $page );
    $total_pages  = max( 1, (int) $numpages );
    $page_refs    = array();

    if ( $total_pages > 1 ) {
        $raw_post_content = get_post_field( 'post_content', get_the_ID() );
        $raw_pages        = is_string( $raw_post_content ) ? preg_split( '/<!--nextpage-->/', $raw_post_content ) : array();

        if ( is_array( $raw_pages ) && count( $raw_pages ) > 1 ) {
            foreach ( $raw_pages as $index => $raw_page_content ) {
                $page_refs[ $index + 1 ] = node_extract_footnote_references_from_html( $raw_page_content );
            }
        }
    }

    if ( empty( $page_refs[ $current_page ]['ids'] ) ) {
        $page_refs[ $current_page ] = $current_references;
    }

    $groups = array();
    foreach ( $page_refs as $page_number => $references ) {
        if ( empty( $references['ids'] ) ) {
            continue;
        }

        $items = array();
        foreach ( $references['ids'] as $id ) {
            if ( ! isset( $footnotes[ $id ] ) ) {
                continue;
            }

            $number = $references['numbers'][ $id ] ?? ( count( $items ) + 1 );
            $target = sprintf( '#%s-link', $id );

            if ( (int) $page_number !== $current_page ) {
                $target = node_get_footnote_multipage_url( (int) $page_number ) . $target;
            }

            $return_link_pattern = '#\s*<a\b[^>]*href=["\']' . preg_quote( '#' . $id . '-link', '#' ) . '["\'][^>]*>.*?</a>\s*#is';
            $footnote_content = preg_replace(
                array(
                    $return_link_pattern,
                    '#\s*<a\b[^>]*aria-label=["\']脚注参照\d+にジャンプ["\'][^>]*>.*?</a>\s*#u',
                ),
                array( ' ', ' ' ),
                $footnotes[ $id ]
            );
            $footnote_content = is_string( $footnote_content ) ? trim( $footnote_content ) : $footnotes[ $id ];

            $items[] = sprintf(
                '<li id="%1$s">%2$s <a href="%3$s" aria-label="%4$s">↩︎</a></li>',
                esc_attr( $id ),
                wp_kses_post( $footnote_content ),
                esc_url( $target ),
                esc_attr( sprintf( '脚注参照%dにジャンプ', $number ) )
            );
        }

        if ( empty( $items ) ) {
            continue;
        }

        $first_id = $references['ids'][0] ?? '';
        $groups[ (int) $page_number ] = array(
            'items'        => $items,
            'count'        => count( $items ),
            'first_number' => $references['numbers'][ $first_id ] ?? 1,
        );
    }

    if ( empty( $groups ) ) {
        return $content_without_footnotes;
    }

    $footnote_section = node_render_footnote_section( $groups, $current_page, $total_pages );

    return rtrim( $content_without_footnotes ) . "\n\n" . $footnote_section;
}
add_filter( 'the_content', 'node_reposition_current_page_footnotes', 999 );
