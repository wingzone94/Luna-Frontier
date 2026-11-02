<?php

declare(strict_types=1);
/**
 * Luminous Core AJAX Handlers (Theme side)
 *
 * Note: AI related handlers have been migrated to the Luminous AI Core plugin.
 */

/**
 * Check for Theme Updates
 *
 * 取得先は GitHub Releases（不変タグ luna-frontier-v{version}-{build_id}）。
 * Release の兄弟 asset luna-release.json の sha256 / version / build_id を使うため、
 * ブランチ HEAD や生の style.css / build.json は参照しない。
 */
add_action(
	'wp_ajax_luminous_check_update',
	function () {
		check_ajax_referer( 'luminous_update_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$local_version    = node_get_theme_version();
		$local_build_info = function_exists( 'node_get_build_info' ) ? node_get_build_info() : null;
		$local_build      = $local_build_info['build_id'] ?? null;

		$best = node_select_luna_release( $local_version, is_string( $local_build ) ? $local_build : null );
		if ( is_wp_error( $best ) ) {
			if ( 'luna_update_none' === $best->get_error_code() ) {
				// 現行レスポンス形を維持し、更新なしとして扱う。
				wp_send_json_success(
					array(
						'local_version'    => $local_version,
						'remote_version'   => null,
						'update_available' => false,
						'install_available' => false,
						'same_version'     => false,
						'local_build'      => $local_build,
						'remote_build'     => null,
						'build_update_available' => false,
						'remote_build_behind'    => false,
					)
				);
			}

			error_log( 'Luminous Update: check failed - ' . $best->get_error_code() . ' ' . $best->get_error_message() );
			wp_send_json_error( $best->get_error_message() );
		}

		$meta = $best['meta'];

		$update_available = version_compare( (string) $meta['version'], $local_version, '>' );

		// 同じPreview版でも luna.zip のビルドが更新されるため、
		// バージョンとは独立に build_id も比較する。
		$same_version           = ! $update_available;
		$build_update_available = $same_version && node_is_newer_theme_build( (string) $meta['build_id'], $local_build );
		$install_available      = $update_available || $build_update_available;

		wp_send_json_success(
			array(
				'local_version'    => $local_version,
				'remote_version'   => (string) $meta['version'],
				'update_available' => $update_available,
				'install_available' => $install_available,
				'same_version'     => $same_version,
				'local_build'      => $local_build,
				'remote_build'     => (string) $meta['build_id'],
				'build_update_available' => $build_update_available,
				'remote_build_behind'    => $same_version && '' !== (string) $meta['build_id'] && $local_build !== (string) $meta['build_id'] && ! $build_update_available,
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

		$local_version    = node_get_theme_version();
		$local_build_info = function_exists( 'node_get_build_info' ) ? node_get_build_info() : null;
		$local_build      = $local_build_info['build_id'] ?? null;

		$best = node_select_luna_release( $local_version, is_string( $local_build ) ? $local_build : null );
		if ( is_wp_error( $best ) ) {
			error_log( 'Luminous Update: release selection failed - ' . $best->get_error_code() . ' ' . $best->get_error_message() );
			wp_send_json_error( $best->get_error_message() );
		}

		$meta = $best['meta'];

		$zip_asset = node_release_asset( $best['release'], 'luna.zip' );
		if ( null === $zip_asset ) {
			error_log( 'Luminous Update: luna.zip asset missing on ' . ( $best['release']['tag_name'] ?? '' ) );
			wp_send_json_error( 'リリースに luna.zip が含まれていません。' );
		}

		$temp_file = node_download_release_asset( $zip_asset );
		if ( is_wp_error( $temp_file ) ) {
			error_log( 'Luminous Update: Download failed - ' . $temp_file->get_error_message() );
			wp_send_json_error( 'Download failed: ' . $temp_file->get_error_message() );
		}

		// ダイジェスト不一致のZIPは展開しない。
		// 期待値と計算値を error_log に出してよい（URL全文も可）。トークンは出さない。
		$sha_result = node_verify_zip_sha256( $temp_file, (string) $meta['sha256'] );
		if ( is_wp_error( $sha_result ) ) {
			unlink( $temp_file );
			error_log(
				'Luminous Update: sha256 mismatch for ' . (string) ( $zip_asset['browser_download_url'] ?? '' )
				. ' expected=' . (string) $meta['sha256']
			);
			wp_send_json_error( 'ZIPの整合性検証に失敗しました（sha256照合不一致）。更新を中止しました。' );
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
			error_log( 'Luminous Update: Could not locate Luna Frontier theme folder inside ZIP' );
			wp_send_json_error( 'ZIP 内に Luna Frontier テーマフォルダが見つかりませんでした。' );
		}

		$package_info = node_validate_theme_update_package( $source_dir, $local_version, $local_build );
		if ( is_wp_error( $package_info ) ) {
			$wp_filesystem->delete( $temp_extract_dir, true );
			wp_send_json_error( $package_info->get_error_message() );
		}

		// ZIP 内の版・ビルドが、照合に使った luna-release.json と一致することを確認する。
		if ( (string) $package_info['version'] !== (string) $meta['version']
			|| (string) $package_info['build_id'] !== (string) $meta['build_id']
		) {
			$wp_filesystem->delete( $temp_extract_dir, true );
			error_log(
				'Luminous Update: meta mismatch for ' . (string) ( $zip_asset['browser_download_url'] ?? '' )
				. ' package=' . $package_info['version'] . '/' . $package_info['build_id']
				. ' meta=' . $meta['version'] . '/' . $meta['build_id']
			);
			wp_send_json_error( 'ZIPの内容と配信メタ情報（luna-release.json）が一致しません。更新を中止しました。' );
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

		// Luna Connect（有効時のみ購読）へアップデート完了イベントを通知する
		do_action(
			'node_connect_event',
			'node_updated',
			array(
				'title'   => 'Luna Frontier を更新しました',
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
