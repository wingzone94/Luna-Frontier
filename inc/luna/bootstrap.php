<?php
/**
 * Luna Frontier 2.0 bootstrap
 *
 * Luna Frontier は Node の子テーマではない。Node 1.x を起点に発展した独立テーマ。
 * Template ヘッダーは置かない。Node テーマのインストールは不要。
 *
 * 原則:
 * - このテーマ自身のファイルと assets を読む。別テーマの実体は参照しない。
 * - 既存サイトの _node_* / node_* / lc_* 等は rename しない。親テーマ互換ではなく、公開済みデータとの後方互換。
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUNA_FRONTIER_DIR', get_stylesheet_directory() );
define( 'LUNA_FRONTIER_URI', get_stylesheet_directory_uri() );
define( 'LUNA_FRONTIER_CODENAME', 'SkyAlow' );

require_once LUNA_FRONTIER_DIR . '/inc/luna/dynamic-color.php';
require_once LUNA_FRONTIER_DIR . '/inc/luna/reading-aside.php';
require_once LUNA_FRONTIER_DIR . '/inc/luna/category-label.php';
require_once LUNA_FRONTIER_DIR . '/inc/luna/writer-avatar.php';

/**
 * このテーマのバージョン（style.css の Version）。
 */
function luna_frontier_version(): string {
	$version = wp_get_theme()->get( 'Version' );

	return ( is_string( $version ) && '' !== $version ) ? $version : '0.0.0';
}

/**
 * フッターなど利用者向けに見せるメジャーバージョン。
 */
function luna_frontier_display_version(): string {
	return '2.0';
}

/**
 * ビルド成果物のパスを Vite manifest から解決する。
 *
 * node_resolve_vite_build()（inc/theme-setup.php）を唯一の入力とし、Luna 側で
 * manifest パスや /assets/ 固定を持たない。dist / assets のどちらを唯一の入
 * 力にしても（ミックスしないので）エントリが自ずと揃う。
 *
 * @param string $entry manifest のキー（例: 'src/styles/luna.css'）。
 * @return array{file:string,css:string[],base:string}|null
 */
function luna_frontier_manifest_entry( string $entry ): ?array {
	static $resolved = array();

	if ( ! isset( $resolved[ $entry ] ) ) {
		$build = node_resolve_vite_build( LUNA_FRONTIER_DIR );
		if ( null === $build ) {
			$resolved[ $entry ] = null;
		} else {
			$manifest = $build['manifest'];
			if ( ! isset( $manifest[ $entry ] ) || ! is_array( $manifest[ $entry ] ) ) {
				$resolved[ $entry ] = null;
			} else {
				$file = $manifest[ $entry ]['file'] ?? '';
				if ( ! is_string( $file ) || '' === $file ) {
					$resolved[ $entry ] = null;
				} else {
					$css = $manifest[ $entry ]['css'] ?? array();
					$resolved[ $entry ] = array(
						'file' => $file,
						'css'  => is_array( $css ) ? $css : array(),
						'base' => $build['base'],
					);
				}
			}
		}
	}

	return $resolved[ $entry ];
}

/**
 * ファイル更新時刻をバージョン文字列に使う（ハッシュなしビルドのキャッシュ対策）。
 *
 * @param string      $relative_file manifest の file（例: js/luna.js）。
 * @param string|null $base          成果物の base（dist / assets）。entry の base を前提に呼ぶ。
 */
function luna_frontier_asset_version( string $relative_file, ?string $base = null ): string {
	$base    = $base ?? 'assets';
	$absolute = LUNA_FRONTIER_DIR . '/' . $base . '/' . ltrim( $relative_file, '/' );
	$mtime    = is_readable( $absolute ) ? filemtime( $absolute ) : false;

	return false !== $mtime ? luna_frontier_version() . '.' . $mtime : luna_frontier_version();
}

/**
 * manifest の base から公開URLを組む。/assets/ 固定を持たない。
 */
function luna_frontier_asset_url( string $base, string $relative_file ): string {
	return LUNA_FRONTIER_URI . '/' . $base . '/' . ltrim( $relative_file, '/' );
}

/**
 * 2.0 固有アセットの読み込み。
 *
 * Node 由来の ThemeSupport::enqueueAssets() が優先度 10 でベース資産を登録する。
 * こちらは 20 で後から載せ、差分 CSS がベースの後に来るようにする。
 */
function luna_frontier_enqueue_assets(): void {
	// フォントは header が読み込む Manrope / Inter / Noto Sans JP だけを使う。
	// 当初は機能ラベル用に Orbitron を追加していたが、2026-08-09 のユーザー指示で不採用。

	$legacy_archive = luna_frontier_is_legacy_archive();
	$style = luna_frontier_manifest_entry( $legacy_archive ? 'src/luna/styles/luna-archive.css' : 'src/luna/styles/luna.css' );

	if ( null !== $style ) {
		// CSS entry は Vite の版により 'file' 直接／'css' 配列経由のどちらにもなり得る。
		$style_file = $style['css'][0] ?? $style['file'];

		if ( '.css' === substr( $style_file, -4 ) ) {
			wp_enqueue_style(
				$legacy_archive ? 'luna-frontier-archive' : 'luna-frontier',
				luna_frontier_asset_url( $style['base'], $style_file ),
				array(),
				luna_frontier_asset_version( $style_file, $style['base'] )
			);
		}
	}

	$script = luna_frontier_manifest_entry( 'src/luna/luna.js' );
	if ( get_query_var( 'node_spotlight' ) ) {
		$spotlight_style = luna_frontier_manifest_entry( 'src/luna/styles/luna-spotlight.css' );
		if ( null !== $spotlight_style ) {
			$spotlight_file = $spotlight_style['css'][0] ?? $spotlight_style['file'];
			wp_enqueue_style(
				'luna-frontier-spotlight',
				luna_frontier_asset_url( $spotlight_style['base'], $spotlight_file ),
				array( 'luna-frontier' ),
				luna_frontier_asset_version( $spotlight_file, $spotlight_style['base'] )
			);
		}
	}

	if ( null !== $script ) {
		wp_enqueue_script(
			'luna-frontier',
			luna_frontier_asset_url( $script['base'], $script['file'] ),
			array(),
			luna_frontier_asset_version( $script['file'], $script['base'] ),
			true
		);
	}
}
add_action( 'wp_enqueue_scripts', 'luna_frontier_enqueue_assets', 20 );

/**
 * Luna Frontier の script を type="module" で配信する（Vite 出力は ESM）。
 */
function luna_frontier_script_module_type( string $tag, string $handle ): string {
	if ( 'luna-frontier' !== $handle ) {
		return $tag;
	}

	if ( false !== strpos( $tag, ' type=' ) ) {
		return str_replace( ' type=\'text/javascript\'', ' type=\'module\'', str_replace( ' type="text/javascript"', ' type="module"', $tag ) );
	}

	return str_replace( '<script ', '<script type="module" ', $tag );
}
add_filter( 'script_loader_tag', 'luna_frontier_script_module_type', 10, 2 );

/**
 * body_class に Luna Frontier の識別子を足す。
 * Node 由来の既存クラスは外さない。テンプレートと CSS が依存している。
 *
 * @param string[] $classes body class 一覧。
 * @return string[]
 */
function luna_frontier_body_class( array $classes ): array {
	$classes[] = 'lf-theme';
	if ( luna_frontier_is_legacy_archive() ) {
		$classes[] = 'lf-legacy-archive';
	}

	return $classes;
}
add_filter( 'body_class', 'luna_frontier_body_class' );

/** /spotlight/ の既存ルートを解決したあと、Luna のアーカイブテンプレートを使う。 */
function luna_frontier_spotlight_template( string $template ): string {
	if ( get_query_var( 'node_spotlight' ) ) {
		return LUNA_FRONTIER_DIR . '/template-parts/spotlight-archive.php';
	}
	return $template;
}
add_filter( 'template_include', 'luna_frontier_spotlight_template', 100 );

/** Use a published article's cover from the same feature, never an unrelated image. */
function luna_frontier_spotlight_image_id( array $feature ): int {
	$term = get_term_by( 'slug', (string) ( $feature['slug'] ?? '' ), 'category' );
	if ( ! $term instanceof WP_Term ) {
		return 0;
	}
	$posts = get_posts(
		array(
			'category'       => $term->term_id,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_thumbnail_id',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	return $posts ? (int) get_post_thumbnail_id( $posts[0] ) : 0;
}

/** All-articles keeps the Node 1.3.2 content presentation, including pagination. */
function luna_frontier_is_legacy_archive(): bool {
	return (bool) get_query_var( 'node_all_articles' );
}

/**
 * トピックナビ用のメニュー位置を追加する。
 *
 * 既存の primary / footer は残す。未設定のあいだは
 * template-parts/luna/topic-nav.php が上位カテゴリで自動的に埋める。
 */
function luna_frontier_register_menus(): void {
	register_nav_menus(
		array(
			'luna_topics' => __( 'トピック（Luna Frontier）', 'node' ),
		)
	);
}
add_action( 'after_setup_theme', 'luna_frontier_register_menus', 20 );

/** Include the editorial home icons in the existing font subset. */
function luna_frontier_home_icon_names( array $names ): array {
	return array_merge( $names, array( 'star', 'label' ) );
}
add_filter( 'node_icon_font_names', 'luna_frontier_home_icon_names' );
