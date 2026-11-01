<?php

declare(strict_types=1);
/**
 * Theme Setup for Luminous Core (Node)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * このテーマ（Luna Frontier）の style.css Version。
 * 関数名は Node 1.x 由来の既存呼び出しとの互換のため維持する。別テーマの Version は読まない。
 */
function node_get_theme_version(): string {
	$theme   = wp_get_theme();
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
 * luna.zip 展開後のコピー元ディレクトリを解決する
 *
 * @param string $temp_extract_dir 一時展開先。
 * @return string|null 末尾スラッシュ付きパス。見つからない場合は null。
 */
function node_resolve_theme_update_source_dir( string $temp_extract_dir ): ?string {
	$candidates = array(
		$temp_extract_dir . '/luna-frontier',
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
 * テーマ更新のコピー元として style.css が Luna Frontier テーマか検証
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

	return isset( $data['Theme Name'] ) && 'Luna Frontier' === $data['Theme Name'];
}

/**
 * Compare timestamped release builds; retain compatibility with legacy IDs.
 */
function node_is_newer_theme_build( ?string $remote_build, ?string $local_build ): bool {
	if ( ! $remote_build || $remote_build === $local_build ) {
		return false;
	}
	$pattern = '/^(\d{8}T\d{6}Z)-/';
	if ( $local_build && preg_match( $pattern, $remote_build, $remote ) && preg_match( $pattern, $local_build, $local ) ) {
		return strcmp( $remote[1], $local[1] ) > 0;
	}
	return true;
}

/**
 * 展開済みZIPの版とビルドIDを確認する。
 *
 * @return array{version:string,build_id:string}|WP_Error
 */
function node_validate_theme_update_package( string $source_dir, string $local_version, ?string $local_build ): array|WP_Error {
	if ( ! node_is_valid_theme_update_source( $source_dir ) || ! is_file( $source_dir . '/build.json' ) ) {
		return new WP_Error( 'invalid_theme_package', 'Luna Frontier テーマの必須ファイルがZIPにありません。' );
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

	if ( $version === $local_version && ! node_is_newer_theme_build( (string) $build['build_id'], $local_build ) ) {
		return new WP_Error( 'older_theme_build', '現在より古いビルドはインストールできません。' );
	}

	return array( 'version' => $version, 'build_id' => (string) $build['build_id'] );
}

/**
 * Luna Frontier 自己更新の取得先（GitHub Releases）。
 *
 * ブランチ HEAD（raw / refs/heads）はリポジトリ以上の権限があれば差し替え可能で、
 * タグリリースのダイジェスト照合が効かないため取得先に使わない。
 * タグは不変（luna-frontier-v{version}-{build_id}）で、Release の兄弟 asset
 * luna-release.json だけが luna.zip の sha256 を保持する（ZIP 内 build.json には書かない）。
 */
if ( ! defined( 'LUNA_UPDATE_API' ) ) {
	define( 'LUNA_UPDATE_API', 'https://api.github.com/repos/wingzone94/Luna-Frontier' );
}
if ( ! defined( 'LUNA_UPDATE_TAG_PREFIX' ) ) {
	define( 'LUNA_UPDATE_TAG_PREFIX', 'luna-frontier-v' );
}
if ( ! defined( 'LUNA_UPDATE_USER_AGENT' ) ) {
	define( 'LUNA_UPDATE_USER_AGENT', 'Luna-Frontier-Updater' );
}

/**
 * GitHub API から JSON を取得する。
 *
 * @return array|WP_Error
 */
function node_github_get_json( string $url ): array|WP_Error {
	$response = wp_remote_get(
		$url,
		array(
			'headers' => array(
				'Accept'               => 'application/vnd.github+json',
				'User-Agent'           => LUNA_UPDATE_USER_AGENT,
				'X-GitHub-Api-Version' => '2022-11-28',
			),
			'timeout' => 15,
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'luna_update_http', 'GitHub APIへのアクセスに失敗しました。' );
	}

	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'luna_update_json', 'GitHub APIの応答を解釈できませんでした。' );
	}

	return $data;
}

/**
 * Release の assets から名前で asset を探す。
 */
function node_release_asset( array $release, string $name ): ?array {
	$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();

	foreach ( $assets as $asset ) {
		if ( is_array( $asset ) && (string) ( $asset['name'] ?? '' ) === $name ) {
			return $asset;
		}
	}

	return null;
}

/**
 * browser_download_url がこのリポジトリの luna-frontier-v* タグの正しい asset か検証。
 *
 * raw / refs/heads や他ホスト、クエリ付き URL、別 asset 名は拒否する。
 */
function node_is_luna_release_download_url( string $url, string $name ): bool {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return false;
	}

	if ( ( $parts['scheme'] ?? '' ) !== 'https' || ( $parts['host'] ?? '' ) !== 'github.com' ) {
		return false;
	}
	if ( isset( $parts['query'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return false;
	}

	return (bool) preg_match(
		'#^/wingzone94/Luna-Frontier/releases/download/' . preg_quote( LUNA_UPDATE_TAG_PREFIX, '#' ) . '[^/]+/' . preg_quote( $name, '#' ) . '$#',
		(string) ( $parts['path'] ?? '' )
	);
}

/**
 * Release asset を一時ファイルへダウンロードする。
 *
 * @return string|WP_Error 一時ファイルパス。
 */
function node_download_release_asset( array $asset ): string|WP_Error {
	$url = (string) ( $asset['browser_download_url'] ?? '' );

	if ( ! node_is_luna_release_download_url( $url, (string) ( $asset['name'] ?? '' ) ) ) {
		return new WP_Error( 'luna_update_asset_url', '更新アセットのURLが不正です。' );
	}

	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	return download_url( $url );
}

/**
 * luna-release.json の内容が Release 自身と整合するか検証（純粋関数）。
 *
 * @param array $meta    ダウンロードした luna-release.json の内容。
 * @param array $release GitHub API の Release オブジェクト。
 * @return true|WP_Error
 */
function node_validate_luna_release_meta( array $meta, array $release ): bool|WP_Error {
	$tag = (string) ( $release['tag_name'] ?? '' );

	if ( (string) ( $meta['tag'] ?? '' ) !== $tag ) {
		return new WP_Error( 'luna_update_meta_tag', '配信メタのタグがReleaseと一致しません。' );
	}
	if ( (string) ( $meta['asset'] ?? '' ) !== 'luna.zip' ) {
		return new WP_Error( 'luna_update_meta_asset', '配信メタのasset名が不正です。' );
	}
	if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $meta['sha256'] ?? '' ) ) ) {
		return new WP_Error( 'luna_update_meta_sha', '配信メタのsha256が不正です。' );
	}
	if ( '' === (string) ( $meta['version'] ?? '' ) || '' === (string) ( $meta['build_id'] ?? '' ) ) {
		return new WP_Error( 'luna_update_meta_build', '配信メタのバージョン・ビルドが不正です。' );
	}
	if ( $tag !== LUNA_UPDATE_TAG_PREFIX . (string) $meta['version'] . '-' . (string) $meta['build_id'] ) {
		return new WP_Error( 'luna_update_meta_tag_shape', '配信メタのタグ形式が不正です。' );
	}

	return true;
}

/**
 * Release の luna-release.json asset を取得して検証する。
 *
 * @return array|WP_Error 検証済みメタ。
 */
function node_read_luna_release_meta( array $release ): array|WP_Error {
	$asset = node_release_asset( $release, 'luna-release.json' );
	if ( null === $asset ) {
		return new WP_Error( 'luna_update_meta_missing', 'リリースに luna-release.json がありません。' );
	}

	$file = node_download_release_asset( $asset );
	if ( is_wp_error( $file ) ) {
		return $file;
	}

	$meta = json_decode( (string) file_get_contents( $file ), true );
	unlink( $file );

	if ( ! is_array( $meta ) ) {
		return new WP_Error( 'luna_update_json', 'luna-release.json の内容を解釈できませんでした。' );
	}

	$checked = node_validate_luna_release_meta( $meta, $release );

	return is_wp_error( $checked ) ? $checked : $meta;
}

/**
 * 2つのリリースメタを比較し、第1引数の方が新しいか判定する。
 */
function node_release_is_newer( array $meta, array $best_meta ): bool {
	$version_pair = version_compare( (string) $meta['version'], (string) $best_meta['version'] );
	if ( 1 === $version_pair ) {
		return true;
	}
	if ( -1 === $version_pair ) {
		return false;
	}

	return node_is_newer_theme_build( (string) $meta['build_id'], (string) $best_meta['build_id'] );
}

/**
 * インストール済みテーマより新しい luna-frontier-v* リリースを選択する。
 *
 * @param string      $local_version 現在の style.css Version。
 * @param string|null $local_build   現在の build_id。
 * @return array{release:array,meta:array}|WP_Error
 */
function node_select_luna_release( string $local_version, ?string $local_build ): array|WP_Error {
	$releases = node_github_get_json( LUNA_UPDATE_API . '/releases?per_page=20' );
	if ( is_wp_error( $releases ) ) {
		return $releases;
	}

	$best = null;
	foreach ( $releases as $release ) {
		if ( ! is_array( $release ) || ! empty( $release['draft'] ) ) {
			continue;
		}

		$tag = (string) ( $release['tag_name'] ?? '' );
		if ( '' === $tag || ! str_starts_with( $tag, LUNA_UPDATE_TAG_PREFIX ) ) {
			continue;
		}

		$meta = node_read_luna_release_meta( $release );
		if ( is_wp_error( $meta ) ) {
			continue;
		}

		if ( version_compare( (string) $meta['version'], $local_version, '<' ) ) {
			continue;
		}
		if ( (string) $meta['version'] === $local_version && ! node_is_newer_theme_build( (string) $meta['build_id'], $local_build ) ) {
			continue;
		}

		if ( null === $best || node_release_is_newer( $meta, $best['meta'] ) ) {
			$best = array( 'release' => $release, 'meta' => $meta );
		}
	}

	if ( null === $best ) {
		return new WP_Error( 'luna_update_none', '利用可能な更新はありません。' );
	}

	return $best;
}

/**
 * ZIP ファイルの sha256 が期待値と一致するか検証（純粋関数）。
 *
 * @return true|WP_Error
 */
function node_verify_zip_sha256( string $zip_path, string $expected_hex ): bool|WP_Error {
	$actual = hash_file( 'sha256', $zip_path );

	if ( ! is_string( $actual ) || ! hash_equals( $expected_hex, $actual ) ) {
		return new WP_Error( 'luna_update_sha256', 'ZIPの整合性検証（sha256）に失敗しました。' );
	}

	return true;
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
