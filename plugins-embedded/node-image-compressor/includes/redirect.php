<?php
/**
 * 置き換えで消えた旧 URL を、新しい WebP へ 301 転送する。
 *
 * 元ファイルを削除すると、外部サイトや RSS リーダーに残った
 * `.../PSHERO.jpg` のような直リンクが 404 になる。ファイルが存在しないリクエストは
 * サーバー設定次第で index.php に流れてくるので、そこを捕まえて転送する。
 * 中間サイズ（`PSHERO-768x432.jpg`）も同じ寸法の WebP へ送る。
 *
 * @package Node_Image_Compressor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_ic_maybe_redirect_replaced_image' ) ) {
	/**
	 * 旧画像 URL へのリクエストを WebP へ転送する。
	 */
	function node_ic_maybe_redirect_replaced_image(): void {
		if ( is_admin() || wp_doing_ajax() || ! Node_IC_Converter::redirects_old_urls() ) {
			return;
		}

		$relative = node_ic_requested_upload_path();
		if ( '' === $relative ) {
			return;
		}

		$target = node_ic_resolve_replacement_url( $relative );
		if ( '' === $target ) {
			return;
		}

		// uploads を CDN や別ドメインへ逃がしている場合、wp_safe_redirect は
		// 外部ホストを弾いてトップページへ飛ばしてしまう。転送先のホストだけ許可する
		$target_host = (string) wp_parse_url( $target, PHP_URL_HOST );
		if ( '' !== $target_host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( array $hosts ) use ( $target_host ): array {
					$hosts[] = $target_host;
					return $hosts;
				}
			);
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}
}

if ( ! function_exists( 'node_ic_requested_upload_path' ) ) {
	/**
	 * リクエストが「uploads 配下の、実在しない画像ファイル」なら
	 * uploads からの相対パスを返す。それ以外は空文字。
	 */
	function node_ic_requested_upload_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $uri ) {
			return '';
		}

		// クエリを落とし、%E3%81%82 のような日本語ファイル名も戻す
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = rawurldecode( $path );
		if ( '' === $path ) {
			return '';
		}

		// 置き換え対象になりうる拡張子だけ相手にする（通常の 404 に負荷をかけない）
		if ( ! preg_match( '/\.(jpe?g|png)$/i', $path ) ) {
			return '';
		}

		$uploads  = wp_get_upload_dir();
		$base_path = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( '' === $base_path ) {
			return '';
		}

		$base_path = trailingslashit( $base_path );
		if ( 0 !== strpos( $path, $base_path ) ) {
			return '';
		}

		$relative = ltrim( substr( $path, strlen( $base_path ) ), '/' );

		// ディレクトリを遡る指定は受け付けない
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return '';
		}

		// ファイルが実在するなら転送する理由がない（元を残す設定のとき）
		if ( file_exists( trailingslashit( $uploads['basedir'] ) . $relative ) ) {
			return '';
		}

		return $relative;
	}
}

if ( ! function_exists( 'node_ic_resolve_replacement_url' ) ) {
	/**
	 * 旧相対パスから、置き換え後の WebP の URL を求める。
	 *
	 * @param string $relative 例: `2026/07/PSHERO-768x432.jpg`
	 * @return string 見つからなければ空文字。
	 */
	function node_ic_resolve_replacement_url( string $relative ): string {
		// `-768x432` のような中間サイズの接尾辞を外して、フルサイズのパスに戻す
		$width  = 0;
		$height = 0;
		$full   = preg_replace_callback(
			'/-(\d+)x(\d+)(\.[a-zA-Z0-9]+)$/',
			static function ( array $m ) use ( &$width, &$height ): string {
				$width  = (int) $m[1];
				$height = (int) $m[2];
				return $m[3];
			},
			$relative
		);

		$attachment_id = node_ic_find_attachment_by_original( (string) $full );

		// 接尾辞を外して見つからないなら、元のパスそのもので引き直す
		// （`logo-2x.png` のように寸法に見えるだけのファイル名がある）
		if ( 0 === $attachment_id && $full !== $relative ) {
			$attachment_id = node_ic_find_attachment_by_original( $relative );
			$width         = 0;
			$height        = 0;
		}

		if ( 0 === $attachment_id ) {
			return '';
		}

		// 同じ寸法の WebP があればそれへ、無ければフルサイズへ送る
		if ( $width > 0 && $height > 0 ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_name => $size ) {
					if ( (int) ( $size['width'] ?? 0 ) === $width && (int) ( $size['height'] ?? 0 ) === $height ) {
						$url = wp_get_attachment_image_url( $attachment_id, $size_name );
						if ( $url ) {
							return $url;
						}
					}
				}
			}
		}

		return (string) wp_get_attachment_url( $attachment_id );
	}
}

if ( ! function_exists( 'node_ic_find_attachment_by_original' ) ) {
	/**
	 * 置き換え前の相対パスからアタッチメントを引く。
	 *
	 * 404 のときだけ走るので都度クエリで十分だが、存在しない画像を大量に叩かれても
	 * DB を殴られないよう結果はキャッシュする（見つからなかったことも覚える）。
	 */
	function node_ic_find_attachment_by_original( string $relative ): int {
		$key    = 'node_ic_from_' . md5( $relative );
		$cached = wp_cache_get( $key, 'node-image-compressor' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$attachment_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = %s
				 LIMIT 1",
				Node_IC_Converter::META_REDIRECT_FROM,
				$relative
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		wp_cache_set( $key, $attachment_id, 'node-image-compressor', HOUR_IN_SECONDS );

		return $attachment_id;
	}
}
