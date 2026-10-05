<?php
/**
 * Luna Library 回帰テスト用フィクスチャ生成スクリプト（NODE_LIBRARY_REGRESSION_PLAN.md 準拠）。
 *
 * LocalWP の cybernode.local に対して、固定スラッグの node_library 項目と
 * それを埋め込んだ検証記事を「削除→再作成」で決定的に作り直す。
 * 実運用データには一切触れない（node-library-regression-* スラッグのみ操作）。
 *
 * 実行方法（LocalWPのphpバイナリで直接実行する開発用スクリプト。テーマZIPには含まれない）:
 *
 *   "/Users/saitoutatsuya/Library/Application Support/Local/lightning-services/php-8.2.30+1/bin/darwin/bin/php" \
 *     -d mysqli.default_socket="/Users/saitoutatsuya/Library/Application Support/Local/run/Q39UjXsTt/mysql/mysqld.sock" \
 *     scripts/library-fixtures.php
 *
 * テーマ側コードを変更した場合の事前同期手順（プラグインはsymlinkのため不要）:
 *   1. bun x vite build
 *   2. rsync でテーマを cybernode.local へ同期
 *   3. 同期先の plugins-embedded/ を削除（プラグインは wp-content/plugins/node-* のsymlinkが正）
 *   4. Super Cache クリア: rm -rf "/Users/saitoutatsuya/Local Sites/cybernode/app/public/wp-content/cache/supercache/"*
 *
 * 実行後は scripts/library-regression.mjs（bun）で回帰チェックを行う。
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI専用スクリプトです。\n" );
	exit( 1 );
}

$wp_load = getenv( 'NODE_WP_LOAD' ) ?: '/Users/saitoutatsuya/Local Sites/cybernode/app/public/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php が見つかりません: {$wp_load}\n（NODE_WP_LOAD 環境変数で指定できます）\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'cybernode.local';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );
require $wp_load;

if ( ! post_type_exists( 'node_library' ) ) {
	fwrite( STDERR, "node_library CPT が登録されていません（node-libraryプラグインの有効化を確認）。\n" );
	exit( 1 );
}

wp_set_current_user( 1 );

/**
 * フィクスチャ定義（正本 Test Matrix の代表パターン）。
 *
 * - steam-only     : Steam のみ（埋め込みトグルon/offの検証対象）
 * - steam-mixed    : Steam + PC/モバイル/コンソール混在（タブ・PS5単独警告・(PS5)注記）
 * - console-mixed  : Switch/Switch2 + PS4/PS5両対応 + XboxOne/Series両対応（任天堂警告・両対応時の警告抑止と注記）
 * - mobile-apps    : App Store / Google Play / Amazon App Store（appタイプ・QR・バッジ）
 * - invalid-links  : 空URL・不正URL・重複URL（空ピルなし・重複排除の検証）
 */
$fixtures = [
	[
		'slug'    => 'node-library-regression-steam-only',
		'title'   => '【回帰】Luna Library: Steam Only',
		'type'    => 'game',
		'summary' => 'Steamのみのリンクを持つ回帰テスト用フィクスチャ。',
		'links'   => [
			[ 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/1091500/Cyberpunk_2077/', 'category' => 'auto', 'hardware' => 'auto' ],
		],
	],
	[
		'slug'    => 'node-library-regression-steam-mixed',
		'title'   => '【回帰】Luna Library: Steam Mixed',
		'type'    => 'game',
		'summary' => 'Steamと他ストアが混在する回帰テスト用フィクスチャ。',
		'links'   => [
			[ 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/1091500/Cyberpunk_2077/', 'category' => 'auto', 'hardware' => 'auto' ],
			[ 'platform' => 'Microsoft Store (Windows)', 'url' => 'https://apps.microsoft.com/detail/9ncbcszsjrsb', 'category' => 'pc', 'hardware' => 'windows-pc' ],
			[ 'platform' => 'PlayStation 5', 'url' => 'https://store.playstation.com/ja-jp/product/EP4497-PPSA10666_00-0000000000000CP7', 'category' => 'console', 'hardware' => 'playstation-5' ],
			[ 'platform' => 'App Store', 'url' => 'https://apps.apple.com/jp/app/id1604212236', 'category' => 'mobile', 'hardware' => 'iphone-ipad' ],
		],
	],
	[
		'slug'    => 'node-library-regression-console-mixed',
		'title'   => '【回帰】Luna Library: Console Mixed',
		'type'    => 'game',
		'summary' => '任天堂・PS・Xboxの機種違いが混在する回帰テスト用フィクスチャ。',
		'links'   => [
			[ 'platform' => 'Nintendo Switch', 'url' => 'https://store-jp.nintendo.com/item/software/D70010000010193', 'category' => 'console', 'hardware' => 'nintendo-switch' ],
			[ 'platform' => 'Nintendo Switch 2', 'url' => 'https://store-jp.nintendo.com/item/software/D70010000096732', 'category' => 'console', 'hardware' => 'nintendo-switch-2' ],
			[ 'platform' => 'PlayStation 4', 'url' => 'https://store.playstation.com/ja-jp/product/JP0082-CUSA05088_00-KINGDOMHEARTS300', 'category' => 'console', 'hardware' => 'playstation-4' ],
			[ 'platform' => 'PlayStation 5', 'url' => 'https://store.playstation.com/ja-jp/product/JP0082-PPSA02684_00-KINGDOMHEARTS3PS', 'category' => 'console', 'hardware' => 'playstation-5' ],
			[ 'platform' => 'Xbox One', 'url' => 'https://www.xbox.com/ja-jp/games/store/x/9nblggh43dpt', 'category' => 'console', 'hardware' => 'xbox-one' ],
			[ 'platform' => 'Xbox Series X|S', 'url' => 'https://www.xbox.com/ja-jp/games/store/x/9n2s04lgxxh4', 'category' => 'console', 'hardware' => 'xbox-series' ],
		],
	],
	[
		'slug'    => 'node-library-regression-mobile-apps',
		'title'   => '【回帰】Luna Library: Mobile Apps',
		'type'    => 'app',
		'summary' => 'モバイルアプリストアのみの回帰テスト用フィクスチャ。',
		'links'   => [
			[ 'platform' => 'App Store', 'url' => 'https://apps.apple.com/jp/app/id310633997', 'category' => 'mobile', 'hardware' => 'iphone-ipad' ],
			[ 'platform' => 'Google Play', 'url' => 'https://play.google.com/store/apps/details?id=com.whatsapp', 'category' => 'mobile', 'hardware' => 'android' ],
			[ 'platform' => 'Amazon App Store', 'url' => 'https://www.amazon.co.jp/dp/B00DTHYPKW', 'category' => 'mobile', 'hardware' => 'amazon-fire' ],
		],
	],
	[
		'slug'    => 'node-library-regression-invalid-links',
		'title'   => '【回帰】Luna Library: Invalid Links',
		'type'    => 'game',
		'summary' => '空URL・不正URL・重複URLを含む回帰テスト用フィクスチャ。',
		'links'   => [
			[ 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/730/CS2/', 'category' => 'auto', 'hardware' => 'auto' ],
			// 重複URL（同一ストア）→ 表示は1ピルに重複排除されること。
			[ 'platform' => 'Steam', 'url' => 'https://store.steampowered.com/app/730/CS2/', 'category' => 'auto', 'hardware' => 'auto' ],
			// 空URL → テンプレート側で除外され、空ピルが出ないこと。
			[ 'platform' => 'Epic Games Store', 'url' => '', 'category' => 'auto', 'hardware' => 'auto' ],
			// プラットフォーム名なし → 同じく除外されること。
			[ 'platform' => '', 'url' => 'https://example.com/nameless-store/', 'category' => 'auto', 'hardware' => 'auto' ],
		],
	],
];

/**
 * スラッグ一致の投稿を（ゴミ箱を経由せず）完全削除する。
 */
function node_library_fixture_delete( string $slug, string $post_type ): void {
	$existing = get_posts(
		[
			'name'           => $slug,
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);
	foreach ( $existing as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}

$created = [];

foreach ( $fixtures as $fixture ) {
	$lib_slug  = $fixture['slug'] . '-lib';
	$post_slug = $fixture['slug'];

	node_library_fixture_delete( $lib_slug, 'node_library' );
	node_library_fixture_delete( $post_slug, 'post' );

	$lib_id = wp_insert_post(
		[
			'post_type'   => 'node_library',
			'post_status' => 'publish',
			'post_title'  => $fixture['title'],
			'post_name'   => $lib_slug,
		],
		true
	);
	if ( is_wp_error( $lib_id ) ) {
		fwrite( STDERR, "ライブラリ項目の作成に失敗: {$lib_slug}: " . $lib_id->get_error_message() . "\n" );
		exit( 1 );
	}

	// 保存ハンドラ（$_POST経由）を通さず、保存後と同じ形のメタを直接投入する（決定的な再作成）。
	update_post_meta( $lib_id, '_node_library_type', $fixture['type'] );
	update_post_meta( $lib_id, '_node_library_summary', $fixture['summary'] );
	update_post_meta( $lib_id, '_node_library_links', $fixture['links'] );

	$post_id = wp_insert_post(
		[
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => $fixture['title'],
			'post_name'    => $post_slug,
			'post_content' => "<!-- wp:paragraph --><p>Luna Library回帰テスト用の固定フィクスチャ記事です。</p><!-- /wp:paragraph -->\n\n" .
				'<!-- wp:node-library/item-card {"libraryId":' . (int) $lib_id . '} /-->',
		],
		true
	);
	if ( is_wp_error( $post_id ) ) {
		fwrite( STDERR, "検証記事の作成に失敗: {$post_slug}: " . $post_id->get_error_message() . "\n" );
		exit( 1 );
	}

	// Super Cache に旧世代のページが残っていると再作成が反映されないため、該当スラッグ分だけ破棄する。
	$cache_dir = WP_CONTENT_DIR . '/cache/supercache/cybernode.local/' . $post_slug;
	if ( is_dir( $cache_dir ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $cache_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $cache_dir );
	}

	$created[] = [
		'slug'    => $post_slug,
		'lib_id'  => $lib_id,
		'post_id' => $post_id,
		'url'     => get_permalink( $post_id ),
	];
}

echo "Luna Library フィクスチャを再作成しました:\n";
foreach ( $created as $row ) {
	echo sprintf( "  - %s (post=%d, library=%d)\n    %s\n", $row['slug'], $row['post_id'], $row['lib_id'], $row['url'] );
}
echo "次: bun scripts/library-regression.mjs\n";
