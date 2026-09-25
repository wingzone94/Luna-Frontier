<?php

declare(strict_types=1);
/**
 * Theme Setup for Luminous Core (Node)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Node テーマのバージョン（親テーマ style.css の Version ヘッダー）
 */
function node_get_theme_version(): string {
	$theme   = wp_get_theme( get_template() );
	$version = $theme->get( 'Version' );

	return ( is_string( $version ) && '' !== $version ) ? $version : '0.0.0';
}

/**
 * iPad / Android タブレット等の UA 判定（表示モード切替ボタン出力用）
 */
function node_is_tablet_ua(): bool {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	if ( '' === $ua ) {
		return false;
	}

	// iPad（クラシック UA）
	if ( false !== stripos( $ua, 'iPad' ) ) {
		return true;
	}

	// Android タブレット（Mobile なし）
	if ( false !== stripos( $ua, 'Android' ) && false === stripos( $ua, 'Mobile' ) ) {
		return true;
	}

	// 汎用 Tablet / Kindle 等
	if ( preg_match( '/Tablet|PlayBook|Silk/i', $ua ) ) {
		return true;
	}

	// iPadOS（Mobile 付き Macintosh Safari）
	if ( preg_match( '/Macintosh|Mac OS X/i', $ua )
		&& preg_match( '/AppleWebKit/i', $ua )
		&& false === stripos( $ua, 'iPhone' )
		&& false === stripos( $ua, 'iPod' )
		&& false !== stripos( $ua, 'Mobile' ) ) {
		return true;
	}

	// Client Hints（対応ブラウザ）
	$ch_platform = isset( $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ) ? (string) $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] : '';
	if ( '' !== $ch_platform && false !== stripos( $ch_platform, 'iPad' ) ) {
		return true;
	}

	return false;
}

/**
 * node.zip 展開後のコピー元ディレクトリを解決する
 *
 * @param string $temp_extract_dir 一時展開先。
 * @return string|null 末尾スラッシュ付きパス。見つからない場合は null。
 */
function node_resolve_theme_update_source_dir( string $temp_extract_dir ): ?string {
	$candidates = array(
		$temp_extract_dir . '/Node',
		$temp_extract_dir . '/node',
		$temp_extract_dir . '/node-theme-production',
	);

	foreach ( $candidates as $dir ) {
		if ( node_is_valid_theme_update_source( $dir ) ) {
			return trailingslashit( $dir );
		}
	}

	$entries = is_dir( $temp_extract_dir ) ? scandir( $temp_extract_dir ) : false;
	if ( is_array( $entries ) ) {
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$dir = $temp_extract_dir . '/' . $entry;
			if ( is_dir( $dir ) && node_is_valid_theme_update_source( $dir ) ) {
				return trailingslashit( $dir );
			}
		}
	}

	if ( node_is_valid_theme_update_source( $temp_extract_dir ) ) {
		return trailingslashit( $temp_extract_dir );
	}

	return null;
}

/**
 * テーマ更新のコピー元として style.css が Node テーマか検証
 *
 * @param string $dir 検査対象ディレクトリ。
 */
function node_is_valid_theme_update_source( string $dir ): bool {
	$style = $dir . '/style.css';
	$index = $dir . '/index.php';
	if ( ! is_file( $style ) || ! is_file( $index ) ) {
		return false;
	}

	$data = get_file_data(
		$style,
		array(
			'Theme Name' => 'Theme Name',
		)
	);

	return isset( $data['Theme Name'] ) && 'Node' === $data['Theme Name'];
}

/**
 * 展開済みZIPの版とビルドIDを確認する。
 *
 * @return array{version:string,build_id:string}|WP_Error
 */
function node_validate_theme_update_package( string $source_dir, string $local_version, ?string $local_build ): array|WP_Error {
	if ( ! node_is_valid_theme_update_source( $source_dir ) || ! is_file( $source_dir . '/build.json' ) ) {
		return new WP_Error( 'invalid_theme_package', 'Node テーマの必須ファイルがZIPにありません。' );
	}

	$headers = get_file_data( $source_dir . '/style.css', array( 'Version' => 'Version' ) );
	$version = (string) ( $headers['Version'] ?? '' );
	$build   = json_decode( (string) file_get_contents( $source_dir . '/build.json' ), true );
	if ( '' === $version || ! is_array( $build ) || empty( $build['build_id'] ) || $version !== ( $build['version'] ?? null ) ) {
		return new WP_Error( 'invalid_theme_build', 'ZIP内のバージョンとビルド情報が一致しません。' );
	}
	if ( version_compare( $version, $local_version, '<' ) ) {
		return new WP_Error( 'older_theme_package', '現在より古いテーマはインストールできません。' );
	}
	if ( $version === $local_version && (string) $build['build_id'] === $local_build ) {
		return new WP_Error( 'same_theme_build', 'このビルドは既にインストールされています。' );
	}

	return array( 'version' => $version, 'build_id' => (string) $build['build_id'] );
}

/**
 * 展開済みテーマを別ディレクトリに配置してから切り替える。
 *
 * 旧テーマは新テーマへの切り替え成功まで保持し、失敗時は元へ戻す。
 *
 * @param WP_Filesystem_Base $filesystem WordPress filesystem instance.
 * @return true|WP_Error
 */
function node_swap_theme_update_directory( $filesystem, string $source_dir, string $theme_dir ): bool|WP_Error {
	$next_dir   = $theme_dir . '_next_update';
	$backup_dir = $theme_dir . '_previous_update';

	if ( $filesystem->exists( $next_dir ) || $filesystem->exists( $backup_dir ) ) {
		return new WP_Error( 'theme_update_leftovers', '前回の更新用ディレクトリが残っています。管理者が確認してください。' );
	}

	$copy_result = copy_dir( $source_dir, $next_dir );
	if ( is_wp_error( $copy_result ) || ! $copy_result ) {
		$filesystem->delete( $next_dir, true );
		return is_wp_error( $copy_result ) ? $copy_result : new WP_Error( 'theme_stage_failed', '新しいテーマの配置に失敗しました。' );
	}
	if ( ! $filesystem->exists( $next_dir . '/functions.php' ) || ! $filesystem->exists( $next_dir . '/build.json' ) ) {
		$filesystem->delete( $next_dir, true );
		return new WP_Error( 'theme_stage_incomplete', '新しいテーマの配置が不完全です。' );
	}

	if ( ! $filesystem->move( $theme_dir, $backup_dir ) ) {
		$filesystem->delete( $next_dir, true );
		return new WP_Error( 'theme_backup_failed', '現在のテーマの退避に失敗しました。' );
	}
	if ( ! $filesystem->move( $next_dir, $theme_dir ) ) {
		if ( ! $filesystem->move( $backup_dir, $theme_dir ) ) {
			return new WP_Error( 'theme_rollback_failed', '更新と復元に失敗しました。退避先: ' . $backup_dir );
		}
		$filesystem->delete( $next_dir, true );
		return new WP_Error( 'theme_swap_failed', '新しいテーマへの切り替えに失敗しました。元のテーマを復元しました。' );
	}

	$filesystem->delete( $backup_dir, true );
	return true;
}
