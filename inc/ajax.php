<?php

declare(strict_types=1);
/**
 * Luminous Core AJAX Handlers (Theme side)
 *
 * Note: AI related handlers have been migrated to the Luminous AI Core plugin.
 */

/**
 * Check for Theme Updates
 */
add_action(
	'wp_ajax_luminous_check_update',
	function () {
		check_ajax_referer( 'luminous_update_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$local_version = node_get_theme_version();
		$remote_url    = 'https://raw.githubusercontent.com/wingzone94/Luna-Frontier/refs/heads/master/style.css';

		$response = wp_remote_get( $remote_url );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Failed to fetch remote version' );
		}

		$body = wp_remote_retrieve_body( $response );
		preg_match( '/Version:\s*([\d\.]+)/i', $body, $matches );
		$remote_version = isset( $matches[1] ) ? $matches[1] : '0.0.0';

		$update_available = version_compare( $remote_version, $local_version, '>' );
		$install_available = version_compare( $remote_version, $local_version, '>=' );

		// 同日リリースはバージョン据え置きで node.zip だけ更新されるため、
		// バージョンとは独立に build.json のビルド識別子も比較する。
		// ?cb= は raw URL の CDN キャッシュ回避。
		$local_build_info = function_exists( 'node_get_build_info' ) ? node_get_build_info() : null;
		$local_build      = $local_build_info['build_id'] ?? null;

		$remote_build   = null;
		$build_response = wp_remote_get( 'https://raw.githubusercontent.com/wingzone94/Luna-Frontier/refs/heads/master/build.json?cb=' . time() );
		if ( ! is_wp_error( $build_response ) && 200 === wp_remote_retrieve_response_code( $build_response ) ) {
			$build_data = json_decode( wp_remote_retrieve_body( $build_response ), true );
			if ( is_array( $build_data ) && ! empty( $build_data['build_id'] ) ) {
				$remote_build = (string) $build_data['build_id'];
			}
		}

		$same_version = ! $update_available && $install_available;

		wp_send_json_success(
			array(
				'local_version'    => $local_version,
				'remote_version'   => $remote_version,
				'update_available'  => $update_available,
				'install_available' => $install_available,
				'same_version'      => $same_version,
				'local_build'       => $local_build,
				'remote_build'      => $remote_build,
				'build_update_available' => $same_version && $remote_build && $remote_build !== $local_build,
			)
		);
	}
);

/**
 * Install Theme Update
 */
add_action(
	'wp_ajax_luminous_install_update',
	function () {
		check_ajax_referer( 'luminous_update_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$zip_url = 'https://github.com/wingzone94/Luna-Frontier/raw/refs/heads/master/node.zip';

		error_log( 'Luminous Update: Starting update from ' . $zip_url );

		$temp_file = download_url( $zip_url );
		if ( is_wp_error( $temp_file ) ) {
			error_log( 'Luminous Update: Download failed - ' . $temp_file->get_error_message() );
			wp_send_json_error( 'Download failed: ' . $temp_file->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			unlink( $temp_file );
			error_log( 'Luminous Update: Filesystem API failed' );
			wp_send_json_error( 'Filesystem API failed' );
		}

		$theme_dir        = get_template_directory();
		$temp_extract_dir = $theme_dir . '_temp_update';

		if ( $wp_filesystem->exists( $temp_extract_dir ) ) {
			$wp_filesystem->delete( $temp_extract_dir, true );
		}

		$unzipped = unzip_file( $temp_file, $temp_extract_dir );
		if ( is_wp_error( $unzipped ) ) {
			unlink( $temp_file );
			$wp_filesystem->delete( $temp_extract_dir, true );
			error_log( 'Luminous Update: Extraction failed - ' . $unzipped->get_error_message() );
			wp_send_json_error( 'Extraction failed: ' . $unzipped->get_error_message() );
		}
		unlink( $temp_file );

		$source_dir = node_resolve_theme_update_source_dir( $temp_extract_dir );
		if ( null === $source_dir ) {
			$wp_filesystem->delete( $temp_extract_dir, true );
			error_log( 'Luminous Update: Could not locate Node theme folder inside ZIP' );
			wp_send_json_error( 'ZIP 内に Node テーマフォルダが見つかりませんでした。' );
		}

		$local_build_info = function_exists( 'node_get_build_info' ) ? node_get_build_info() : null;
		$package_info     = node_validate_theme_update_package( $source_dir, node_get_theme_version(), $local_build_info['build_id'] ?? null );
		if ( is_wp_error( $package_info ) ) {
			$wp_filesystem->delete( $temp_extract_dir, true );
			wp_send_json_error( $package_info->get_error_message() );
		}

		error_log( 'Luminous Update: Switching to build ' . $package_info['build_id'] );
		$copy_result = node_swap_theme_update_directory( $wp_filesystem, $source_dir, $theme_dir );

		$wp_filesystem->delete( $temp_extract_dir, true );

		if ( is_wp_error( $copy_result ) ) {
			error_log( 'Luminous Update: Switch failed - ' . $copy_result->get_error_message() );
			wp_send_json_error( 'Update failed: ' . $copy_result->get_error_message() );
		}

		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache();
		}

		// インストール直後の build.json を読み、どのビルドが入ったかを検証可能にする
		$installed_build = null;
		$build_path      = $theme_dir . '/build.json';
		if ( $wp_filesystem->exists( $build_path ) ) {
			$build_data = json_decode( (string) $wp_filesystem->get_contents( $build_path ), true );
			if ( is_array( $build_data ) && ! empty( $build_data['build_id'] ) ) {
				$installed_build = (string) $build_data['build_id'];
			}
		}

		error_log( 'Luminous Update: Update installed successfully (build: ' . ( $installed_build ?? 'unknown' ) . ')' );

		// Node Connect（有効時のみ購読）へアップデート完了イベントを通知する
		do_action(
			'node_connect_event',
			'node_updated',
			array(
				'title'   => 'Node テーマを更新しました',
				'message' => 'インストールされたビルド: ' . ( $installed_build ?? '不明' ),
			)
		);

		wp_send_json_success(
			array(
				'message'         => 'Update installed successfully',
				'installed_build' => $installed_build,
			)
		);
	}
);
