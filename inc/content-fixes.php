<?php

declare(strict_types=1);
/**
 * 暫定補正。DB側の修正が終わったものから削除してよい。ロジックは凍結。
 *
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * -------------------------------------------------------
 * 5. Branding Normalization & DB Updates
 * -------------------------------------------------------
 */
function node_enforce_branding_update() {
    if ( ! is_admin() || (defined('REST_REQUEST') && REST_REQUEST) ) return;
    $current_name = get_option('blogname');
    if ($current_name === 'CyberNode' || $current_name === 'Node' || empty($current_name)) {
        update_option('blogname', 'Luminous Core');
    }
}
add_action('admin_init', 'node_enforce_branding_update');

function luminous_brand_normalize( $value ) {
    if ( is_string( $value ) ) {
        $trimmed_value = trim( $value );
        if ( in_array( $trimmed_value, array( 'CyberNode', 'Node' ), true ) ) {
            return 'Luminous Core';
        }

        return str_replace( 'CyberNode', 'Luminous Core', $value );
    }
    return $value;
}
add_filter( 'option_blogname', 'luminous_brand_normalize' );
add_filter( 'option_blogdescription', 'luminous_brand_normalize' );
add_filter( 'pre_get_document_title', 'luminous_brand_normalize', 999 );

/**
 * -------------------------------------------------------
 * 6. Slug Sanitization (日本語スラッグ自動回避ロジック)
 * 本番環境でのSEO・シェア時のURL文字化けを防ぎます。
 * -------------------------------------------------------
 */
function luminous_core_auto_post_slug( $slug, $post_ID, $post_status, $post_type ) {
	$slug = (string) $slug;

    // ID未確定（wp_insert_post の新規挿入時は 0）の場合は書き換えない。
    // 書き換えると全記事が「post-0」に衝突し、シングルクエリが複数件を返して
    // ループ二重描画→comments.php の関数再宣言 Fatal を誘発する。
    if ( ! $post_ID ) {
        return $slug;
    }

    // カスタム投稿タイプなどを含め、日本語（URLエンコードされる文字）が含まれているかを判定
    if ( preg_match( '/(%[0-9a-f]{2})+/i', $slug ) || preg_match( '/[^a-z0-9\-]/i', $slug ) ) {
        // 日本語が含まれている場合、一律で「post-投稿ID」の形式に書き換える
        $slug = 'post-' . $post_ID;
    }
    return $slug;
}
add_filter( 'wp_unique_post_slug', 'luminous_core_auto_post_slug', 10, 4 );

/**
 * -------------------------------------------------------
 * 10. カスタムライター情報（追加リンク枠最大5つ）
 * -------------------------------------------------------
 */
function node_user_contact_methods( $methods ) {
    $methods['discord']       = 'Discord (URL)';
    $methods['custom_link_1'] = '追加リンク 1 (URL)';
    $methods['custom_link_2'] = '追加リンク 2 (URL)';
    $methods['custom_link_3'] = '追加リンク 3 (URL)';
    $methods['custom_link_4'] = '追加リンク 4 (URL)';
    $methods['custom_link_5'] = '追加リンク 5 (URL)';
    return $methods;
}
add_filter( 'user_contactmethods', 'node_user_contact_methods' );


/**
 * -------------------------------------------------------
 * 11. 投稿保存時のデフォルトステータス制御
 * -------------------------------------------------------
 * 新規投稿や下書き系からの保存時は「レビュー待ち」に固定する。
 * 公開済み投稿の更新には影響を与えない。
 */
function node_is_auto_draft_placeholder_title( $title ) {
    $normalized_title = trim( wp_strip_all_tags( (string) $title ) );
    return in_array( $normalized_title, array( 'Auto Draft', '自動下書き' ), true );
}

function node_force_default_post_status_on_save( $data, $postarr ) {
    if ( ! isset( $data['post_type'] ) || 'post' !== $data['post_type'] ) {
        return $data;
    }

    if ( isset( $data['post_title'] ) && node_is_auto_draft_placeholder_title( $data['post_title'] ) ) {
        $data['post_title'] = '';
    }

    $incoming_status = $data['post_status'] ?? '';
    if ( 'auto-draft' === $incoming_status ) {
        return $data;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return $data;
    }

    if ( isset( $postarr['ID'] ) && wp_is_post_revision( (int) $postarr['ID'] ) ) {
        return $data;
    }

    // 新規作成時や下書き保存時のみ「レビュー待ち（pending）」に強制する
    // publish（公開）等のその他のステータス変更には干渉せず、WordPressコアの権限チェックに委ねる
    if ( in_array( $incoming_status, array( '', 'draft' ), true ) ) {
        $data['post_status'] = 'pending';
    }

    return $data;
}
add_filter( 'wp_insert_post_data', 'node_force_default_post_status_on_save', 99, 2 );

/**
 * RSS GUID を常に正規パーマリンクにそろえる。
 * これにより localhost / staging / 一時ドメイン由来の GUID 残存を防ぐ。
 */
function node_normalize_feed_guid( $guid, $post_id ) {
    if ( ! is_feed() ) {
        return $guid;
    }

    $post_id = absint( $post_id );
    if ( $post_id <= 0 ) {
        return $guid;
    }

    $permalink = get_permalink( $post_id );
    if ( ! $permalink ) {
        return $guid;
    }

    return $permalink;
}
add_filter( 'the_guid', 'node_normalize_feed_guid', 10, 2 );

/**
 * フッターメニュー内に残っている仮URLを正規URLへ補正する。
 */
function node_fix_footer_menu_placeholder_urls( $items, $args ) {
    if ( empty( $args->theme_location ) || 'footer' !== $args->theme_location ) {
        return $items;
    }

    $privacy_url = function_exists( 'node_get_existing_page_url' ) ? node_get_existing_page_url( '/privacy-policy/' ) : '';
    $contact_url = function_exists( 'node_get_existing_page_url' ) ? node_get_existing_page_url( '/contact/' ) : '';

    foreach ( $items as $item_index => $item ) {
        if ( ! isset( $item->url ) ) {
            continue;
        }

        $item_title = isset( $item->title ) ? wp_strip_all_tags( (string) $item->title ) : '';
        $is_privacy_placeholder = false !== strpos( $item->url, '/sample-page/' )
            || false !== strpos( $item->url, '/sample-page-2/' );
        $is_contact_placeholder = false !== strpos( $item->url, '/post-0-2/' )
            || false !== strpos( $item->url, '/%e3%81%8a%e5%95%8f%e3%81%84%e5%90%88%e3%82%8f%e3%81%9b/' );

        if ( $is_privacy_placeholder
            || false !== strpos( $item_title, 'プライバシーポリシー' )
        ) {
            if ( '' !== $privacy_url ) {
                $item->url = $privacy_url;
            } elseif ( $is_privacy_placeholder ) {
                unset( $items[ $item_index ] );
            }
            continue;
        }

        if ( $is_contact_placeholder
            || false !== strpos( $item_title, 'お問い合わせ' )
        ) {
            if ( '' !== $contact_url ) {
                $item->url = $contact_url;
            } elseif ( $is_contact_placeholder ) {
                unset( $items[ $item_index ] );
            }
        }
    }

    return array_values( $items );
}
add_filter( 'wp_nav_menu_objects', 'node_fix_footer_menu_placeholder_urls', 10, 2 );

/**
 * Aboutページの公開文面を正規表記へ補正する。
 * ※ 固定ページ本文が管理画面で未更新でも、公開画面では適切な文面を表示する。
 */
function node_normalize_about_page_content( $content ) {
    if ( is_admin() || ! is_page() ) {
        return $content;
    }

    $about_page = get_page_by_path( 'about' );
    if ( ! $about_page || get_queried_object_id() !== (int) $about_page->ID ) {
        return $content;
    }

    $keep_first_paragraph = static function( string $html, string $text ): string {
        $pattern = '#<p>\s*' . preg_quote( $text, '#' ) . '\s*</p>#u';
        $seen    = 0;
        return (string) preg_replace_callback(
            $pattern,
            static function( $matches ) use ( &$seen ) {
                $seen++;
                return 1 === $seen ? $matches[0] : '';
            },
            $html
        );
    };

    $duplicate_targets = array(
        'Luminous Coreは、ガジェット、ゲーム、Webサービス、AIに関する最新情報や商品レビューなどをお伝えするブログメディアです。',
        'ブログのロゴはガジェット、ゲーム、AI・Webサービスを構成する３つの光が交差し、その交点を表す部分を切り取り、作成したものです。',
        'ブログ名のLuminous Coreはこれらを構成するイメージカラーから着想を経て、命名したものであり、赤が「ゲーム」、青が「ガジェット」、緑が「Webサービス・AI」を司ることを意味しています。',
    );
    foreach ( $duplicate_targets as $target ) {
        $content = $keep_first_paragraph( $content, $target );
    }

    $replacements = array(
        'wingzone94を中心とする複数のメンバーで複製され、日々、記事制作に取り組んでいます。'
            => 'wingzone94を中心とする複数のメンバーで構成され、日々、記事制作に取り組んでいます。',
        '<strong>2026年5月下旬</strong>：正式サービス開始（予定）Luminous Coreは、ガジェット、ゲーム、Webサービス、AIに関する最新情報や商品レビューなどをお伝えするブログメディアです。'
            => '<strong>2026年5月下旬</strong>：正式サービス開始（予定）。Luminous Coreは、ガジェット、ゲーム、Webサービス、AIに関する最新情報や商品レビューなどをお伝えするブログメディアです。',
        '略称（才能）の響きが自分たちのスタイルに対して少し主張が強すぎると感じたため、'
            => '旧ブログ名の略称の響きが自分たちのスタイルに対して少し主張が強すぎると感じたため、',
        'Server：Conoha Wing'
            => 'Server: Conoha Wing',
        'Server：'
            => 'Server: ',
    );
    $content = str_replace( array_keys( $replacements ), array_values( $replacements ), $content );

    $content = (string) preg_replace(
        '#<td>\s*X(?:\s*<br\s*/?>\s*Threads)?\s*</td>#u',
        '<td><a href="https://x.com/LuminousCoreJP" target="_blank" rel="noopener noreferrer">X</a></td>',
        $content
    );

    // 「Threads」を案内文や運営情報表から完全に除去する。
    $content = str_replace(
        array( '公式SNS：X / Threads', '公式SNS: X / Threads', 'X / Threads', 'Threads' ),
        array( '公式SNS：X', '公式SNS: X', 'X', '' ),
        $content
    );

    return $content;
}
add_filter( 'the_content', 'node_normalize_about_page_content', 30 );
