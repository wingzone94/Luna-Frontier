<?php
/**
 * Luna Frontier 2.0 / SkyAlow Prototype — bootstrap
 *
 * 親テーマ: Node 1.3（Template: node）
 *
 * 原則:
 * - 親テーマのファイル・assets を書き換えない。差分だけをここから足す。
 * - 親の内部データ（_node_* / node_* / lc_* 等）は rename しない。互換のまま読む。
 * - Node 1.3 へ不可逆な DB migration を行わない（管理画面で戻すだけで復帰できること）。
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUNA_FRONTIER_DIR', get_template_directory() );
define( 'LUNA_FRONTIER_URI', get_template_directory_uri() );
define( 'LUNA_FRONTIER_CODENAME', 'SkyAlow' );

require_once LUNA_FRONTIER_DIR . '/inc/luna/dynamic-color.php';
require_once LUNA_FRONTIER_DIR . '/inc/luna/reading-aside.php';
require_once LUNA_FRONTIER_DIR . '/inc/luna/category-label.php';
require_once LUNA_FRONTIER_DIR . '/inc/luna/writer-avatar.php';

/**
 * 子テーマ自身のバージョン（style.css の Version）。
 * 親のバージョンは NODE_THEME_VERSION が保持する。
 */
function luna_frontier_version(): string {
	$version = wp_get_theme( get_template() )->get( 'Version' );

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
 * @param string $entry manifest のキー（例: 'src/styles/luna.css'）。
 * @return array{file:string,css:string[]}|null
 */
function luna_frontier_manifest_entry( string $entry ): ?array {
	static $manifest = null;

	if ( null === $manifest ) {
		$path     = LUNA_FRONTIER_DIR . '/assets/.vite/manifest.json';
		$manifest = array();

		if ( is_readable( $path ) ) {
			$decoded = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $decoded ) ) {
				$manifest = $decoded;
			}
		}
	}

	if ( ! isset( $manifest[ $entry ] ) || ! is_array( $manifest[ $entry ] ) ) {
		return null;
	}

	$file = $manifest[ $entry ]['file'] ?? '';
	if ( ! is_string( $file ) || '' === $file ) {
		return null;
	}

	$css = $manifest[ $entry ]['css'] ?? array();

	return array(
		'file' => $file,
		'css'  => is_array( $css ) ? $css : array(),
	);
}

/**
 * ファイル更新時刻をバージョン文字列に使う（ハッシュなしビルドのキャッシュ対策）。
 */
function luna_frontier_asset_version( string $relative_file ): string {
	$absolute = LUNA_FRONTIER_DIR . '/assets/' . ltrim( $relative_file, '/' );
	$mtime    = is_readable( $absolute ) ? filemtime( $absolute ) : false;

	return false !== $mtime ? luna_frontier_version() . '.' . $mtime : luna_frontier_version();
}

/**
 * 2.0 固有アセットの読み込み。
 *
 * 親テーマは NodeTheme\Setup\ThemeSupport::enqueueAssets() を優先度 10 で登録するため、
 * こちらは 20 で走らせて必ず親の後に載せる（= 低い specificity でも上書きが効く）。
 */
function luna_frontier_enqueue_assets(): void {
	// フォントは親 header.php が読み込む Manrope / Inter / Noto Sans JP だけを使う。
	// 当初は機能ラベル用に Orbitron を追加していたが、2026-08-09 のユーザー指示で不採用。
	// 追加ウェイトを読まないぶん、フォント読み込みは Node 1.3 と同じコストになる。

	$legacy_archive = luna_frontier_is_legacy_archive();
	$style = luna_frontier_manifest_entry( $legacy_archive ? 'src/luna/styles/luna-archive.css' : 'src/luna/styles/luna.css' );

	if ( null !== $style ) {
		// CSS entry は Vite の版により 'file' 直接／'css' 配列経由のどちらにもなり得る。
		$style_file = $style['css'][0] ?? $style['file'];

		if ( '.css' === substr( $style_file, -4 ) ) {
			wp_enqueue_style(
				$legacy_archive ? 'luna-frontier-archive' : 'luna-frontier',
				LUNA_FRONTIER_URI . '/assets/' . $style_file,
				array(),
				luna_frontier_asset_version( $style_file )
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
				LUNA_FRONTIER_URI . '/assets/' . $spotlight_file,
				array( 'luna-frontier' ),
				luna_frontier_asset_version( $spotlight_file )
			);
		}
	}

	if ( null !== $script ) {
		wp_enqueue_script(
			'luna-frontier',
			LUNA_FRONTIER_URI . '/assets/' . $script['file'],
			array(),
			luna_frontier_asset_version( $script['file'] ),
			true
		);
	}
}
add_action( 'wp_enqueue_scripts', 'luna_frontier_enqueue_assets', 20 );

/**
 * 子テーマの script を type="module" で配信する（Vite 出力は ESM）。
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
 * 親テーマ由来のクラスは一切外さない。
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

/** Use Luna's archive after the parent has resolved the existing /spotlight/ route. */
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
 * 親テーマの primary / footer には手を触れない。未設定のあいだは
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
