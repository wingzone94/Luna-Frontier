<?php
/**
 * native モードの純粋関数テスト。WordPress なしで実行できる。
 *
 * php scripts/native-app-logic-test.php
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/inc/native-app.php';

$failures = 0;

function native_app_assert( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		echo "ok  {$message}\n";
		return;
	}
	$failures++;
	echo "FAIL {$message}\n";
}

$home = 'https://luminous-core.net/';

native_app_assert( true === node_native_app_resolve_mode( '1', null, false, false ), 'native=1 はアプリ内表示' );
native_app_assert( false === node_native_app_resolve_mode( '0', '1', false, false ), 'native=0 は Cookie より優先して解除' );
native_app_assert( true === node_native_app_resolve_mode( null, '1', false, false ), 'Cookie だけで維持' );
native_app_assert( false === node_native_app_resolve_mode( null, null, false, false ), 'パラメータも Cookie も無ければ通常表示' );
native_app_assert( false === node_native_app_resolve_mode( '1', '1', true, false ), '管理系リクエストは表示しない' );
native_app_assert( false === node_native_app_resolve_mode( '1', '1', false, true ), '/contact/ はテーマ側の native 表示にしない' );
native_app_assert( false === node_native_app_resolve_mode( 'yes', null, false, false ), '1 以外の値だけではオンにしない' );
native_app_assert( 'set' === node_native_app_cookie_action( '1', false ), 'native=1 は Cookie を保存' );
native_app_assert( 'clear' === node_native_app_cookie_action( '0', false ), 'native=0 は Cookie を消す' );
native_app_assert( 'none' === node_native_app_cookie_action( null, false ), '指定が無ければ Cookie を変えない' );
native_app_assert( 'set' === node_native_app_cookie_action( '1', false ), 'contact でもクエリ 1 なら保存対象' );
native_app_assert( 'none' === node_native_app_cookie_action( '1', true ), '管理系では Cookie を書かない' );

native_app_assert( node_native_app_path_is_exempt( '/wp-admin/edit.php' ), 'wp-admin は対象外' );
native_app_assert( node_native_app_path_is_exempt( '/wp-login.php' ), 'ログインは対象外' );
native_app_assert( node_native_app_path_is_exempt( '/wp-json/wp/v2/posts' ), 'REST は対象外' );
native_app_assert( node_native_app_path_is_exempt( '/wp-admin/admin-ajax.php' ), 'Ajax は対象外' );
native_app_assert( node_native_app_path_is_exempt( '/feed/' ), 'フィードは対象外' );
native_app_assert( node_native_app_path_is_exempt( '/wp-sitemap.xml' ), 'サイトマップは対象外' );
native_app_assert( ! node_native_app_path_is_exempt( '/hello/' ), '記事パスは対象' );
native_app_assert( node_native_app_is_contact_path( '/contact/', '/' ), '/contact/ を識別' );
native_app_assert( node_native_app_is_contact_path( '/contact', '' ), '末尾スラッシュ無しも識別' );
native_app_assert( node_native_app_is_contact_path( '/blog/contact/', '/blog' ), 'サブディレクトリでも識別' );
native_app_assert( ! node_native_app_is_contact_path( '/contact-us/', '/' ), '別スラッグは contact ではない' );

native_app_assert(
	'https://luminous-core.net/post/' === node_native_app_strip_native_query( 'https://luminous-core.net/post/?native=1' ),
	'canonical から native だけを外す'
);
native_app_assert(
	'https://luminous-core.net/post/?s=a' === node_native_app_strip_native_query( 'https://luminous-core.net/post/?s=a&native=1' ),
	'他のクエリは残す'
);
native_app_assert(
	'https://luminous-core.net/post/?s=a#sec' === node_native_app_strip_native_query( 'https://luminous-core.net/post/?native=1&s=a#sec' ),
	'フラグメントを残して native を外す'
);
native_app_assert(
	'https://luminous-core.net/post/' === node_native_app_strip_native_query( 'https://luminous-core.net/post/' ),
	'native が無ければ canonical は不変'
);

$marked = node_native_app_mark_language_attributes( 'lang="ja"' );
native_app_assert( str_contains( $marked, 'class="is-native-app"' ) && str_contains( $marked, 'data-native-app="1"' ), 'html にクラスと data 属性' );
$marked_class = node_native_app_mark_language_attributes( 'lang="ja" class="no-js"' );
native_app_assert( str_contains( $marked_class, 'class="no-js is-native-app"' ), '既存 class に is-native-app を足す' );

$html = <<<'HTML'
<html><head>
<link rel="canonical" href="https://luminous-core.net/post/">
<script>var html = '<a href="/secret/">x</a>';</script>
</head><body>
<a href="https://luminous-core.net/post/">記事</a>
<a href="https://luminous-core.net/post/?utm=1">追跡</a>
<a href="https://luminous-core.net/post/#sec">節</a>
<a href="/hello/">相対</a>
<a href="https://example.com/out/">外部</a>
<a href="mailto:editor@luminous-core.net">mail</a>
<a href="#top">hash</a>
<a href="https://luminous-core.net/wp-admin/">管理</a>
<a href="https://luminous-core.net/wp-json/wp/v2/posts">API</a>
<a href="https://luminous-core.net/feed/">RSS</a>
<a href="https://luminous-core.net/wp-sitemap.xml">map</a>
<a href="https://luminous-core.net/contact/">問い合わせ</a>
<a href="https://luminous-core.net/post/?native=0">解除</a>
<form action="https://luminous-core.net/"><input></form>
<option value="https://luminous-core.net/post/2/">2</option>
<option value="newest">新着</option>
</body></html>
HTML;

$out = node_native_app_rewrite_markup( $html, $home );

native_app_assert( str_contains( $out, 'href="https://luminous-core.net/post/?native=1"' ), '内部リンクに native=1' );
native_app_assert(
	str_contains( $out, 'href="https://luminous-core.net/post/?utm=1' . htmlspecialchars( '&', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . 'native=1"' ),
	'既存クエリの後ろに足す'
);
native_app_assert( str_contains( $out, 'href="https://luminous-core.net/post/?native=1#sec"' ), 'フラグメントの前に足す' );
native_app_assert( str_contains( $out, 'href="/hello/?native=1"' ), '相対 URL にも足す' );
native_app_assert( str_contains( $out, 'href="https://example.com/out/"' ), '外部リンクは不変' );
native_app_assert( str_contains( $out, 'href="mailto:editor@luminous-core.net"' ), 'mailto は不変' );
native_app_assert( str_contains( $out, 'href="#top"' ), 'ページ内アンカーは不変' );
native_app_assert( str_contains( $out, 'href="https://luminous-core.net/wp-admin/"' ), '管理画面リンクは不変' );
native_app_assert( str_contains( $out, 'href="https://luminous-core.net/wp-json/wp/v2/posts"' ), 'REST リンクは不変' );
native_app_assert( str_contains( $out, 'href="https://luminous-core.net/feed/"' ), 'フィードリンクは不変' );
native_app_assert( str_contains( $out, 'href="https://luminous-core.net/wp-sitemap.xml"' ), 'サイトマップリンクは不変' );
native_app_assert( str_contains( $out, 'href="https://luminous-core.net/contact/?native=1"' ), 'contact へのリンクはアプリ内フォームへ引き継ぐ' );
native_app_assert( str_contains( $out, 'href="https://luminous-core.net/post/?native=0"' ), 'native=0 は上書きしない' );
native_app_assert( str_contains( $out, 'action="https://luminous-core.net/?native=1"' ), 'フォーム action を引き継ぐ' );
native_app_assert( str_contains( $out, 'value="https://luminous-core.net/post/2/?native=1"' ), 'ページ送り option を引き継ぐ' );
native_app_assert( str_contains( $out, 'value="newest"' ), 'URL でない option は不変' );
native_app_assert( str_contains( $out, '<link rel="canonical" href="https://luminous-core.net/post/">' ), 'canonical の link 要素は書き換えない' );
native_app_assert( str_contains( $out, '<script>var html = \'<a href="/secret/">x</a>\';</script>' ), 'script 内のリンクは書き換えない' );

$root = dirname( __DIR__ );
$header = (string) file_get_contents( $root . '/header.php' );
$footer = (string) file_get_contents( $root . '/footer.php' );
$single = (string) file_get_contents( $root . '/single.php' );
$share  = (string) file_get_contents( $root . '/template-parts/single/footer.php' );
$toc    = (string) file_get_contents( $root . '/template-parts/single/toc.php' );
$seo    = (string) file_get_contents( $root . '/inc/seo.php' );
$suggest = (string) file_get_contents( $root . '/src/scripts/view-mode-suggest.js' );

native_app_assert( str_contains( $header, "data-native-app=\"1\"" ) && str_contains( $header, 'node_is_native_app()' ), 'header は native のとき外枠を出さない' );
native_app_assert( str_contains( $footer, '$node_native_app' ) && str_contains( $footer, 'floating-actions' ), 'footer は native のときフッターと FAB を出さない' );
native_app_assert( str_contains( $single, 'node_is_native_app()' ) && str_contains( $single, 'm3-inline-toc' ), '本文内目次は残し関連記事は分岐する' );
native_app_assert( str_contains( $share, 'node_is_native_app()' ), '共有ボタンは native で出さない' );
native_app_assert( str_contains( $toc, 'm3-article-toc-data' ) && str_contains( $toc, 'node_is_native_app()' ), '目次データは残し固定目次は出さない' );
native_app_assert( str_contains( $seo, 'node_is_native_app()' ), 'パンくずは native で return する' );
native_app_assert( str_contains( $suggest, "dataset.nativeApp === '1'" ), '表示モード提案は native で出さない' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} failed\n" );
	exit( 1 );
}

echo "all passed\n";
