<?php
/**
 * WordPress theme-support registration.
 *
 * @package NodeTheme
 */

declare(strict_types=1);

namespace NodeTheme\Setup;

/**
 * Registers the theme features owned by Node's presentation layer.
 */
final class ThemeSupport {
	/**
	 * Register WordPress hooks.
	 */
	public static function register(): void {
		add_action( 'after_setup_theme', array( self::class, 'setup' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueueAssets' ) );
	}

	/**
	 * Configure supported WordPress theme features.
	 */
	public static function setup(): void {
		load_theme_textdomain( 'node', get_template_directory() . '/languages' );

		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'align-wide' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'wp-block-styles' );

		register_nav_menus(
			array(
				'primary' => __( 'メインメニュー', 'node' ),
				'footer'  => __( 'フッターメニュー', 'node' ),
			)
		);

		add_theme_support(
			'html5',
			array(
				'search-form',
				'comment-form',
				'comment-list',
				'gallery',
				'caption',
				'script',
				'style',
				'navigation-widgets',
			)
		);

		add_theme_support(
			'custom-logo',
			array(
				'height'      => 64,
				'width'       => 64,
				'flex-width'  => true,
				'flex-height' => true,
			)
		);

		register_block_style(
			'core/table',
			array(
				'name'  => 'sortable',
				'label' => '並べ替え可能',
			)
		);
	}

	/**
	 * Enqueue Vite-built scripts and styles.
	 */
	public static function enqueueAssets(): void {
		$build = node_resolve_vite_build();

		if ( null !== $build ) {
			self::enqueueMainScript( $build );
			self::enqueueMainStyles( $build );
		}

		luminous_enqueue_plugin_scripts();
	}

	/**
	 * Enqueue the main ES module and its imported chunks.
	 *
	 * @param array{base:string,manifest:array} $build node_resolve_vite_build() の解決結果。
	 */
	private static function enqueueMainScript( array $build ): void {
		$manifest = $build['manifest'];

		if ( ! isset( $manifest['src/main.js']['file'] ) ) {
			return;
		}

		$seen    = array();
		$handles = self::registerViteChain( $manifest, 'src/main.js', $seen, $build['base'] );

		foreach ( $handles as $handle ) {
			wp_enqueue_script( $handle );
		}

		$main_handle = end( $handles );
		if ( false === $main_handle ) {
			return;
		}

		wp_localize_script(
			$main_handle,
			'm3_ajax',
			array(
				'ajax_url'          => admin_url( 'admin-ajax.php' ),
				'home_url'          => home_url( '/' ),
				'all_articles_url'  => node_get_all_articles_url(),
				'tag_search_url'      => rest_url( 'luminous-core-engine/v1/search/tags' ),
				'search_suggest_url'  => rest_url( 'node/v1/search/suggest' ),
				'result_search_url'   => rest_url( 'luminous-core-engine/v1/search/results' ),
				'platform_search_url' => rest_url( 'luminous-core-engine/v1/search/platforms' ),
			)
		);
	}

	/**
	 * Enqueue styles referenced by the Vite manifest.
	 *
	 * @param array{base:string,manifest:array} $build node_resolve_vite_build() の解決結果。
	 */
	private static function enqueueMainStyles( array $build ): void {
		$manifest = $build['manifest'];

		if ( isset( $manifest['src/main.js']['css'] ) && is_array( $manifest['src/main.js']['css'] ) ) {
			foreach ( $manifest['src/main.js']['css'] as $css_file ) {
				if ( is_string( $css_file ) ) {
					self::enqueueStyle( 'node-main-css', $css_file, $build['base'] );
				}
			}
		}

		$style_file = $manifest['src/styles/style.css']['file'] ?? null;
		if ( is_string( $style_file ) ) {
			self::enqueueStyle( 'node-style-css', $style_file, $build['base'] );
		}
	}

	/**
	 * Register a Vite import chain in dependency order.
	 *
	 * @param array<string, mixed> $manifest Vite manifest.
	 * @param string               $key      Manifest entry key.
	 * @param array<string, bool>  $seen     Registered entries.
	 * @param string               $base     node_resolve_vite_build() が選んだ成果物の base。
	 * @return string[] Script handles in load order.
	 */
	private static function registerViteChain( array $manifest, string $key, array &$seen, string $base ): array {
		if ( ! isset( $manifest[ $key ] ) || isset( $seen[ $key ] ) || ! is_array( $manifest[ $key ] ) ) {
			return array();
		}

		$handles = array();
		$imports = $manifest[ $key ]['imports'] ?? array();

		if ( is_array( $imports ) ) {
			foreach ( $imports as $import_key ) {
				if ( is_string( $import_key ) ) {
					$handles = array_merge( $handles, self::registerViteChain( $manifest, $import_key, $seen, $base ) );
				}
			}
		}

		$file = $manifest[ $key ]['file'] ?? null;
		if ( ! is_string( $file ) ) {
			return $handles;
		}

		$handle = 'node-vite-' . sanitize_title( str_replace( array( '/', '_', '.' ), '-', $key ) );

		/*
		 * バージョンクエリを付けない。
		 *
		 * main.js は vendor チャンクを `./vendor.<hash>.js` と相対 import する。
		 * ここで `?ver=` を足すと <script> タグ側だけ別 URL になり、ブラウザは
		 * 同じチャンクを2回ダウンロードして2回評価する（実測 96KB の二重取得）。
		 * Vite のファイル名は内容ハッシュ付きなので、キャッシュ破棄はファイル名が担う。
		 */
		wp_register_script(
			$handle,
			node_vite_public_url( $base, $file ),
			array(),
			null,
			true
		);
		wp_script_add_data( $handle, 'type', 'module' );

		$seen[ $key ] = true;
		$handles[]    = $handle;

		return $handles;
	}

	/**
	 * Enqueue a stylesheet with an mtime-based version.
	 */
	private static function enqueueStyle( string $handle, string $file, string $base ): void {
		$path    = node_vite_disk_path( $base, $file );
		$version = file_exists( $path ) ? (string) filemtime( $path ) : NODE_THEME_VERSION;

		wp_enqueue_style(
			$handle,
			node_vite_public_url( $base, $file ),
			array(),
			$version
		);
	}
}
