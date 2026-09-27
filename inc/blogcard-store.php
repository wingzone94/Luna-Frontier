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
 * 公式照会が一時的に失敗したとき、過去に ID 照合済みの題名を使う。
 *
 * @param string $key ストアと商品 ID を含むキー。
 * @return string
 */
function node_store_saved_title( string $key ): string {
	return trim( (string) get_option( 'node_store_title_' . md5( $key ), '' ) );
}

/**
 * ID 照合済みの商品名を障害時用に保存する。
 *
 * @param string $key   ストアと商品 ID を含むキー。
 * @param string $title 商品名。
 * @return void
 */
function node_store_save_title( string $key, string $title ): void {
	if ( '' !== trim( $title ) ) {
		update_option( 'node_store_title_' . md5( $key ), $title, false );
	}
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
	$saved = node_store_saved_title( 'nintendo:' . $id );
	$empty['title'] = $saved;

	$transient_key = 'node_nintendo_soft_' . md5( $id );
	$cached        = get_transient( $transient_key );
	if ( is_array( $cached ) ) {
		node_store_save_title( 'nintendo:' . $id, (string) ( $cached['title'] ?? '' ) );
		return $cached;
	}
	if ( node_blogcard_fetch_failure_marker() === $cached ) {
		return $empty;
	}

	$response = wp_safe_remote_get(
		add_query_arg(
			array(
				// q は商品 ID を検索対象に含めない。id フィルターで正確に照合する。
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
	if ( '' !== $result['title'] ) {
		node_store_save_title( 'nintendo:' . $id, $result['title'] );
	}

	return '' !== $result['title'] ? $result : $empty;
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
 * Microsoft の公開商品カタログから、URL 内の Store ID に一致する題名を取得する。
 *
 * @param string $url Xbox / Microsoft Store の商品 URL。
 * @return string 題名。取得できなければ空文字。
 */
function node_microsoft_store_title( string $url ): string {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	if ( ! preg_match( '#/(9[A-Z0-9]{11})(?:/|$)#i', $path, $matches ) ) {
		return '';
	}

	$id            = strtoupper( $matches[1] );
	$transient_key = 'node_microsoft_product_' . md5( $id );
	$saved         = node_store_saved_title( 'microsoft:' . $id );
	$cached        = get_transient( $transient_key );
	if ( is_string( $cached ) && '' !== $cached ) {
		if ( node_blogcard_fetch_failure_marker() === $cached ) {
			return $saved;
		}
		node_store_save_title( 'microsoft:' . $id, $cached );
		return $cached;
	}

	// 日本で未配信の商品は JP カタログに無いことがあるため、US も照会する。
	foreach ( array( array( 'JP', 'ja-JP' ), array( 'US', 'en-US' ) ) as list( $market, $language ) ) {
		$response = wp_safe_remote_get(
			'https://displaycatalog.mp.microsoft.com/v7.0/products/' . rawurlencode( $id ) . '?fieldsTemplate=Details&market=' . $market . '&languages=' . $language,
			array( 'timeout' => 8 )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			continue;
		}

		$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$product = is_array( $data ) ? ( $data['Product'] ?? array() ) : array();
		if ( ! is_array( $product ) || $id !== strtoupper( (string) ( $product['ProductId'] ?? '' ) ) ) {
			continue;
		}

		foreach ( $product['LocalizedProperties'] ?? array() as $properties ) {
			$title = is_array( $properties ) ? trim( (string) ( $properties['ProductTitle'] ?? '' ) ) : '';
			if ( '' !== $title ) {
				set_transient( $transient_key, $title, WEEK_IN_SECONDS );
				node_store_save_title( 'microsoft:' . $id, $title );
				return $title;
			}
		}
	}

	set_transient( $transient_key, node_blogcard_fetch_failure_marker(), 6 * HOUR_IN_SECONDS );
	return $saved;
}

/**
 * PlayStation の公開ストア照会から、商品 ID に一致する題名を取得する。
 *
 * @param string $url PlayStation Store の商品 URL。
 * @return string 題名。取得できなければ空文字。
 */
function node_playstation_store_title( string $url ): string {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	if ( ! preg_match( '#^/([a-z]{2}-[a-z]{2})/(product|concept)/([A-Z0-9_-]+)(?:/|$)#i', $path, $matches ) ) {
		return '';
	}

	$locale        = strtolower( $matches[1] );
	$type          = strtolower( $matches[2] );
	$id            = strtoupper( $matches[3] );
	$transient_key = 'node_playstation_' . $type . '_' . md5( $locale . ':' . $id );
	$saved         = node_store_saved_title( 'playstation:' . $locale . ':' . $type . ':' . $id );
	$cached        = get_transient( $transient_key );
	if ( is_string( $cached ) && '' !== $cached ) {
		if ( node_blogcard_fetch_failure_marker() === $cached ) {
			return $saved;
		}
		node_store_save_title( 'playstation:' . $locale . ':' . $type . ':' . $id, $cached );
		return $cached;
	}

	$query = array(
		'operationName' => 'concept' === $type ? 'metGetConceptById' : 'metGetProductById',
		'variables'     => wp_json_encode( array( 'concept' === $type ? 'conceptId' : 'productId' => $id ) ),
		'extensions'    => wp_json_encode(
			array(
				'persistedQuery' => array(
					'version'    => 1,
					'sha256Hash' => 'concept' === $type
						? 'cc90404ac049d935afbd9968aef523da2b6723abfb9d586e5f77ebf7c5289006'
						: 'a128042177bd93dd831164103d53b73ef790d56f51dae647064cb8f9d9fc9d1a',
				),
			)
		),
	);
	$response = wp_safe_remote_get(
		add_query_arg( $query, 'https://web.np.playstation.com/api/graphql/v1/op' ),
		array(
			'timeout' => 8,
			'headers' => array(
				'x-psn-store-locale-override' => $locale,
				'content-type'                => 'application/json',
			),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $transient_key, node_blogcard_fetch_failure_marker(), 6 * HOUR_IN_SECONDS );
		return $saved;
	}

	$data    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$product = is_array( $data ) ? ( $data['data'][ 'concept' === $type ? 'conceptRetrieve' : 'productRetrieve' ] ?? array() ) : array();
	$title   = is_array( $product ) && $id === strtoupper( (string) ( $product['id'] ?? '' ) )
		? trim( (string) ( $product['name'] ?? '' ) ) : '';
	set_transient( $transient_key, '' !== $title ? $title : node_blogcard_fetch_failure_marker(), '' !== $title ? WEEK_IN_SECONDS : 6 * HOUR_IN_SECONDS );
	if ( '' !== $title ) {
		node_store_save_title( 'playstation:' . $locale . ':' . $type . ':' . $id, $title );
	}
	return '' !== $title ? $title : $saved;
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
	if ( 'xbox' === $provider['slug'] ) {
		$catalog_title = node_microsoft_store_title( $url );
		if ( '' !== $catalog_title ) {
			$title = $catalog_title;
		}
	}
	if ( 'playstation' === $provider['slug'] ) {
		$api_title = node_playstation_store_title( $url );
		if ( '' !== $api_title ) {
			$title = $api_title;
		}
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
