<?php
/**
 * Resolve and cache OGP base assets.
 *
 * Background/logo: synced from Luminous Core canonical URLs.
 * Fonts: download pinned TTF files from a CDN into the uploads cache.
 *
 * @package Node_SEO_Tools
 */

namespace Node\SEO\Tools\Share;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Asset_Syncer {

	public const CANONICAL_BASE = 'https://luminous-core.net/wp-content/themes/node/assets/';

	public const FONT_JP_FILENAME    = 'NotoSansJP-VF-165c01b.ttf';
	public const FONT_LATIN_FILENAME = 'Inter-VF-e1d6480.ttf';

	/**
	 * Pinned upstream TTF files served by jsDelivr. GD/FreeType needs a local
	 * font path, so the files are downloaded only when the uploads cache is empty.
	 */
	public const FONT_JP_CDN_URL    = 'https://cdn.jsdelivr.net/gh/notofonts/noto-cjk@165c01b46ea533872e002e0785ff17e44f6d97d8/Sans/Variable/TTF/Subset/NotoSansJP-VF.ttf';
	public const FONT_LATIN_CDN_URL = 'https://cdn.jsdelivr.net/gh/google/fonts@e1d6480102fed30739fead0faee463101f892c8f/ofl/inter/Inter%5Bopsz%2Cwght%5D.ttf';

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
	 * Use a versioned uploads cache, downloading from the CDN on a cache miss.
	 */
	public static function resolve_font_paths(): array {
		$cache_dir   = self::get_cache_dir();
		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		$font_jp = self::resolve_single_font(
			$cache_dir . '/' . self::FONT_JP_FILENAME,
			self::FONT_JP_CDN_URL
		);

		$font_latin = self::resolve_single_font(
			$cache_dir . '/' . self::FONT_LATIN_FILENAME,
			self::FONT_LATIN_CDN_URL
		);

		return array(
			'font_jp'    => $font_jp,
			'font_latin' => $font_latin,
		);
	}

	/**
	 * Brand fallback uses the same cached Inter font as title drawing.
	 */
	public static function resolve_inter_font(): string {
		return self::resolve_single_font(
			self::get_cache_dir() . '/' . self::FONT_LATIN_FILENAME,
			self::FONT_LATIN_CDN_URL
		);
	}

	private static function resolve_single_font( string $cached, string $cdn_url ): string {
		if ( self::is_valid_font( $cached ) ) {
			return $cached;
		}

		if ( ! is_dir( dirname( $cached ) ) ) {
			wp_mkdir_p( dirname( $cached ) );
		}

		if ( self::download_file( $cdn_url, $cached ) && self::is_valid_font( $cached ) ) {
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
