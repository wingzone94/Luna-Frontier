<?php
/**
 * Luna Library スプラッシュ画面
 *
 * 無効化: フィルタ luna_library_splash_enabled が false を返せば全体が止まる。
 *
 * @package Node_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LL_SPLASH_ASSET_BASE' ) ) {
	define(
		'LL_SPLASH_ASSET_BASE',
		defined( 'NODE_LIBRARY_URL' )
			? untrailingslashit( NODE_LIBRARY_URL ) . '/assets/luna-library-splash'
			: get_stylesheet_directory_uri() . '/assets/luna-library-splash'
	);
}

/**
 * スプラッシュを表示すべきリクエストかどうか。
 */
function ll_splash_should_show() {
	if ( ! apply_filters( 'luna_library_splash_enabled', true ) ) {
		return false;
	}
	// Luna Library のアーカイブと詳細ページのみ。
	return is_post_type_archive( 'node_library' ) || is_singular( 'node_library' );
}

/**
 * head 内: セッション内2回目以降は CSS で即座に非表示にするための印付けと、
 * 本体 CSS/JS の読み込み。
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! ll_splash_should_show() ) {
		return;
	}
	$base_ver = defined( 'NODE_LIBRARY_VERSION' ) ? NODE_LIBRARY_VERSION : '1.0.0';
	$css_path = defined( 'NODE_LIBRARY_DIR' ) ? NODE_LIBRARY_DIR . 'assets/luna-library-splash/splash.css' : '';
	$js_path  = defined( 'NODE_LIBRARY_DIR' ) ? NODE_LIBRARY_DIR . 'assets/luna-library-splash/splash.js' : '';
	$css_ver  = ( '' !== $css_path && file_exists( $css_path ) ) ? $base_ver . '.' . filemtime( $css_path ) : $base_ver;
	$js_ver   = ( '' !== $js_path && file_exists( $js_path ) ) ? $base_ver . '.' . filemtime( $js_path ) : $base_ver;

	wp_enqueue_style( 'luna-library-splash', LL_SPLASH_ASSET_BASE . '/splash.css', array(), $css_ver );
	wp_enqueue_script( 'luna-library-splash', LL_SPLASH_ASSET_BASE . '/splash.js', array(), $js_ver, true );
} );

add_action( 'wp_head', function () {
	if ( ! ll_splash_should_show() ) {
		return;
	}
	// 2回目以降の表示を防ぐ（JS 無効時は CSS のフェイルセーフで自動的に消える）。
	echo '<script>try{if(sessionStorage.getItem("ll_splash_seen")==="1"){document.documentElement.classList.add("ll-splash-seen");}}catch(e){}</script>' . "\n";
}, 1 );

/**
 * body 末尾: スプラッシュ本体のマークアップ。
 */
add_action( 'wp_footer', function () {
	if ( ! ll_splash_should_show() ) {
		return;
	}
	?>
	<div class="ll-splash" id="ll-splash" role="presentation" aria-hidden="true">
		<div class="ll-splash__inner">
			<picture>
				<source srcset="<?php echo esc_url( LL_SPLASH_ASSET_BASE . '/luna-library-logo-white.webp' ); ?>" type="image/webp">
				<img class="ll-splash__logo" src="<?php echo esc_url( LL_SPLASH_ASSET_BASE . '/luna-library-logo-white.png' ); ?>" alt="" width="1200" height="400" decoding="async">
			</picture>
			<p class="ll-splash__tagline">Luminous Core のゲーム・アプリライブラリ</p>
		</div>
	</div>
	<?php
}, 5 );
