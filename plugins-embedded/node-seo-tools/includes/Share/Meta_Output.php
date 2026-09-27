<?php
/**
 * OGP / Twitter Card meta output.
 *
 * @package Node_SEO_Tools
 */

namespace Node\SEO\Tools\Share;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Meta_Output {
	private const FEATURED_IMAGE_META = '_node_ogp_use_featured_image';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_featured_image_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'wp_head', array( $this, 'inject_ogp_tags' ), 5 );
	}

	public function register_featured_image_meta(): void {
		register_post_meta(
			'post',
			self::FEATURED_IMAGE_META,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ): bool {
					return current_user_can( 'edit_post', (int) $post_id );
				},
			)
		);
	}

	public function enqueue_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		$path = NODE_SEO_TOOLS_DIR . 'assets/js/ogp-featured-toggle.js';
		if ( ! is_readable( $path ) ) {
			return;
		}

		wp_enqueue_script(
			'node-seo-ogp-featured-toggle',
			NODE_SEO_TOOLS_URL . 'assets/js/ogp-featured-toggle.js',
			array( 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-plugins' ),
			NODE_SEO_TOOLS_VERSION . '.' . (string) filemtime( $path ),
			true
		);

		wp_localize_script(
			'node-seo-ogp-featured-toggle',
			'nodeSeoOgpData',
			array(
				'canConvert' => current_user_can( 'manage_options' ) && function_exists( 'node_ic_ajax_convert_one' ),
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'node_ic_action' ),
			)
		);
	}

	public function inject_ogp_tags(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id = get_the_ID();
		$image   = $this->resolve_image( $post_id );
		$ogp_url = $image['url'];
		if ( '' === $ogp_url ) {
			return;
		}

		$title          = get_the_title( $post_id );
		$image_alt      = has_post_thumbnail( $post_id ) ? $title : 'Luminous Core';
		$desc           = $this->build_share_description( $post_id );
		$url            = get_permalink( $post_id );
		$twitter_site   = (string) apply_filters( 'node_seo_twitter_site', '@Luminous_Core_' );
		$twitter_domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$card_type      = 'summary_large_image';

		echo '<meta property="og:type" content="article" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( $desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" />' . "\n";
		echo '<meta property="og:locale" content="ja_JP" />' . "\n";
		echo '<meta property="article:published_time" content="' . esc_attr( get_the_date( 'c', $post_id ) ) . '" />' . "\n";
		echo '<meta property="article:modified_time" content="' . esc_attr( get_the_modified_date( 'c', $post_id ) ) . '" />' . "\n";
		echo '<meta property="og:image" content="' . esc_url( $ogp_url ) . '" />' . "\n";
		if ( str_starts_with( $ogp_url, 'https://' ) ) {
			echo '<meta property="og:image:secure_url" content="' . esc_url( $ogp_url ) . '" />' . "\n";
		}
		echo '<meta property="og:image:width" content="' . esc_attr( (string) $image['width'] ) . '" />' . "\n";
		echo '<meta property="og:image:height" content="' . esc_attr( (string) $image['height'] ) . '" />' . "\n";
		echo '<meta property="og:image:type" content="' . esc_attr( $image['type'] ) . '" />' . "\n";
		echo '<meta property="og:image:alt" content="' . esc_attr( $image_alt ) . '" />' . "\n";
		echo '<meta name="twitter:card" content="' . esc_attr( $card_type ) . '" />' . "\n";
		echo '<meta property="twitter:card" content="' . esc_attr( $card_type ) . '" />' . "\n";
		if ( '' !== $twitter_site ) {
			echo '<meta name="twitter:site" content="' . esc_attr( $twitter_site ) . '" />' . "\n";
		}
		if ( is_string( $twitter_domain ) && '' !== $twitter_domain ) {
			echo '<meta name="twitter:domain" content="' . esc_attr( $twitter_domain ) . '" />' . "\n";
		}
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( $desc ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
		echo '<meta name="twitter:image" content="' . esc_url( $ogp_url ) . '" />' . "\n";
		echo '<meta name="twitter:image:src" content="' . esc_url( $ogp_url ) . '" />' . "\n";
		echo '<meta name="twitter:image:alt" content="' . esc_attr( $image_alt ) . '" />' . "\n";
	}

	/**
	 * @return array{url: string, width: int, height: int, type: string}
	 */
	private function resolve_image( int $post_id ): array {
		if ( rest_sanitize_boolean( get_post_meta( $post_id, self::FEATURED_IMAGE_META, true ) ) ) {
			$attachment_id = (int) get_post_thumbnail_id( $post_id );
			if ( $attachment_id > 0 ) {
				$image = wp_get_attachment_image_src( $attachment_id, 'full' );
				$type  = get_post_mime_type( $attachment_id );
				if ( is_array( $image ) && ! empty( $image[0] ) && $image[1] > 0 && $image[2] > 0 && is_string( $type ) && in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
					return array(
						'url'    => set_url_scheme( $image[0], 'https' ),
						'width'  => (int) $image[1],
						'height' => (int) $image[2],
						'type'   => $type,
					);
				}
			}
		}

		return array(
			'url'    => $this->resolve_ogp_image_url( $post_id ),
			'width'  => 1200,
			'height' => 630,
			'type'   => 'image/png',
		);
	}

	/**
	 * SNS向け説明文（HTML実体・WordPress省略記号を正規化、120文字上限）
	 */
	private function build_share_description( int $post_id ): string {
		$desc = wp_strip_all_tags( get_the_excerpt( $post_id ) );
		$desc = html_entity_decode( $desc, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$desc = preg_replace( '/\[\s*(?:&hellip;|…|\.\.\.)\s*\]/u', '…', $desc ) ?? $desc;
		$desc = preg_replace( '/\.{3,}/u', '…', $desc ) ?? $desc;
		$desc = trim( preg_replace( '/\s+/u', ' ', $desc ) ?? $desc );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $desc ) > 120 ) {
			$desc = mb_substr( $desc, 0, 119 ) . '…';
		}

		return $desc;
	}

	/**
	 * Resolve a cache-busted HTTPS OGP image URL for SNS crawlers.
	 */
	private function resolve_ogp_image_url( int $post_id ): string {
		$url = get_post_meta( $post_id, '_node_ogp_image_url', true );
		if ( ! is_string( $url ) || '' === $url ) {
			$upload_dir = wp_upload_dir();
			$filepath   = trailingslashit( $upload_dir['basedir'] ) . 'ogp/ogp-' . $post_id . '.png';
			if ( ! is_file( $filepath ) ) {
				return '';
			}
			$url = trailingslashit( $upload_dir['baseurl'] ) . 'ogp/ogp-' . $post_id . '.png';
		}

		$url = set_url_scheme( $url, 'https' );

		$mtime = (int) get_post_meta( $post_id, '_node_ogp_image_mtime', true );
		if ( $mtime <= 0 ) {
			$path = $this->url_to_upload_path( $url );
			if ( '' !== $path && is_file( $path ) ) {
				$mtime = (int) filemtime( $path );
			}
		}

		if ( $mtime > 0 ) {
			$url = add_query_arg( 'v', (string) $mtime, $url );
		}

		return $url;
	}

	private function url_to_upload_path( string $url ): string {
		$upload_dir = wp_upload_dir();
		$baseurl    = trailingslashit( $upload_dir['baseurl'] );
		$basedir    = trailingslashit( $upload_dir['basedir'] );

		if ( ! str_starts_with( $url, $baseurl ) ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( ! is_string( $path ) || '' === $path ) {
				return '';
			}
			$relative = ltrim( $path, '/' );
			$uploads  = ltrim( wp_parse_url( $baseurl, PHP_URL_PATH ) ?? '', '/' );
			if ( '' !== $uploads && str_starts_with( $relative, $uploads ) ) {
				$relative = ltrim( substr( $relative, strlen( $uploads ) ), '/' );
			}
			return $basedir . $relative;
		}

		return $basedir . ltrim( substr( $url, strlen( $baseurl ) ), '/' );
	}
}
