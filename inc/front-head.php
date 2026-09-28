<?php

declare(strict_types=1);
/**
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * -------------------------------------------------------
 * 4. Service Worker の登録 (重複排除・最適化)
 * -------------------------------------------------------
 */
function node_register_service_worker() {
    // header.phpではなく、安全にフッターで遅延登録する
	echo '<script>
		window.addEventListener("load", () => {
			if ("serviceWorker" in navigator) {
				navigator.serviceWorker.register("' . esc_url( NODE_THEME_URI . '/sw.js' ) . '")
				.then(() => {})
				.catch(err => console.error("SW registration failed: ", err));
			}
		});
	</script>';
}
add_action( 'wp_footer', 'node_register_service_worker' );

/**
 * -------------------------------------------------------
 * 7. Font Preconnect (存在しないフォントファイルのプリロードは削除)
 * -------------------------------------------------------
 */
function node_preload_webfonts() {
    // Google Fonts preconnect のみ（ローカルフォントファイルは存在しないため削除）
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}
add_action( 'wp_head', 'node_preload_webfonts', 1 );

/**
 * -------------------------------------------------------
 * 7.5. body 非表示フォールバック（JSが失敗した場合の保険）
 *
 * style.css の `body { opacity: 0; visibility: hidden }` は
 * JS が `body.is-loaded` を付与することで解除される設計だが、
 * CDN の GSAP 読み込み失敗などで JS がエラーになった場合に
 * ページが真っ白のままになる。
 * noscript タグと JS フォールバックで確実に表示させる。
 * -------------------------------------------------------
 */
function node_body_visibility_fallback() {
    echo '<noscript><style>body { opacity: 1 !important; visibility: visible !important; }</style></noscript>' . "\n";
    // JS が遅延しても最大2秒後には強制表示するフォールバック
    echo '<script>
        (function() {
            var timeout = setTimeout(function() {
                document.body.classList.add("is-loaded");
            }, 2000);
            document.addEventListener("DOMContentLoaded", function() {
                clearTimeout(timeout);
                // main.js が is-loaded を付与するが、失敗した場合のフォールバック
                setTimeout(function() {
                    if (!document.body.classList.contains("is-loaded")) {
                        document.body.classList.add("is-loaded");
                    }
                }, 500);
            });
        })();
    </script>' . "\n";
}
add_action( 'wp_head', 'node_body_visibility_fallback', 2 );

/**
 * -------------------------------------------------------
 * 8. Script Async & Module Loading (TBT Optimization)
 * -------------------------------------------------------
 */
function node_script_loader_tag($tag, $handle, $src) {
    // Vite handles: node-vite-*
    // Force module script so browser can parse `import/export` bundles.
    if (strpos($handle, 'node-vite-') === 0) {
        if (strpos($tag, 'type="module"') === false) {
            $tag = str_replace('<script ', '<script type="module" crossorigin ', $tag);
        }
    }
    return $tag;
}
add_filter('script_loader_tag', 'node_script_loader_tag', 10, 3);

/**
 * -------------------------------------------------------
 * 6. FOUC対策の修正 — オレンジフラッシュ除去 & PageSpeed最適化
 * -------------------------------------------------------
 * 旧実装: html bg=#f90 + body opacity:0 → JS で is-loaded 付与
 * 問題点: JS実行までオレンジ色しか表示されず、LCP を著しく遅延させていた
 * 新実装: html/body を最初から表示。アニメーション演出は is-loaded で行う（任意）
 */
function node_critical_inline_styles() {
    // フロントエンド: 最優先でレンダリングブロックを解除
    if ( ! is_admin() ) {
        // 背景色は @media screen 限定にする（印刷時に _print.css の白背景を潰さないため）
        echo '<style id="node-critical-fouc-fix">
            @media screen {
                html {
                    background-color: #FFF4E5 !important;
                }
                html[data-theme="dark"],
                body[data-theme="dark"] ~ html,
                [data-theme="dark"] {
                    background-color: #1B1812 !important;
                }
            }
            body {
                opacity: 1 !important;
                visibility: visible !important;
            }
        </style>';
    }
}
add_action( 'wp_head', 'node_critical_inline_styles', 1 );

/**
 * 管理画面 / エディタ用の表示保護
 */
function node_fix_admin_visibility() {
    echo '<style>
        body.wp-admin {
            opacity: 1 !important;
            visibility: visible !important;
            background-color: #f1f1f1 !important;
        }
        .editor-styles-wrapper {
            opacity: 1 !important;
            visibility: visible !important;
            background-color: #ffffff !important;
        }
        html.wp-toolbar {
            background-color: #f1f1f1 !important;
        }
    </style>';
}
add_action( 'admin_head', 'node_fix_admin_visibility' );
add_action( 'enqueue_block_editor_assets', 'node_fix_admin_visibility' );
