<?php

declare(strict_types=1);

/**
 * Flutter アプリ内表示（native=1）。
 *
 * Contact プラグインが本番で使っている `?native=1` をテーマ全体へ広げる。
 * 判定は PHP。Cookie `node_native_app` を正とし、同一オリジンの内部リンクにも
 * パラメータを足す（Cookie を保持しない WebView でも遷移を維持するため）。
 * `native=0` で Cookie を消して通常表示へ戻る。
 *
 * /contact/ はプラグインが応答を差し替えるため、テーマは Cookie だけ更新し
 * HTML も外枠も触らない。管理画面・REST・Ajax・フィード・サイトマップは対象外。
 *
 * @package Luminous_Core
 */

const NODE_NATIVE_APP_COOKIE = 'node_native_app';
const NODE_NATIVE_APP_COOKIE_TTL = 2592000; // 30 days

/**
 * クエリ値が native モードをオンまたはオフにするか。
 *
 * '1' / '0' / null（指定なし、または 1・0 以外）。
 */
function node_native_app_query_flag( ?string $query ): ?string {
	if ( '1' === $query || '0' === $query ) {
		return $query;
	}

	return null;
}

/**
 * テーマ側の native 表示にするか。
 *
 * お問い合わせと管理系リクエストは false（プラグイン出力と管理画面を変えない）。
 * クエリ 1 が最優先、0 は明示的な解除、それ以外は Cookie。
 */
function node_native_app_resolve_mode( ?string $query, ?string $cookie, bool $exempt, bool $contact ): bool {
	if ( $exempt || $contact ) {
		return false;
	}

	$flag = node_native_app_query_flag( $query );
	if ( '0' === $flag ) {
		return false;
	}
	if ( '1' === $flag ) {
		return true;
	}

	return '1' === $cookie;
}

/**
 * Cookie を書くか消すか。exempt なリクエストでは何もしない。
 *
 * @return 'set'|'clear'|'none'
 */
function node_native_app_cookie_action( ?string $query, bool $exempt ): string {
	if ( $exempt ) {
		return 'none';
	}

	$flag = node_native_app_query_flag( $query );
	if ( '1' === $flag ) {
		return 'set';
	}
	if ( '0' === $flag ) {
		return 'clear';
	}

	return 'none';
}

/**
 * リクエストパスがテーマの native 対象外か（管理画面・REST・Ajax・フィード・サイトマップ）。
 */
function node_native_app_path_is_exempt( string $request_path ): bool {
	$path = strtolower( $request_path );

	if ( preg_match( '#/wp-admin(/|$)#', $path ) ) {
		return true;
	}
	if ( str_contains( $path, 'wp-login.php' ) || str_contains( $path, 'wp-cron.php' ) || str_contains( $path, 'admin-ajax.php' ) || str_contains( $path, 'xmlrpc.php' ) ) {
		return true;
	}
	if ( preg_match( '#/wp-json(/|$)#', $path ) ) {
		return true;
	}
	if ( preg_match( '#/(feed|rss|rdf|atom)(/|$)#', $path ) ) {
		return true;
	}
	if ( preg_match( '#/(wp-sitemap[^/]*|sitemap[^/]*|news-sitemap[^/]*)\.xml$#', $path ) ) {
		return true;
	}

	return false;
}

/**
 * /contact/ （Contact プラグインの領域）か。サブディレクトリ設置も見る。
 */
function node_native_app_is_contact_path( string $request_path, string $home_path ): bool {
	$path = strtolower( $request_path );
	$home = strtolower( rtrim( $home_path, '/' ) );

	if ( '' !== $home && '/' !== $home && str_starts_with( $path, $home ) ) {
		$path = substr( $path, strlen( $home ) );
	}

	$path = rtrim( $path, '/' );

	return '/contact' === $path;
}

/**
 * canonical 等から native クエリだけを除く。他のパラメータとフラグメントは残す。
 */
function node_native_app_strip_native_query( string $url ): string {
	if ( ! str_contains( $url, 'native=' ) ) {
		return $url;
	}

	$fragment = '';
	$hash     = strpos( $url, '#' );
	if ( false !== $hash ) {
		$fragment = substr( $url, $hash );
		$url      = substr( $url, 0, $hash );
	}

	$parts = explode( '?', $url, 2 );
	if ( count( $parts ) < 2 ) {
		return $url . $fragment;
	}

	$kept = array();
	foreach ( explode( '&', $parts[1] ) as $pair ) {
		if ( '' === $pair ) {
			continue;
		}
		$name = rawurldecode( explode( '=', $pair, 2 )[0] );
		if ( 'native' === $name ) {
			continue;
		}
		$kept[] = $pair;
	}

	$rebuilt = $parts[0];
	if ( $kept ) {
		$rebuilt .= '?' . implode( '&', $kept );
	}

	return $rebuilt . $fragment;
}

/**
 * html 開始タグへアプリが読める印を足す。
 */
function node_native_app_mark_language_attributes( string $output ): string {
	if ( ! str_contains( $output, 'is-native-app' ) ) {
		if ( preg_match( '/class=(["\'])(.*?)\\1/', $output ) ) {
			$output = (string) preg_replace( '/class=(["\'])(.*?)\\1/', 'class=$1$2 is-native-app$1', $output, 1 );
		} else {
			$output .= ' class="is-native-app"';
		}
	}

	if ( ! str_contains( $output, 'data-native-app=' ) ) {
		$output .= ' data-native-app="1"';
	}

	return $output;
}

/**
 * 属性値が「引き継ぎ候補の URL」か。値そのもの（newest 等）は対象外。
 */
function node_native_app_is_rewritable_reference( string $url ): bool {
	$decoded = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( '' === $decoded || '#' === $decoded[0] ) {
		return false;
	}
	if ( preg_match( '#^(mailto:|tel:|javascript:)#i', $decoded ) ) {
		return false;
	}
	if ( preg_match( '#^(https?:)?//#i', $decoded ) ) {
		return true;
	}
	if ( '/' === $decoded[0] || str_starts_with( $decoded, './' ) || str_starts_with( $decoded, '../' ) ) {
		return true;
	}

	return str_contains( $decoded, '/' );
}

/**
 * 同一オリジンの内部 URL に native=1 を足す。対象外なら null。
 */
function node_native_app_transform_url( string $url, string $home ): ?string {
	if ( ! node_native_app_is_rewritable_reference( $url ) ) {
		return null;
	}

	$decoded = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( preg_match( '/(?:^|[?&])native=/', $decoded ) ) {
		return null;
	}

	$home_host = strtolower( (string) ( parse_url( $home, PHP_URL_HOST ) ?? '' ) );
	$host      = $home_host;

	if ( str_starts_with( $decoded, '//' ) ) {
		$host = strtolower( (string) ( parse_url( 'https:' . $decoded, PHP_URL_HOST ) ?? '' ) );
	} elseif ( preg_match( '#^https?://#i', $decoded ) ) {
		$host = strtolower( (string) ( parse_url( $decoded, PHP_URL_HOST ) ?? '' ) );
	}

	if ( '' === $home_host || $host !== $home_host ) {
		return null;
	}

	$path = (string) ( parse_url( $decoded, PHP_URL_PATH ) ?? '' );
	if ( '' === $path && ( str_starts_with( $decoded, '/' ) || str_starts_with( $decoded, './' ) || str_starts_with( $decoded, '../' ) || ! preg_match( '#^https?://#i', $decoded ) ) ) {
		$bare = preg_replace( '/[?#].*$/', '', $decoded );
		$path = is_string( $bare ) ? $bare : '';
	}
	if ( node_native_app_path_is_exempt( $path ) ) {
		return null;
	}

	$hash   = '';
	$before = $decoded;
	$mark   = strpos( $decoded, '#' );
	if ( false !== $mark ) {
		$before = substr( $decoded, 0, $mark );
		$hash   = substr( $decoded, $mark );
	}

	$separator = str_contains( $before, '?' ) ? '&' : '?';

	return $before . $separator . 'native=1' . $hash;
}

/**
 * フロント HTML の a / form / option だけを書き換える。script・style・link は触らない。
 */
function node_native_app_rewrite_markup( string $html, string $home ): string {
	if ( '' === $html || '' === $home ) {
		return $html;
	}

	$parts = preg_split( '#(<script\\b[^>]*>.*?</script>|<style\\b[^>]*>.*?</style>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) ) {
		return $html;
	}

	foreach ( $parts as $index => $part ) {
		if ( 1 === $index % 2 ) {
			continue;
		}

		$rewritten = preg_replace_callback(
			'#<(a|form|option)\\b([^>]*?)\\s(href|action|value)\\s*=\\s*([\'"])([^\'"]*)\\4#i',
			static function ( array $match ) use ( $home ): string {
				$tag  = strtolower( $match[1] );
				$attr = strtolower( $match[3] );
				if ( ( 'a' === $tag && 'href' !== $attr ) || ( 'form' === $tag && 'action' !== $attr ) || ( 'option' === $tag && 'value' !== $attr ) ) {
					return $match[0];
				}

				$next = node_native_app_transform_url( $match[5], $home );
				if ( null === $next ) {
					return $match[0];
				}

				$escaped = htmlspecialchars( $next, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				return '<' . $match[1] . $match[2] . ' ' . $attr . '=' . $match[4] . $escaped . $match[4];
			},
			$part
		);

		$parts[ $index ] = is_string( $rewritten ) ? $rewritten : $part;
	}

	return implode( '', $parts );
}

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * 今回のリクエストの native クエリ。配列は無視する。
 */
function node_native_app_query_value(): ?string {
	if ( ! isset( $_GET['native'] ) ) {
		return null;
	}

	$raw = wp_unslash( $_GET['native'] );
	if ( is_array( $raw ) ) {
		return null;
	}

	return (string) $raw;
}

/**
 * 管理画面・Ajax・cron・REST・フィード・サイトマップか。
 */
function node_native_app_request_is_exempt(): bool {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return true;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}
	if ( wp_is_json_request() ) {
		return true;
	}
	if ( isset( $_GET['feed'] ) ) {
		return true;
	}
	if ( did_action( 'wp' ) && is_feed() ) {
		return true;
	}

	return node_native_app_path_is_exempt( node_native_app_request_path() );
}

/**
 * Contact プラグインのページか。テーマの外枠も HTML 書き換えもかけない。
 */
function node_native_app_is_contact_request(): bool {
	$home_path = (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '' );
	if ( node_native_app_is_contact_path( node_native_app_request_path(), $home_path ) ) {
		return true;
	}

	if ( isset( $_GET['pagename'] ) && 'contact' === (string) wp_unslash( $_GET['pagename'] ) ) {
		return true;
	}

	return did_action( 'wp' ) && is_page( 'contact' );
}

function node_native_app_request_path(): string {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	$path = wp_parse_url( $uri, PHP_URL_PATH );

	return is_string( $path ) ? $path : '';
}

/**
 * テーマの外枠を出さないアプリ内表示か。
 */
function node_is_native_app(): bool {
	$cookie = isset( $_COOKIE[ NODE_NATIVE_APP_COOKIE ] ) ? (string) $_COOKIE[ NODE_NATIVE_APP_COOKIE ] : null;

	return node_native_app_resolve_mode(
		node_native_app_query_value(),
		$cookie,
		node_native_app_request_is_exempt(),
		node_native_app_is_contact_request()
	);
}

/**
 * native=1 で Cookie を保存し、native=0 で消す。/contact/ でも保存だけは行う。
 */
function node_native_app_sync_cookie(): void {
	if ( node_native_app_request_is_exempt() || headers_sent() ) {
		return;
	}

	$action = node_native_app_cookie_action( node_native_app_query_value(), false );
	if ( 'none' === $action ) {
		return;
	}

	$options = array(
		'expires'  => 'set' === $action ? time() + NODE_NATIVE_APP_COOKIE_TTL : time() - DAY_IN_SECONDS,
		'path'     => ( defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ) ? COOKIEPATH : '/',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	);
	if ( defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) && '' !== COOKIE_DOMAIN ) {
		$options['domain'] = COOKIE_DOMAIN;
	}

	$value = 'set' === $action ? '1' : '';
	setcookie( NODE_NATIVE_APP_COOKIE, $value, $options );

	if ( 'set' === $action ) {
		$_COOKIE[ NODE_NATIVE_APP_COOKIE ] = '1';
	} else {
		unset( $_COOKIE[ NODE_NATIVE_APP_COOKIE ] );
	}
}
add_action( 'init', 'node_native_app_sync_cookie', 1 );

/**
 * @param string $output language_attributes() の属性文字列。
 */
function node_native_app_language_attributes( string $output ): string {
	if ( ! node_is_native_app() ) {
		return $output;
	}

	return node_native_app_mark_language_attributes( $output );
}
add_filter( 'language_attributes', 'node_native_app_language_attributes' );

/**
 * @param array<int, string> $classes body クラス。
 * @return array<int, string>
 */
function node_native_app_body_class( array $classes ): array {
	if ( node_is_native_app() && ! in_array( 'is-native-app', $classes, true ) ) {
		$classes[] = 'is-native-app';
	}

	return $classes;
}
add_filter( 'body_class', 'node_native_app_body_class' );

/**
 * コアの rel=canonical から native を外す。パラメータが無い URL では無変更。
 *
 * @param mixed $url canonical URL。
 * @return mixed
 */
function node_native_app_filter_canonical_url( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return $url;
	}

	return node_native_app_strip_native_query( $url );
}
add_filter( 'get_canonical_url', 'node_native_app_filter_canonical_url', 20 );

/**
 * 本番の単独プラグインが AdBlock モーダルを足していても、native では出さない。
 * プラグインのソースは変更しない。
 */
function node_native_app_unhook_adblock(): void {
	if ( ! node_is_native_app() ) {
		return;
	}

	global $wp_filter;

	foreach ( array( 'wp_footer', 'wp_enqueue_scripts' ) as $hook ) {
		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
			continue;
		}

		$callbacks = $wp_filter[ $hook ]->callbacks ?? array();
		foreach ( $callbacks as $priority => $list ) {
			foreach ( $list as $item ) {
				$callback = $item['function'] ?? null;
				if ( ! is_array( $callback ) || ! isset( $callback[0] ) || ! is_object( $callback[0] ) ) {
					continue;
				}
				if ( str_ends_with( get_class( $callback[0] ), 'AdBlockDetector' ) ) {
					remove_action( $hook, $callback, (int) $priority );
				}
			}
		}
	}
}
add_action( 'wp', 'node_native_app_unhook_adblock', 20 );
add_action( 'wp_footer', 'node_native_app_unhook_adblock', 0 );

/**
 * 描画済み HTML の内部リンクへ native=1 を足す。
 * permalink フィルタは canonical とサイトマップへ漏れるため使わない。
 */
function node_native_app_start_buffer(): void {
	if ( ! node_is_native_app() ) {
		return;
	}

	ob_start( 'node_native_app_buffer_callback' );
}
add_action( 'template_redirect', 'node_native_app_start_buffer', 100 );

function node_native_app_buffer_callback( string $html ): string {
	if ( '' === $html || false === stripos( $html, '<html' ) ) {
		return $html;
	}

	return node_native_app_rewrite_markup( $html, home_url( '/' ) );
}
