<?php
/**
 * GitHub release lookup shared by Luna Frontier and standalone Luna Library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Both distributions ship this helper and can be loaded in the same request.
if ( ! class_exists( 'Luna_GitHub_Release', false ) ) {
	final class Luna_GitHub_Release {
		public const REPOSITORY = 'https://github.com/wingzone94/Luna-Frontier';

		/** Cache failed lookups as empty arrays to avoid retrying in wp-admin. */
		private static function get_json( string $url, string $cache_key ): array {
			$cached = get_site_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout' => 5,
					'headers' => array( 'User-Agent' => 'Luna-Release-Updater' ),
				)
			);
			$data = array();
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_array( $decoded ) ) {
					$data = $decoded;
				}
			}

			set_site_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );
			return $data;
		}

		public static function latest(): array {
			return self::get_json(
				'https://api.github.com/repos/wingzone94/Luna-Frontier/releases/latest',
				'luna_github_latest_release'
			);
		}

		public static function theme_version( $tag ): ?string {
			$pattern = '/\Aluna-frontier-v(\d+\.\d+\.\d+(?:-[a-zA-Z0-9]+(?:[.-][a-zA-Z0-9]+)*)?)-\d{8}T\d{6}Z-[a-f0-9]+\z/';
			return is_string( $tag ) && preg_match( $pattern, $tag, $matches ) ? $matches[1] : null;
		}

		public static function asset_url( array $release, string $name ): ?string {
			$tag = $release['tag_name'] ?? null;
			if ( null === self::theme_version( $tag ) || ! is_array( $release['assets'] ?? null ) ) {
				return null;
			}

			$expected = self::REPOSITORY . '/releases/download/' . $tag . '/' . $name;
			foreach ( $release['assets'] as $asset ) {
				if ( is_array( $asset ) && $name === ( $asset['name'] ?? null ) && $expected === ( $asset['browser_download_url'] ?? null ) ) {
					return $expected;
				}
			}

			return null;
		}

		public static function metadata( array $release ): array {
			$url = self::asset_url( $release, 'luna-release.json' );
			if ( null === $url ) {
				return array();
			}

			$data = self::get_json( $url, 'luna_github_release_meta_' . md5( $url ) );
			return $release['tag_name'] === ( $data['tag'] ?? null ) ? $data : array();
		}
	}
}
