<?php
/**
 * ゲームストア URL のブログカード対応
 *
 * ニンテンドーストア / PlayStation Store / Xbox（Microsoft ストア）/ Steam の商品ページは
 * ボット防御下にあり OGP 取得に失敗することがある。取得できなかった場合でも素のリンクへ
 * 落とさず、URL から組み立てた最低限の情報でストアカードを表示するためのヘルパー群。
 *
 * @package Node
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL が対応ゲームストアの商品ページかどうかを判定する。
 *
 * @param string $url 判定対象 URL。
 * @return array{slug: string, name: string, brand_var: string}|array{} 該当しなければ空配列。
 */
function node_store_provider( string $url ): array {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	$host = (string) preg_replace( '/^www\./', '', $host );
	$path = strtolower( (string) parse_url( $url, PHP_URL_PATH ) );

	if ( '' === $host ) {
		return array();
	}

	// --- Nintendo ---
	// ストア専用ホストはパス不問。nintendo.com / nintendo.co.jp は情報サイトも兼ねるため
	// ストア配下のパスに限定する（トピックス記事等を誤ってストアカードにしない）。
	if ( node_store_host_matches( $host, array( 'store-jp.nintendo.com', 'store.nintendo.co.jp', 'ec.nintendo.com', 'store.nintendo.com' ) )
		|| ( node_store_host_matches( $host, array( 'nintendo.com' ) ) && str_contains( $path, '/store/' ) )
		|| ( node_store_host_matches( $host, array( 'nintendo.co.jp' ) ) && str_contains( $path, '/software/' ) ) ) {
		return array(
			'slug'      => 'nintendo',
			'name'      => 'ニンテンドーストア',
			'brand_var' => '--brand-nintendo',
		);
	}

	// --- PlayStation ---
	if ( node_store_host_matches( $host, array( 'store.playstation.com' ) ) ) {
		return array(
			'slug'      => 'playstation',
			'name'      => 'PlayStation Store',
			'brand_var' => '--brand-sony',
		);
	}

	// --- Xbox / Microsoft ストア ---
	if ( ( node_store_host_matches( $host, array( 'xbox.com' ) ) && str_contains( $path, '/games/store/' ) )
		|| node_store_host_matches( $host, array( 'marketplace.xbox.com', 'apps.microsoft.com' ) ) ) {
		return array(
			'slug'      => 'xbox',
			'name'      => 'Microsoft ストア',
			'brand_var' => '--brand-xbox',
		);
	}

	// --- Steam ---
	if ( node_store_host_matches( $host, array( 'store.steampowered.com', 's.team' ) ) ) {
		return array(
			'slug'      => 'steam',
			'name'      => 'Steam',
			'brand_var' => '--brand-windows',
		);
	}

	return array();
}

/**
 * ホストが対象ドメイン（またはそのサブドメイン）に一致するか判定する。
 *
 * `str_contains()` だと `nintendo.com.example.net` のような別ドメインを誤検知するため、
 * 完全一致かサブドメイン一致でのみ真を返す（node_is_excluded_oembed_provider() と同形）。
 *
 * @param string        $host    www を除いたホスト。
 * @param array<string> $domains 対象ドメイン。
 * @return bool
 */
function node_store_host_matches( string $host, array $domains ): bool {
	foreach ( $domains as $domain ) {
		if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
			return true;
		}
	}

	return false;
}

/**
 * ストア URL のスラッグから題名を組み立てる（メタ取得に失敗したときのフォールバック）。
 *
 * 例: /ja-JP/games/store/forza-horizon-5/9NKX70BBCDRN → Forza Horizon 5
 *     /app/570/Dota_2/                                → Dota 2
 * ニンテンドーストアの商品 ID 型パス（/item/software/D70010000073404）のように
 * 題名を復元できない形式では空文字を返す。
 *
 * @param string $url  ストア URL。
 * @param string $slug ストア識別子。
 * @return string 題名（組み立てられなければ空文字）。
 */
function node_store_title_from_url( string $url, string $slug ): string {
	$path     = (string) parse_url( $url, PHP_URL_PATH );
	$segments = array_values( array_filter( explode( '/', $path ), static fn( string $s ): bool => '' !== $s ) );
	if ( empty( $segments ) ) {
		return '';
	}

	$candidate = '';

	if ( 'steam' === $slug ) {
		// /app/<id>/<Name>/ 形式。名前セグメントが無い短縮形もある。
		foreach ( $segments as $index => $segment ) {
			if ( 'app' === strtolower( $segment ) && isset( $segments[ $index + 2 ] ) ) {
				$candidate = $segments[ $index + 2 ];
				break;
			}
		}
	} elseif ( 'xbox' === $slug ) {
		// /<locale>/games/store/<name>/<product-id>
		foreach ( $segments as $index => $segment ) {
			if ( 'store' === strtolower( $segment ) && isset( $segments[ $index + 1 ] ) ) {
				$candidate = $segments[ $index + 1 ];
				break;
			}
		}
	} else {
		// PlayStation は /<locale>/product/<CONCEPT-ID> のように ID 止まりのことが多い。
		// 汎用に「最後のセグメント」を候補にし、ID 判定で弾く。
		$candidate = (string) end( $segments );
	}

	if ( 'nintendo' === $slug ) {
		// nintendo.com の商品スラッグは機種名で終わる（.../minecraft-switch/）。
		// 題名としては不要なので落とす。
		$candidate = (string) preg_replace( '/-(?:for-)?(?:nintendo-)?switch(?:-2)?$/i', '', $candidate );
	}

	return node_store_humanize_slug( $candidate );
}

/**
 * URL スラッグを人が読める題名へ変換する。ID とみなせる文字列は空文字を返す。
 *
 * @param string $slug URL セグメント。
 * @return string
 */
function node_store_humanize_slug( string $slug ): string {
	$slug = rawurldecode( $slug );
	$slug = (string) preg_replace( '/\.(html?|php)$/i', '', $slug );

	if ( '' === $slug ) {
		return '';
	}

	// 商品 ID 系は題名にならない。数字のみ（Steam の app id・任天堂の title id）と、
	// 小文字を含まず数字を含む羅列（`D70010000073404` / `9NKX70BBCDRN` /
	// PS の `JP0082-PPSA01284_00-ASTROSBGDELUXE01`）を除外する。
	if ( preg_match( '/^[0-9]+$/', $slug )
		|| ( preg_match( '/^[A-Z0-9_-]+$/', $slug ) && preg_match( '/[0-9]/', $slug ) ) ) {
		return '';
	}

	$words = trim( (string) preg_replace( '/[-_+]+/', ' ', $slug ) );
	$words = trim( (string) preg_replace( '/\s+/', ' ', $words ) );

	if ( '' === $words ) {
		return '';
	}

	// 日本語を含む場合はそのまま（ucwords がマルチバイトを壊すため）。
	if ( preg_match( '/[^\x20-\x7E]/', $words ) ) {
		return $words;
	}

	return ucwords( $words );
}

/**
 * 取得したページが、要求した商品ページのままかどうかを判定する。
 *
 * Xbox は商品ページの取得に失敗すると 403 ではなく xbox.com のトップへ転送する。
 * この場合 HTTP 200 と「Xbox 公式サイト: 本体、ゲーム、コミュニティ」のような
 * サイト共通のタイトルが返るため、取得失敗として検知できずカードの題名が壊れる。
 * 最終 URL が同じストアの商品ページを指しているかを確認して弾く。
 *
 * @param string $url       要求した URL。
 * @param string $final_url リダイレクト解決後の最終 URL（不明なら空文字）。
 * @return bool 商品ページとみなせるか（最終 URL 不明時は true）。
 */
function node_store_response_is_product_page( string $url, string $final_url ): bool {
	if ( '' === $final_url ) {
		return true;
	}

	$requested = node_store_provider( $url );
	$received  = node_store_provider( $final_url );

	// 商品ページ配下から出た（例: /games/store/... → トップ）場合は別ページ。
	if ( ( $requested['slug'] ?? '' ) !== ( $received['slug'] ?? '' ) ) {
		return false;
	}

	// 商品 ID がパスから消えていたら別ページ。地域切り替えのリダイレクトでは ID は保たれる。
	// スラッグ型（Dota_2 等）は転送で落ちることがあるため、ID 型のみを対象にする。
	$segments = array_values( array_filter( explode( '/', (string) parse_url( $url, PHP_URL_PATH ) ), static fn( string $s ): bool => '' !== $s ) );
	$product  = (string) end( $segments );

	if ( '' === $product || '' !== node_store_humanize_slug( $product ) ) {
		return true;
	}

	return str_contains( strtolower( $final_url ), strtolower( $product ) );
}

/**
 * ニンテンドーストア URL からソフトのタイトル ID を取り出す。
 *
 * store-jp.nintendo.com は `/item/software/D70010000000964`、
 * ec.nintendo.com は `/titles/70010000012332` の形式。
 *
 * @param string $url ストア URL。
 * @return string タイトル ID（見つからなければ空文字）。
 */
function node_nintendo_title_id( string $url ): string {
	$path = (string) parse_url( $url, PHP_URL_PATH );

	return preg_match( '/\b(D?\d{14})\b/i', $path, $m ) ? strtoupper( $m[1] ) : '';
}

/**
 * 任天堂のソフト検索から題名・画像を引く（商品 ID しか無い URL 用）。
 *
 * ニンテンドーストアの商品ページはボット防御下にあり、`/item/software/D7001...` のような
 * ID だけの URL からは題名を復元できない。任天堂が自社サイトの検索に使っている
 * 公開エンドポイントに ID を投げて題名を得る。
 *
 * 応答が想定と違う場合は何も返さない（＝従来どおりストア名を表示する）。誤った題名を
 * 載せるより、控えめに失敗させる。
 *
 * @param string $url ニンテンドーストア URL。
 * @return array{title: string, image: string}
 */
function node_nintendo_store_lookup( string $url ): array {
	$empty = array(
		'title' => '',
		'image' => '',
	);

	$id = node_nintendo_title_id( $url );
	if ( '' === $id ) {
		return $empty;
	}

	$transient_key = 'node_nintendo_soft_' . md5( $id );
	$cached        = get_transient( $transient_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	if ( node_blogcard_fetch_failure_marker() === $cached ) {
		return $empty;
	}

	// タイトル ID をキーワード（q）に渡すと、検索側が数字を全角へ正規化して 0 件になる。
	// インデックス上の id は D なしの14桁なので、fq でその値だけを指定する。
	$response = wp_safe_remote_get(
		add_query_arg(
			array(
				'q'     => '',
				'fq'    => 'id:' . ltrim( $id, 'D' ),
				'limit' => 5,
			),
			'https://search.nintendo.jp/nintendo_soft/search.json'
		),
		array(
			'timeout'    => 8,
			'user-agent' => node_blogcard_user_agent(),
			// sslverify は既定(true)を維持する（絶対原則: 検証無効化の新規追加は禁止）
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $transient_key, node_blogcard_fetch_failure_marker(), 6 * HOUR_IN_SECONDS );
		return $empty;
	}

	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		set_transient( $transient_key, node_blogcard_fetch_failure_marker(), 6 * HOUR_IN_SECONDS );
		return $empty;
	}

	$result = node_nintendo_pick_item( $data, $id );
	set_transient( $transient_key, '' !== $result['title'] ? $result : node_blogcard_fetch_failure_marker(), '' !== $result['title'] ? WEEK_IN_SECONDS : 6 * HOUR_IN_SECONDS );

	return $result;
}

/**
 * 検索応答から、要求した ID に一致する項目の題名・画像を取り出す。
 *
 * 応答のキー名に依存しすぎないよう、項目のどこかに ID と一致する値があるかで照合する
 * （フィールド名が変わっても、間違った項目を拾わないことを優先する）。
 *
 * @param array<mixed> $data 検索応答（json_decode 済み）。
 * @param string       $id   要求したタイトル ID。
 * @return array{title: string, image: string}
 */
function node_nintendo_pick_item( array $data, string $id ): array {
	$empty = array(
		'title' => '',
		'image' => '',
	);

	$items = $data['result']['items'] ?? ( $data['items'] ?? array() );
	if ( ! is_array( $items ) ) {
		return $empty;
	}

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || ! node_nintendo_item_matches_id( $item, $id ) ) {
			continue;
		}

		$title = trim( (string) ( $item['title'] ?? '' ) );
		if ( '' === $title ) {
			continue;
		}

		return array(
			'title' => $title,
			'image' => node_nintendo_item_image( $item ),
		);
	}

	return $empty;
}

/**
 * 検索結果の項目が、要求したタイトル ID のものかを判定する。
 *
 * @param array<mixed> $item 検索結果の1件。
 * @param string       $id   要求したタイトル ID。
 * @return bool
 */
function node_nintendo_item_matches_id( array $item, string $id ): bool {
	// D 有無の表記ゆれを吸収して比較する。
	$needle = ltrim( strtoupper( $id ), 'D' );

	$found = false;
	array_walk_recursive(
		$item,
		static function ( $value ) use ( $needle, &$found ): void {
			if ( $found || ! is_scalar( $value ) ) {
				return;
			}
			$candidate = ltrim( strtoupper( (string) $value ), 'D' );
			if ( $candidate === $needle ) {
				$found = true;
			}
		}
	);

	return $found;
}

/**
 * 検索結果の項目から画像 URL を取り出す（フィールド名に依存せず走査する）。
 *
 * @param array<mixed> $item 検索結果の1件。
 * @return string 画像 URL（見つからなければ空文字）。
 */
function node_nintendo_item_image( array $item ): string {
	$image = '';

	array_walk_recursive(
		$item,
		static function ( $value ) use ( &$image ): void {
			if ( '' !== $image || ! is_string( $value ) ) {
				return;
			}
			if ( preg_match( '#^https://[^\s"\']+\.(?:jpg|jpeg|png|webp)(?:\?.*)?$#i', $value ) ) {
				$image = $value;
			}
		}
	);

	return $image;
}

/**
 * ストア URL 用のフォールバック OGP 情報を組み立てる。
 *
 * @param string                                          $url      ストア URL。
 * @param array{slug: string, name: string, brand_var: string} $provider ストア情報。
 * @return array<string, mixed>
 */
function node_store_fallback_ogp( string $url, array $provider ): array {
	$title = node_store_title_from_url( $url, $provider['slug'] );
	$image = '';

	// 商品 ID だけの任天堂 URL は、公開検索から題名を引く。
	if ( '' === $title && 'nintendo' === $provider['slug'] ) {
		$lookup = node_nintendo_store_lookup( $url );
		$title  = $lookup['title'];
		$image  = $lookup['image'];
	}

	return array(
		// 商品名を復元できない商品 ID だけの URL（store-jp.nintendo.com/item/software/<ID> 等）でも
		// 店名を題名にしてカードを出す。1.2.6 の表示に合わせる。
		'title'       => '' !== $title ? $title : $provider['name'],
		'description' => '',
		'image'       => $image,
		'favicon'     => 'https://www.google.com/s2/favicons?domain=' . rawurlencode( (string) parse_url( $url, PHP_URL_HOST ) ) . '&sz=64',
		'site_name'   => $provider['name'],
		'is_internal' => false,
		'store'       => $provider['slug'],
	);
}
