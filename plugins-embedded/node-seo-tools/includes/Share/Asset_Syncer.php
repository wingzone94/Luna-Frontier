<?php
/**
 * Resolve and cache OGP base assets.
 *
 * Background/logo: synced from Luminous Core canonical URLs.
 * Font: plugin bundle first, then the theme's verified font asset and cache/CDN.
 *       Never uses the legacy theme ogp-font.ttf (known broken HTML on prod/test).
 *
 * @package Node_SEO_Tools
 */

namespace Node\SEO\Tools\Share;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Asset_Syncer {

	public const CANONICAL_BASE = 'https://luminous-core.net/wp-content/themes/node/assets/';

	public const FONT_JP_FILENAME            = 'NotoSansJP-VF.ttf';
	public const FONT_LATIN_FILENAME         = 'DIN2014-Regular.ttf';
	public const FONT_LATIN_FALLBACK_BUNDLED = 'Inter-Regular.ttf';

	/**
	 * Google Fonts CDN — stable direct TTF (environment-agnostic).
	 */
	public const FONT_JP_CDN_URL    = 'https://fonts.gstatic.com/ea/notosansjapanese/v6/NotoSansJP-Regular.otf';
	public const FONT_LATIN_CDN_URL = 'https://rsms.me/inter/font-files/Inter-Regular.woff2';

	/** @var array<string, string> */
	private const REMOTE_IMAGES = array(
		'ogp-bg.png'  => self::CANONICAL_BASE . 'images/ogp-bg.png',
		'ogp-logo.png' => self::CANONICAL_BASE . 'images/ogp-logo.png',
	);

	/**
	 * Ensure cached assets exist and are valid.
	 *
	 * @return array{background:string,logo:string,font_jp:string,font_latin:string}
	 */
	public static function ensure_assets(): array {
		$cache_dir = self::get_cache_dir();
		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		foreach ( self::REMOTE_IMAGES as $filename => $url ) {
			$local = $cache_dir . '/' . $filename;
			if ( ! self::is_valid_image( $local ) ) {
				self::download_file( $url, $local );
			}
		}

		$fonts = self::resolve_font_paths();

		return array(
			'background' => $cache_dir . '/ogp-bg.png',
			'logo'       => $cache_dir . '/ogp-logo.png',
			'font_jp'    => $fonts['font_jp'],
			'font_latin' => $fonts['font_latin'],
		);
	}

	/**
	 * Resolve OGP fonts usable by GD FreeType.
	 *
	 * Priority:
	 * 1. Plugin bundle (works offline, identical on test/prod)
	 * 2. Theme bundle (keeps the theme ZIP from duplicating the large JP font)
	 * 3. Uploads cache (previous CDN sync)
	 * 4. CDN download into cache
	 */
	public static function resolve_font_paths(): array {
		$bundled_dir = NODE_SEO_TOOLS_DIR . 'assets/share/fonts/';
		$cache_dir   = self::get_cache_dir();
		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		$font_jp_bundled = $bundled_dir . self::FONT_JP_FILENAME;
		if ( ! self::is_valid_font( $font_jp_bundled ) ) {
			$font_jp_bundled = trailingslashit( get_template_directory() ) . 'assets/ttf/' . self::FONT_JP_FILENAME;
		}

		$font_jp = self::resolve_single_font(
			$font_jp_bundled,
			$cache_dir . '/' . self::FONT_JP_FILENAME,
			self::FONT_JP_CDN_URL
		);

		$font_latin = self::resolve_single_font(
			$bundled_dir . self::FONT_LATIN_FILENAME,
			$cache_dir . '/' . self::FONT_LATIN_FILENAME,
			''
		);
		if ( '' === $font_latin ) {
			$font_latin = self::resolve_single_font(
				$bundled_dir . self::FONT_LATIN_FALLBACK_BUNDLED,
				$cache_dir . '/' . self::FONT_LATIN_FALLBACK_BUNDLED,
				self::FONT_LATIN_CDN_URL
			);
		}

		return array(
			'font_jp'    => $font_jp,
			'font_latin' => $font_latin,
		);
	}

	/**
	 * ブランドフォールバック用 Inter（プラグイン同梱を正本とする）。
	 */
	public static function resolve_inter_font(): string {
		$bundled = NODE_SEO_TOOLS_DIR . 'assets/share/fonts/' . self::FONT_LATIN_FALLBACK_BUNDLED;
		if ( self::is_valid_font( $bundled ) ) {
			return $bundled;
		}

		$cached = self::get_cache_dir() . '/' . self::FONT_LATIN_FALLBACK_BUNDLED;
		if ( self::is_valid_font( $cached ) ) {
			return $cached;
		}

		if ( self::download_file( self::FONT_LATIN_CDN_URL, $cached ) && self::is_valid_font( $cached ) ) {
			return $cached;
		}

		return '';
	}

	private static function resolve_single_font( string $bundled, string $cached, string $cdn_url ): string {
		if ( self::is_valid_font( $bundled ) ) {
			return $bundled;
		}

		if ( self::is_valid_font( $cached ) ) {
			return $cached;
		}

		if ( '' !== $cdn_url && self::download_file( $cdn_url, $cached ) && self::is_valid_font( $cached ) ) {
			return $cached;
		}

		error_log( 'Luna SEO Tools: no valid font available for ' . basename( $cached ) . '.' );
		return '';
	}

	public static function get_cache_dir(): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'node-seo-tools/assets';
	}

	private static function download_file( string $url, string $dest ): bool {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => 'Mozilla/5.0 (compatible; NodeSEO/1.0; +https://luminous-core.net/)',
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'Luna SEO Tools: download failed for ' . $url . ' — ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'Luna SEO Tools: download HTTP ' . $code . ' for ' . $url );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return false;
		}

		if ( self::looks_like_html( $body ) ) {
			error_log( 'Luna SEO Tools: rejected HTML response for ' . $url );
			return false;
		}

		$written = file_put_contents( $dest, $body );
		if ( false === $written ) {
			return false;
		}

		return true;
	}

	private static function looks_like_html( string $body ): bool {
		$trimmed = ltrim( $body );
		return str_starts_with( $trimmed, '<!' ) || str_starts_with( $trimmed, '<html' );
	}

	public static function is_valid_image( string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$info = @getimagesize( $path );
		return is_array( $info ) && in_array( $info[2], array( IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP ), true );
	}

	public static function is_valid_font( string $path ): bool {
		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			return false;
		}

		if ( self::looks_like_html( (string) file_get_contents( $path, false, null, 0, 64 ) ) ) {
			return false;
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		$header = fread( $handle, 4 );
		fclose( $handle );

		if ( false === $header || strlen( $header ) < 4 ) {
			return false;
		}

		return "\x00\x01\x00\x00" === $header
			|| 'OTTO' === $header
			|| 'true' === $header
			|| 'typ1' === $header;
	}
}
