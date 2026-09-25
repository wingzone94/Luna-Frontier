<?php

declare(strict_types=1);
/**
 * Node テーマ組み込みブログカード
 *
 * OGP 取得・ショートコード・URL 単独行の自動変換をテーマ側で提供する。
 * Luminous Nexus プラグインが未読み込みでも動作する。
 *
 * @package Node
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL が自サイト（同一ホスト）かどうかを判定する。
 *
 * `str_contains($url, home_url())` はスキーム依存で、HTTPS リクエスト時に home_url() が
 * https を返す一方で本文中の URL が http だと内部判定に失敗する。ホスト同士を比較して
 * スキームに依存しないようにする。
 *
 * @param string $url 判定対象 URL。
 * @return bool
 */
function node_is_internal_url( string $url ): bool {
	$url_host  = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	$home_host = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );

	return '' !== $url_host && $url_host === $home_host;
}

/**
 * 自サイト URL を投稿 ID へ変換する（スキーム差異を吸収）。
 *
 * @param string $url 自サイト URL。
 * @return int 投稿 ID（見つからなければ 0）。
 */
function node_internal_url_to_postid( string $url ): int {
	$post_id = url_to_postid( $url );
	if ( ! $post_id ) {
		// home 側のスキームへ揃えて再試行（http/https 混在対策）。
		$post_id = url_to_postid( set_url_scheme( $url ) );
	}

	return (int) $post_id;
}

/**
 * URL から OGP 情報を取得する（キャッシュ対応）
 *
 * @param string $url 対象 URL。
 * @return array<string, mixed>|false
 */
function node_get_ogp_data( string $url ) {
	$url = esc_url_raw( $url );
	if ( empty( $url ) ) {
		return false;
	}

	$transient_key = 'node_ogp_' . md5( $url );
	$cached        = get_transient( $transient_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	// 取得失敗は負のキャッシュとして記録する。これが無いと、失敗する URL では
	// レンダーのたびに 15 秒タイムアウトの外部 HTTP が走り表示が遅延する。
	if ( node_blogcard_fetch_failure_marker() === $cached ) {
		return false;
	}

	$ogp = array(
		'title'       => '',
		'description' => '',
		'image'       => '',
		'favicon'     => '',
		'site_name'   => '',
		'is_internal' => false,
	);

	if ( node_is_internal_url( $url ) ) {
		$post_id = node_internal_url_to_postid( $url );
		if ( $post_id ) {
			$ogp['title']       = get_the_title( $post_id );
			$ogp['description'] = get_the_excerpt( $post_id );
			$ogp['image']       = get_the_post_thumbnail_url( $post_id, 'large' ) ?: '';
			$ogp['site_name']   = get_bloginfo( 'name' );
			$ogp['is_internal'] = true;
			$ogp['favicon']     = get_site_icon_url( 32 ) ?: '';
			set_transient( $transient_key, $ogp, WEEK_IN_SECONDS );
			return $ogp;
		}
	}

	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'    => 15,
			// ゲームストア（任天堂・PlayStation・Xbox 等）はボット防御下にあり、
			// 「compatible; ...」型の自己申告ボット UA では 403 を返す。同梱の
			// node-library と同じブラウザ UA に揃えて商品ページを取得できるようにする。
			'user-agent' => node_blogcard_user_agent(),
			'headers'    => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'ja,en-US;q=0.9,en;q=0.8',
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return node_blogcard_cache_failure( $transient_key );
	}

	$html = wp_remote_retrieve_body( $response );
	if ( empty( $html ) ) {
		return node_blogcard_cache_failure( $transient_key );
	}

	$html = node_blogcard_to_utf8( $html, (string) wp_remote_retrieve_header( $response, 'content-type' ) );

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	// UTF-8 の明示は meta ではなく XML 宣言で行う。`mb_convert_encoding( …, 'HTML-ENTITIES' )` は
	// PHP 8.2 で非推奨（8.2 環境では本文中に Deprecated が漏れうる）ため使わない。
	@$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();

	$xpath = new DOMXPath( $dom );
	$meta  = node_extract_page_meta( $xpath, $url );

	$ogp['title']       = $meta['title'];
	$ogp['description'] = $meta['description'];
	$ogp['image']       = $meta['image'];
	$ogp['site_name']   = '' !== $meta['site_name'] ? $meta['site_name'] : (string) parse_url( $url, PHP_URL_HOST );
	$ogp['favicon']     = 'https://www.google.com/s2/favicons?domain=' . rawurlencode( (string) parse_url( $url, PHP_URL_HOST ) ) . '&sz=64';
	// 転送先を記録する。要求した商品ページとは別のページ（ストアのトップ等）を掴んでいないか、
	// 呼び出し側が検証できるようにするため。
	$ogp['final_url']   = node_http_final_url( $response );

	// 題名が取れないページはカードを組み立てられない（node_blogcard_markup() が空を返す）。
	// 成功として1週間キャッシュせず、失敗として扱う。
	if ( '' === $ogp['title'] ) {
		return node_blogcard_cache_failure( $transient_key );
	}

	set_transient( $transient_key, $ogp, WEEK_IN_SECONDS );
	return $ogp;
}

/**
 * HTTP レスポンスからリダイレクト解決後の最終 URL を取り出す。
 *
 * `pre_http_request` で短絡されたレスポンス（テスト等）には `http_response` が無いため、
 * その場合は空文字（＝転送の有無は不明）を返す。
 *
 * @param array<string, mixed>|WP_Error $response wp_remote_get の戻り値。
 * @return string 最終 URL（判定できなければ空文字）。
 */
function node_http_final_url( $response ): string {
	if ( ! is_array( $response ) || empty( $response['http_response'] ) ) {
		return '';
	}

	$http_response = $response['http_response'];
	if ( ! is_object( $http_response ) || ! method_exists( $http_response, 'get_response_object' ) ) {
		return '';
	}

	$requests_response = $http_response->get_response_object();

	return is_object( $requests_response ) && ! empty( $requests_response->url ) ? (string) $requests_response->url : '';
}

/**
 * OGP 取得に使うブラウザ UA を返す。
 *
 * @return string
 */
function node_blogcard_user_agent(): string {
	return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';
}

/**
 * 取得した HTML を UTF-8 へ揃える。
 *
 * Content-Type ヘッダに charset を書かず `<meta charset>` だけで宣言するサイトが多いため、
 * ヘッダ→HTML の順に文字コードを探す。UTF-8 以外のまま DOMDocument に渡すと題名が
 * 文字化けし、結果としてカードが崩れる。
 *
 * @param string $html         取得した本文。
 * @param string $content_type Content-Type ヘッダ。
 * @return string UTF-8 の HTML。
 */
function node_blogcard_to_utf8( string $html, string $content_type ): string {
	$charset = '';

	if ( preg_match( '/charset\s*=\s*["\']?([\w-]+)/i', $content_type, $m ) ) {
		$charset = $m[1];
	}

	if ( '' === $charset ) {
		// <head> 相当の先頭だけを見る（本文中の文字列を誤検出しないため）。
		$head = substr( $html, 0, 4096 );
		if ( preg_match( '/<meta[^>]+charset\s*=\s*["\']?([\w-]+)/i', $head, $m ) ) {
			$charset = $m[1];
		}
	}

	$charset = strtoupper( str_replace( '_', '-', trim( $charset ) ) );
	$aliases = array(
		'SHIFT-JIS'   => 'SJIS',
		'SHIFTJIS'    => 'SJIS',
		'SJIS'        => 'SJIS',
		'X-SJIS'      => 'SJIS',
		'WINDOWS-31J' => 'SJIS-win',
		'CP932'       => 'SJIS-win',
		'MS932'       => 'SJIS-win',
		'EUC-JP'      => 'EUC-JP',
		'EUCJP'       => 'EUC-JP',
		'X-EUC-JP'    => 'EUC-JP',
		'ISO-2022-JP' => 'ISO-2022-JP',
	);

	if ( ! isset( $aliases[ $charset ] ) ) {
		return $html;
	}

	$converted = mb_convert_encoding( $html, 'UTF-8', $aliases[ $charset ] );

	return is_string( $converted ) ? $converted : $html;
}

/**
 * OGP 取得失敗を表すキャッシュ値。
 *
 * @return string
 */
function node_blogcard_fetch_failure_marker(): string {
	return 'node_ogp_fetch_failed';
}

/**
 * 取得失敗を短期間キャッシュして false を返す。
 *
 * @param string $transient_key キャッシュキー。
 * @return false
 */
function node_blogcard_cache_failure( string $transient_key ) {
	set_transient( $transient_key, node_blogcard_fetch_failure_marker(), 6 * HOUR_IN_SECONDS );
	return false;
}

/**
 * HTML からカードに必要なメタ情報を抽出する。
 *
 * og:*（property）→ og:*（name 表記ゆれ）→ twitter:* → JSON-LD → <title>/description の順で
 * 補完する。ゲームストアの商品ページは og を持たず JSON-LD（Product / VideoGame）だけを
 * 出力していることがあるため、単純な og 参照では題名も画像も取れない。
 *
 * @param DOMXPath $xpath 解析済みドキュメント。
 * @param string   $url   元 URL（相対画像 URL の絶対化に使用）。
 * @return array{title: string, description: string, image: string, site_name: string}
 */
function node_extract_page_meta( DOMXPath $xpath, string $url ): array {
	$meta_content = static function ( string $key ) use ( $xpath ): string {
		$value = $xpath->evaluate( sprintf( 'string(//meta[@property="%1$s"]/@content)', $key ) );
		if ( '' === trim( (string) $value ) ) {
			$value = $xpath->evaluate( sprintf( 'string(//meta[@name="%1$s"]/@content)', $key ) );
		}
		return trim( (string) $value );
	};

	$title       = $meta_content( 'og:title' );
	$description = $meta_content( 'og:description' );
	$image       = $meta_content( 'og:image' );
	$site_name   = $meta_content( 'og:site_name' );

	if ( '' === $title ) {
		$title = $meta_content( 'twitter:title' );
	}
	if ( '' === $description ) {
		$description = $meta_content( 'twitter:description' );
	}
	if ( '' === $image ) {
		$image = $meta_content( 'twitter:image' );
	}

	if ( '' === $title || '' === $image || '' === $description ) {
		$json_ld = node_extract_json_ld_product( $xpath );
		$title   = '' !== $title ? $title : $json_ld['title'];
		$image   = '' !== $image ? $image : $json_ld['image'];

		$description = '' !== $description ? $description : $json_ld['description'];
	}

	if ( '' === $title ) {
		$title = trim( (string) $xpath->evaluate( 'string(//title)' ) );
	}
	if ( '' === $description ) {
		$description = trim( (string) $xpath->evaluate( 'string(//meta[@name="description"]/@content)' ) );
	}

	// og:image に相対パスを置くサイトがあるため絶対 URL へ補正する。
	if ( '' !== $image && ! preg_match( '#^https?://#i', $image ) ) {
		$absolute = WP_Http::make_absolute_url( $image, $url );
		$image    = is_string( $absolute ) ? $absolute : '';
	}

	return array(
		'title'       => $title,
		'description' => $description,
		'image'       => $image,
		'site_name'   => $site_name,
	);
}

/**
 * JSON-LD から商品情報（name / description / image）を抽出する。
 *
 * @param DOMXPath $xpath 解析済みドキュメント。
 * @return array{title: string, description: string, image: string}
 */
function node_extract_json_ld_product( DOMXPath $xpath ): array {
	$result = array(
		'title'       => '',
		'description' => '',
		'image'       => '',
	);

	$nodes = $xpath->query( '//script[@type="application/ld+json"]' );
	if ( ! $nodes instanceof DOMNodeList ) {
		return $result;
	}

	$types = array( 'product', 'videogame', 'softwareapplication', 'game', 'mobileapplication' );

	foreach ( $nodes as $node ) {
		$decoded = json_decode( (string) $node->textContent, true );
		if ( ! is_array( $decoded ) ) {
			continue;
		}

		// トップレベルが配列・@graph 入り・単体オブジェクトのいずれの形式にも対応する。
		$entries = array();
		if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
			$entries = $decoded['@graph'];
		} elseif ( isset( $decoded[0] ) ) {
			$entries = $decoded;
		} else {
			$entries = array( $decoded );
		}

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_types = (array) ( $entry['@type'] ?? array() );
			$matched     = false;
			foreach ( $entry_types as $entry_type ) {
				if ( in_array( strtolower( (string) $entry_type ), $types, true ) ) {
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				continue;
			}

			$result['title']       = trim( (string) ( $entry['name'] ?? '' ) );
			$result['description'] = trim( (string) ( $entry['description'] ?? '' ) );
			$result['image']       = node_json_ld_image_url( $entry['image'] ?? '' );

			return $result;
		}
	}

	return $result;
}

/**
 * JSON-LD の image プロパティ（文字列 / 配列 / ImageObject）から URL を取り出す。
 *
 * @param mixed $image image プロパティの値。
 * @return string
 */
function node_json_ld_image_url( $image ): string {
	if ( is_string( $image ) ) {
		return trim( $image );
	}

	if ( is_array( $image ) ) {
		if ( isset( $image['url'] ) ) {
			return trim( (string) $image['url'] );
		}
		if ( isset( $image[0] ) ) {
			return node_json_ld_image_url( $image[0] );
		}
	}

	return '';
}

/**
 * Luminous Core ブランドカード用の画像URLを返す。
 *
 * ブランドオレンジ背景にロゴ＋ワードマークを合成済みの静的アセット。
 * `[blogcard]` ショートコードで明示的に luminous-core.net を指定した場合のみ使用する
 * （単独行URL・core/embedブロック等の自動検出では、実際のOGP画像をそのまま表示する —
 * 本番の記事ページは Node_SEO_Tools\Share\Image_Generator が記事タイトル入りのOGP画像を
 * 既に生成しているため、自動検出時にこちらで上書きする必要はない）。
 *
 * @return string
 */
function node_get_luminous_core_card_image(): string {
	return get_template_directory_uri() . '/assets/images/luminous-core-card-image.png';
}

/**
 * URL のホストが Luminous Core 自身のドメインかどうかを判定する。
 *
 * 同一ブランドへの外部リンク（本番ドメイン違い・dev環境からの参照等）では、
 * 記事ごとのOGP画像ではなく常にサイトロゴを表示する。
 *
 * @param string $host 判定対象ホスト。
 * @return bool
 */
function node_is_luminous_core_host( string $host ): bool {
	$host = strtolower( $host );
	return in_array( $host, array( 'luminous-core.net', 'www.luminous-core.net' ), true );
}

/**
 * ブログカード HTML マークアップを生成する。
 *
 * @param array<string, mixed> $ogp OGP 情報。
 * @param string               $url リンク先 URL。
 * @return string
 */
function node_blogcard_markup( array $ogp, string $url ): string {
	$url = esc_url_raw( $url );
	if ( empty( $url ) || empty( $ogp['title'] ) ) {
		return '';
	}

	$is_internal = ! empty( $ogp['is_internal'] );
	$modifier    = $is_internal ? 'm3-blogcard--internal' : 'm3-blogcard--external';
	if ( ! empty( $ogp['store'] ) ) {
		// ゲームストアの商品ページは、プラットフォーム色のアクセントとストア名バッジを付ける。
		$modifier .= ' m3-blogcard--store m3-blogcard--store-' . sanitize_html_class( (string) $ogp['store'] );
	}
	if ( ! empty( $ogp['is_brand'] ) ) {
		// ブランドバナー（1200x630 のロゴ＋ワードマーク一体画像）は通常のサムネイル枠だと
		// 中央クロップで文字が切れるため、専用の表示ルールを当てる。
		$modifier .= ' m3-blogcard--brand';
	}
	// OGP を取得できず URL から組み立てたカード。見た目は通常カードと同じで、
	// 呼び出し側が「本物のメタではない」と判別できるようにするための印。
	$is_fallback = ! empty( $ogp['fetch_failed'] );
	if ( $is_fallback ) {
		$modifier .= ' m3-blogcard--fallback';
	}
	$title       = (string) $ogp['title'];
	$share_title = wp_strip_all_tags( $title );
	$aria_label  = sprintf(
		/* translators: %s: linked page title */
		__( '%s へのリンク', 'node' ),
		$share_title
	);

	// NOTE: このカードは2経路で挿入される — `[blogcard]` ショートコード（the_content 優先度11、
	// wpautop の後）と oEmbed/autoembed フック（優先度8、wpautop の前）。後者は wpautop に
	// 破壊されるため node_blogcard_defer()/node_blogcard_hydrate()（優先度20）で遅延挿入する。
	// どちらの経路でも壊れないよう、末尾でタグ間空白を潰して1行 HTML にする。
	//
	// カード全体は「ストレッチリンク」でクリック可能にする（.m3-blogcard__overlay の ::after が
	// カード全面を覆う）。コピー/シェアのアクションボタンはそのオーバーレイより上（z-index）に置き、
	// <a> の中にインタラクティブ要素を入れ子にせずに独立してクリックできるようにする。
	ob_start();
	?>
	<div class="m3-blogcard-wrap">
		<div class="m3-blogcard m3-reveal <?php echo esc_attr( $modifier ); ?>">
			<div class="m3-blogcard__body">
				<?php if ( ! empty( $ogp['image'] ) ) : ?>
					<div class="m3-blogcard__image">
						<img src="<?php echo esc_url( $ogp['image'] ); ?>" alt="" loading="lazy" decoding="async">
					</div>
				<?php elseif ( $is_fallback && ! empty( $ogp['favicon'] ) ) : ?>
					<?php // アイキャッチを取得できない場合はサイトアイコンを置き、通常カードと同じ2カラム構成を保つ。 ?>
					<div class="m3-blogcard__image m3-blogcard__image--placeholder">
						<img src="<?php echo esc_url( $ogp['favicon'] ); ?>" alt="" loading="lazy" decoding="async" width="40" height="40">
					</div>
				<?php endif; ?>
				<div class="m3-blogcard__text">
					<a class="m3-blogcard__overlay" href="<?php echo esc_url( $url ); ?>"
					   aria-label="<?php echo esc_attr( $aria_label ); ?>"
					   <?php echo $is_internal ? '' : 'target="_blank" rel="noopener noreferrer"'; ?>>
						<span class="m3-blogcard__title"><?php echo esc_html( $title ); ?></span>
						<?php if ( ! $is_internal ) : ?>
							<span class="m3-blogcard__external-icon material-symbols-outlined" aria-hidden="true">open_in_new</span>
						<?php endif; ?>
					</a>
					<?php if ( ! empty( $ogp['description'] ) ) : ?>
						<p class="m3-blogcard__description"><?php echo esc_html( wp_trim_words( $ogp['description'], 40 ) ); ?></p>
					<?php endif; ?>
					<div class="m3-blogcard__footer">
						<span class="m3-blogcard__source">
							<?php if ( ! empty( $ogp['favicon'] ) ) : ?>
								<img src="<?php echo esc_url( $ogp['favicon'] ); ?>" class="m3-blogcard__favicon" alt="" loading="lazy" decoding="async" width="16" height="16">
							<?php endif; ?>
							<span class="m3-blogcard__sitename"><?php echo esc_html( $ogp['site_name'] ); ?></span>
							<?php if ( ! empty( $ogp['store'] ) ) : ?>
								<span class="m3-blogcard__store-badge"><?php esc_html_e( 'ストア', 'node' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="m3-blogcard__actions">
							<button type="button" class="m3-blogcard__action m3-blogcard__action--copy" data-url="<?php echo esc_url( $url ); ?>" data-share-title="<?php echo esc_attr( $share_title ); ?>" title="<?php esc_attr_e( 'リンクをコピー', 'node' ); ?>" aria-label="<?php esc_attr_e( 'リンクをコピー', 'node' ); ?>">
								<span class="material-symbols-outlined m3-blogcard__action-icon" aria-hidden="true">content_copy</span>
							</button>
							<button type="button" class="m3-blogcard__action m3-blogcard__action--share" data-url="<?php echo esc_url( $url ); ?>" data-share-title="<?php echo esc_attr( $share_title ); ?>" title="<?php esc_attr_e( 'シェア', 'node' ); ?>" aria-label="<?php esc_attr_e( 'シェアする', 'node' ); ?>">
								<span class="material-symbols-outlined m3-blogcard__action-icon" aria-hidden="true">share</span>
							</button>
						</span>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
	$html = (string) ob_get_clean();

	// wpautop は改行を <br>/<p> に変換するため、カード内の改行を完全に除去する。
	// (1) 改行＋前後インデントを単一スペースへ（属性間 `"\n\t class` → `" class` も含め安全）。
	// (2) タグ間の空白のみを潰す（`> <` → `><`）。テキストノード（`>Luminous Core<`）は
	//     空白以外を含むためマッチせず内容は保持される。結果として改行ゼロの1行HTMLになり、
	//     wpautop 前（autoembed）でも後（ショートコード）でも破壊されない。
	$html = (string) preg_replace( '/\s*\n\s*/', ' ', $html );
	$html = (string) preg_replace( '/>\s+</', '><', $html );

	return trim( $html );
}

/**
 * URL のパスセグメントを読める語句へ整形する。
 *
 * `v-bucks-a202300000011516` → `V Bucks` のように、末尾の ID／ハッシュを落として
 * 区切り文字を空白へ置き換える。日本語スラッグ（percent-encoded）は語形を壊さないよう
 * 大文字化せずそのまま返す。
 *
 * @param string $segment パスセグメント（デコード済み）。
 * @return string 整形結果（使えなければ空文字）。
 */
function node_blogcard_humanize_slug( string $segment ): string {
	$segment = rawurldecode( $segment );
	$segment = (string) preg_replace( '/\.(html?|php|aspx?|jsp)$/i', '', $segment );

	// 末尾の ID サフィックスを落とす。Epic の `-a202300000011516` / `-c-202300000001636`、
	// 一般的な `-123456`、CMS が付ける 16 進ハッシュが対象。ストア URL の商品 ID は
	// node_store_humanize_slug() 側の判定に任せるため、ここでは末尾の付属 ID だけを扱う。
	$segment = (string) preg_replace( '/[-_](?:[a-z][-_]?)?\d{6,}$/i', '', $segment );
	$segment = (string) preg_replace( '/[-_][0-9a-f]{8,}$/i', '', $segment );

	if ( '' === $segment || mb_strlen( $segment ) < 2 ) {
		return '';
	}

	// 語に分解できないハッシュ（`a1b2c3d4e5f6` 等）は題名にならない。
	// 呼び出し側はホスト名へ落ちる。
	if ( preg_match( '/^(?:[0-9a-f]{8,}|[a-z]*\d{6,}[a-z0-9]*)$/i', $segment ) ) {
		return '';
	}

	// 区切り文字の置換・ID 判定・大文字化はストアカードと同じ規則を使う
	// （inc/blogcard-store.php）。同梱ファイルが読み込まれていない構成でも
	// 動くよう、無い場合は同等の処理を行う。
	if ( function_exists( 'node_store_humanize_slug' ) ) {
		return node_store_humanize_slug( $segment );
	}

	$words = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[-_+]+/', ' ', $segment ) ) );
	if ( '' === $words || preg_match( '/^[0-9]+$/', $words ) ) {
		return '';
	}

	// 日本語を含む場合はそのまま（ucwords がマルチバイトを壊すため）。
	return preg_match( '/[^\x20-\x7E]/', $words ) ? $words : ucwords( $words );
}

/**
 * URL のパスから題名に使えるセグメントを抜き出す。
 *
 * @param string $url リンク先 URL。
 * @return list<string> 整形済みセグメント（前から順）。
 */
function node_blogcard_path_segments( string $url ): array {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	if ( '' === $path ) {
		return array();
	}

	$segments = array();
	foreach ( explode( '/', trim( rawurldecode( $path ), '/' ) ) as $raw ) {
		$raw = trim( $raw );
		// `index.html` はページ名を持たないので、その手前を末尾セグメントとして扱う。
		if ( '' === $raw || preg_match( '/^index\.(html?|php)$/i', $raw ) ) {
			continue;
		}

		$segments[] = node_blogcard_humanize_slug( $raw );
	}

	return $segments;
}

/**
 * URL のスラッグから題名を組み立てる。
 *
 * Cloudflare 等のボット防御で本文を取得できない URL でも、素のリンクへ落とさず
 * カードとして体裁を保つために使う。
 *
 * @param string $url リンク先 URL。
 * @return string 題名（組み立てられなければ空文字）。
 */
function node_blogcard_title_from_url( string $url ): string {
	$segments = node_blogcard_path_segments( $url );

	// 末尾セグメントだけを見る。ここが ID やハッシュなら、手前へ遡らず空文字を返す。
	// 遡ると `/item/software/D70010000000964` が「Software」のような分類名になり、
	// ページの題名として誤解を招くため。
	return empty( $segments ) ? '' : (string) end( $segments );
}

/**
 * URL の中間パスをパンくずとして組み立てる。
 *
 * @param string $url リンク先 URL。
 * @return string 例: `Fortnite Battle Royale › Billing And Payment`（無ければ空文字）。
 */
function node_blogcard_breadcrumb_from_url( string $url ): string {
	$segments = node_blogcard_path_segments( $url );
	array_pop( $segments );

	$segments = array_values( array_filter( $segments, static fn( string $s ): bool => '' !== $s ) );

	return empty( $segments ) ? '' : implode( ' › ', $segments );
}

/**
 * OGP を取得できない一般 URL でも、本文上はブログカードとして表示するための情報。
 *
 * 題名は URL のスラッグから組み立てる。生の URL は説明欄に入れない（長い URL が
 * そのまま流し込まれると、他のカードと並んだときに見た目が崩れるため）。
 *
 * @param string $url リンク先 URL。
 * @return array<string, mixed>
 */
function node_blogcard_generic_fallback_ogp( string $url ): array {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	$site = '' !== $host ? (string) preg_replace( '/^www\./', '', $host ) : __( 'リンク先', 'node' );

	$title = node_blogcard_title_from_url( $url );
	if ( '' === $title ) {
		$title = $site;
	}

	return array(
		'title'        => $title,
		'description'  => node_blogcard_breadcrumb_from_url( $url ),
		'image'        => '',
		'favicon'      => '' !== $host ? 'https://www.google.com/s2/favicons?domain=' . rawurlencode( $host ) . '&sz=64' : '',
		'site_name'    => $site,
		'is_internal'  => false,
		'fetch_failed' => true,
	);
}

/**
 * OGP 取得に失敗した URL のフォールバックを生成する。
 */
function node_blogcard_fallback_markup( string $url ): string {
	$url = esc_url_raw( $url );
	if ( empty( $url ) ) {
		return '';
	}

	if ( node_is_internal_url( $url ) ) {
		return '<span class="m3-blogcard__fallback m3-blogcard__fallback--missing">' . esc_html( $url ) . '</span>';
	}

	return '<a class="m3-blogcard__fallback" href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
}

/**
 * ブログカード HTML を生成する。
 *
 * @param string                $url           リンク先 URL。
 * @param bool                  $brand_override luminous-core.net の場合にブランド画像（ロゴ＋ワードマーク）を
 *                                強制表示するか。`[blogcard]` ショートコードからのみ true を渡す。
 * @param array<string, string> $overrides     題名・説明・画像の手動指定（`[blogcard]` の属性）。
 *                                空でない値だけが取得結果を上書きする。
 * @return string
 */
function node_render_blogcard( string $url, bool $brand_override = false, array $overrides = array() ): string {
	$url = esc_url_raw( $url );
	if ( empty( $url ) ) {
		return '';
	}

	$store = node_store_provider( $url );
	$ogp   = node_get_ogp_data( $url );

	if ( ! empty( $store ) ) {
		// ストア側のボット防御でメタが取れなくても、URL から組み立てた情報でカードにする
		// （素のリンクへ落とすと、記事内で他のカードと並んだときに見た目が崩れるため）。
		// 商品ページではなくストアのトップへ転送された場合も、サイト共通のタイトルを
		// そのまま載せてしまわないようフォールバックへ回す。
		if ( ! is_array( $ogp ) || empty( $ogp['title'] )
			|| ! node_store_response_is_product_page( $url, (string) ( $ogp['final_url'] ?? '' ) ) ) {
			$ogp = node_store_fallback_ogp( $url, $store );
		} else {
			$ogp['store']     = $store['slug'];
			$ogp['site_name'] = $store['name'];
		}
	}

	// 手動指定は取得結果より優先する。ボット防御で題名を取れないストアページでも、
	// 著者が `[blogcard url="..." title="..."]` と書けば正しい題名で表示できる。
	$ogp = node_blogcard_apply_overrides( $ogp, $overrides, $url );

	// 題名が無いカードは node_blogcard_markup() が空文字を返すため、取得失敗（false）と
	// 「配列は返ったが題名が空」（ストアのフォールバック等）を同じ経路で救う。
	if ( ! is_array( $ogp ) || '' === trim( (string) ( $ogp['title'] ?? '' ) ) ) {
		if ( node_is_internal_url( $url ) ) {
			// 記事が実在しないならカードを捏造せず「見つかりません」表示に落とす。
			// 実在するのに題名が空（無題の記事）の場合は WordPress 標準の埋め込みに任せる。
			return node_internal_url_to_postid( $url ) ? '' : node_blogcard_fallback_markup( $url );
		}

		// ストア商品ページは node_store_fallback_ogp() が店名を題名に入れるため、
		// ここへは到達しない（1.2.6 と同じくストアカードとして表示される）。
		// 外部 URL は Cloudflare 等のボット防御で取得できないだけなので、URL から
		// 組み立てた情報でカードにする（素のリンクや空出力へ落とさない）。
		$ogp = node_blogcard_generic_fallback_ogp( $url );
	}

	// Amazon アフィリエイト ID
	$amazon_id = get_option( 'luminous_nexus_amazon_id' );
	if ( $amazon_id && str_contains( $url, 'amazon.co.jp' ) && ! str_contains( $url, 'tag=' ) ) {
		$separator = str_contains( $url, '?' ) ? '&' : '?';
		$url      .= "{$separator}tag={$amazon_id}";
	}

	if ( $brand_override && node_is_luminous_core_host( (string) parse_url( $url, PHP_URL_HOST ) ) ) {
		$ogp['image']    = node_get_luminous_core_card_image();
		$ogp['is_brand'] = true;
	}

	return node_blogcard_markup( $ogp, $url );
}

/**
 * `[blogcard]` 属性による手動指定を OGP 情報へ反映する。
 *
 * 取得に完全に失敗していても、題名が指定されていればカードを組み立てる。
 *
 * @param array<string, mixed>|false $ogp       取得済み OGP 情報（失敗時は false）。
 * @param array<string, string>      $overrides 手動指定（title / description / image）。
 * @param string                     $url       対象 URL。
 * @return array<string, mixed>|false
 */
function node_blogcard_apply_overrides( $ogp, array $overrides, string $url ) {
	$title       = trim( (string) ( $overrides['title'] ?? '' ) );
	$description = trim( (string) ( $overrides['description'] ?? '' ) );
	$image       = trim( (string) ( $overrides['image'] ?? '' ) );

	// サムネイルを出すかどうか（既定は「取得できたら出す」）。
	$hide_image = array_key_exists( 'show_image', $overrides ) && ! $overrides['show_image'];

	if ( '' === $title && '' === $description && '' === $image && ! $hide_image ) {
		return $ogp;
	}

	if ( ! is_array( $ogp ) ) {
		if ( '' === $title ) {
			return $ogp;
		}

		$host = (string) parse_url( $url, PHP_URL_HOST );
		$ogp  = array(
			'title'       => '',
			'description' => '',
			'image'       => '',
			'favicon'     => 'https://www.google.com/s2/favicons?domain=' . rawurlencode( $host ) . '&sz=64',
			'site_name'   => $host,
			'is_internal' => false,
		);

		$store = node_store_provider( $url );
		if ( ! empty( $store ) ) {
			$ogp['site_name'] = $store['name'];
			$ogp['store']     = $store['slug'];
		}
	}

	if ( '' !== $title ) {
		$ogp['title'] = $title;
	}
	if ( '' !== $description ) {
		$ogp['description'] = $description;
	}
	if ( '' !== $image ) {
		$ogp['image'] = esc_url_raw( $image );
	}

	// 非表示指定は最後に適用する（手動指定の画像より優先）。
	if ( $hide_image ) {
		$ogp['image'] = '';
	}

	return $ogp;
}

/**
 * oEmbed のネイティブ埋め込みを維持するプロバイダーかどうかを判定する。
 *
 * @param string $url  oEmbed 元 URL。
 * @param object $data oEmbed レスポンスデータ。
 * @return bool
 */
function node_is_excluded_oembed_provider( string $url, object $data ): bool {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

	foreach ( array( 'twitter.com', 'x.com', 'youtube.com', 'youtu.be' ) as $domain ) {
		if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
			return true;
		}
	}

	$provider_name = strtolower( (string) ( $data->provider_name ?? '' ) );
	return str_contains( $provider_name, 'twitter' ) || str_contains( $provider_name, 'youtube' );
}

/**
 * 生成済みカード HTML を後段（wpautop の後）で差し込むためのプレースホルダに置き換える。
 *
 * autoembed 由来（oembed_dataparse / embed_maybe_make_link）のカードは `the_content` 優先度8、
 * つまり wpautop(優先度10) より前に挿入される。カードは <a> がブロック <div> を内包する構造の
 * ため、wpautop に通すと <p>/<br> が差し込まれて崩れる。そこでこの段階では base64 化した
 * プレースホルダ <div> のみを返し、実カード HTML は node_blogcard_hydrate() が wpautop 後
 * （優先度20）に復元する。
 *
 * 重要: カード HTML をトークンではなく base64 で「プレースホルダ自身」に埋め込む。WordPress は
 * oEmbed 結果（＝このプレースホルダ）をキャッシュするため、リクエスト固有トークン方式だと
 * キャッシュヒット時にトークンが失効し、hydrate が空に置換してカードが消えてしまう。自己完結型に
 * することで、キャッシュ済みプレースホルダでも別リクエストで確実に復元できる。base64 の文字集合は
 * [A-Za-z0-9+/=] のみで、改行も山括弧も無いため wpautop / texturize に破壊されない。
 *
 * @param string $html カード HTML。
 * @return string プレースホルダ HTML。
 */
function node_blogcard_defer( string $html ): string {
	if ( '' === $html ) {
		return '';
	}

	return '<div class="node-blogcard-slot">' . base64_encode( $html ) . '</div>';
}

/**
 * wpautop 後にプレースホルダを実カード HTML へ復元する。
 *
 * @param string $content 投稿本文。
 * @return string
 */
function node_blogcard_hydrate( string $content ): string {
	if ( ! str_contains( $content, 'node-blogcard-slot' ) ) {
		return $content;
	}

	return (string) preg_replace_callback(
		'#<div class="node-blogcard-slot">([A-Za-z0-9+/=]+)</div>#',
		static function ( array $m ): string {
			$decoded = base64_decode( $m[1], true );
			return false === $decoded ? '' : $decoded;
		},
		$content
	);
}

/**
 * X(Twitter) / Google マップ / YouTube 等、URL 貼り付け時に「カード」ではなく
 * 「埋め込み」を表示すべきプロバイダーの HTML を返す。該当しなければ空文字。
 *
 * WordPress 標準の oEmbed が機能しないケース（X は API 廃止で oEmbed 不可、x.com と
 * Google マップは未登録プロバイダー）を、テーマ側で明示的に埋め込みへ変換する。
 *
 * @param string $url 貼り付けられた URL。
 * @return string 埋め込み HTML（該当しなければ空文字）。
 */
function node_special_embed( string $url ): string {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	$host = (string) preg_replace( '/^www\./', '', $host );
	$path = (string) parse_url( $url, PHP_URL_PATH );

	// --- X (Twitter): 公式 blockquote 埋め込み（oEmbed API 不要で常に動作） ---
	if ( in_array( $host, array( 'twitter.com', 'x.com', 'mobile.twitter.com' ), true )
		&& preg_match( '#/status(?:es)?/\d+#', $path ) ) {
		node_mark_twitter_widgets();
		return '<div class="node-embed node-embed--x"><blockquote class="twitter-tweet" data-dnt="true"><a href="' . esc_url( $url ) . '"></a></blockquote></div>';
	}

	// --- YouTube: youtu.be / shorts 等、標準 oEmbed が拾えない形式のフォールバック ---
	$youtube_id = node_youtube_video_id( $url, $host, $path );
	if ( '' !== $youtube_id ) {
		return '<div class="node-embed node-embed--video"><iframe src="https://www.youtube.com/embed/' . rawurlencode( $youtube_id ) . '" title="YouTube video player" frameborder="0" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>';
	}

	// --- Google マップ: output=embed の iframe（API キー不要） ---
	$map_embed = node_maps_embed_html( $url );
	if ( '' !== $map_embed ) {
		return $map_embed;
	}

	return '';
}

/**
 * URL が Google マップ系（本体・短縮）かどうかを判定する。
 *
 * @param string $url 判定対象 URL。
 * @return bool
 */
function node_is_google_maps_url( string $url ): bool {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	$host = (string) preg_replace( '/^www\./', '', $host );
	$path = (string) parse_url( $url, PHP_URL_PATH );

	if ( in_array( $host, array( 'maps.app.goo.gl', 'goo.gl' ), true ) ) {
		return true;
	}

	// maps.google.* はサブドメイン自体がマップなのでパス不問。google.com は /maps 配下のみ。
	if ( str_starts_with( $host, 'maps.google.' ) ) {
		return true;
	}

	return str_ends_with( $host, 'google.com' ) && str_contains( $path, '/maps' );
}

/**
 * Google マップ URL をレスポンシブ埋め込み iframe HTML に変換する。
 *
 * @param string $url Google マップ URL。
 * @return string 埋め込み HTML（変換できなければ空文字）。
 */
function node_maps_embed_html( string $url ): string {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	$host = (string) preg_replace( '/^www\./', '', $host );
	$path = (string) parse_url( $url, PHP_URL_PATH );

	$map_src = node_google_maps_embed_src( $url, $host, $path );
	if ( '' === $map_src ) {
		return '';
	}

	return '<div class="node-embed node-embed--map"><iframe src="' . esc_url( $map_src ) . '" title="Google Maps" frameborder="0" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div>';
}

/**
 * URL から YouTube 動画 ID を抽出する（youtu.be / watch / shorts / embed 対応）。
 *
 * @param string $url  元 URL。
 * @param string $host www を除いたホスト。
 * @param string $path パス。
 * @return string 動画 ID（該当しなければ空文字）。
 */
function node_youtube_video_id( string $url, string $host, string $path ): string {
	if ( 'youtu.be' === $host ) {
		return (string) preg_replace( '#^/([\w-]{6,})\b.*#', '$1', $path );
	}

	if ( in_array( $host, array( 'youtube.com', 'm.youtube.com' ), true ) ) {
		if ( preg_match( '#^/(?:shorts|embed|v)/([\w-]{6,})#', $path, $m ) ) {
			return $m[1];
		}
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		if ( ! empty( $params['v'] ) && preg_match( '/^[\w-]{6,}$/', (string) $params['v'] ) ) {
			return (string) $params['v'];
		}
	}

	return '';
}

/**
 * Google マップ URL から output=embed 版の iframe src を組み立てる。
 *
 * 短縮 URL（maps.app.goo.gl / goo.gl）はリダイレクト解決してから座標・地名を抽出する。
 * `.../maps?q=...&output=embed` はブラウザ側で frameable な `/maps/embed?pb=...` へ
 * 301 転送されるため、そのまま iframe に載せて表示できる。
 *
 * @param string $url  元 URL。
 * @param string $host www を除いたホスト。
 * @param string $path パス。
 * @return string 埋め込み用 src（該当しなければ空文字）。
 */
function node_google_maps_embed_src( string $url, string $host, string $path ): string {
	$is_short = in_array( $host, array( 'maps.app.goo.gl', 'goo.gl' ), true );
	$is_maps  = ( str_ends_with( $host, 'google.com' ) || str_starts_with( $host, 'maps.google.' ) )
		&& ( str_starts_with( $path, '/maps' ) || '' !== (string) parse_url( $url, PHP_URL_QUERY ) );

	if ( ! $is_short && ! $is_maps ) {
		return '';
	}

	if ( $is_short ) {
		$resolved = node_resolve_redirect( $url );
		if ( '' === $resolved ) {
			return '';
		}
		$url  = $resolved;
		$path = (string) parse_url( $url, PHP_URL_PATH );
	}

	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );

	$query = '';
	$zoom  = '15';

	// /place/NAME/@lat,lng,zoom
	if ( preg_match( '#/place/([^/@]+)#', $path, $m ) ) {
		$query = trim( rawurldecode( str_replace( '+', ' ', $m[1] ) ) );
	}

	// @lat,lng,zoomz（座標・ズーム）
	if ( preg_match( '#@(-?\d+\.\d+),(-?\d+\.\d+)(?:,(\d+(?:\.\d+)?)z)?#', $url, $mm ) ) {
		if ( '' === $query ) {
			$query = $mm[1] . ',' . $mm[2];
		}
		if ( ! empty( $mm[3] ) ) {
			$zoom = (string) (int) $mm[3];
		}
	}

	if ( '' === $query && ! empty( $params['q'] ) ) {
		$query = (string) $params['q'];
	}
	if ( '' === $query && ! empty( $params['ll'] ) ) {
		$query = (string) $params['ll'];
	}

	if ( '' === $query ) {
		return '';
	}

	return add_query_arg(
		array(
			'q'      => rawurlencode( $query ),
			'z'      => $zoom,
			'hl'     => 'ja',
			'output' => 'embed',
		),
		'https://maps.google.com/maps'
	);
}

/**
 * URL のリダイレクト先（Location ヘッダ）を1週間キャッシュ付きで解決する。
 *
 * @param string $url 短縮 URL 等。
 * @return string 解決先 URL（取得できなければ空文字）。
 */
function node_resolve_redirect( string $url ): string {
	$key    = 'node_redir_' . md5( $url );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return (string) $cached;
	}

	$response = wp_safe_remote_head(
		$url,
		array(
			'timeout'     => 8,
			'redirection' => 0,
			// sslverify は既定(true)を維持する（絶対原則: 検証無効化の新規追加は禁止）
		)
	);

	$location = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'location' );
	set_transient( $key, $location, WEEK_IN_SECONDS );

	return $location;
}

/**
 * X(Twitter) の widgets.js をこのリクエストのフッターで一度だけ読み込むフラグを立てる。
 */
function node_mark_twitter_widgets(): void {
	$GLOBALS['node_needs_twitter_widgets'] = true;
}

/**
 * ツイート埋め込みがある場合のみ widgets.js をフッターへ出力する。
 */
function node_print_twitter_widgets(): void {
	if ( ! empty( $GLOBALS['node_needs_twitter_widgets'] ) ) {
		echo '<script async src="https://platform.x.com/widgets.js" charset="utf-8"></script>' . "\n";
	}
}

/**
 * oEmbed 成功時の HTML を Node ブログカードへ差し替える。
 *
 * X(Twitter) と YouTube は WordPress 標準のネイティブ埋め込みを維持する。
 *
 * @param string $return WordPress 標準が生成した HTML。
 * @param object $data   oEmbed レスポンスデータ。
 * @param string $url    oEmbed 元 URL。
 * @return string
 */
function node_oembed_dataparse( string $return, object $data, string $url ): string {
	if ( node_is_excluded_oembed_provider( $url, $data ) ) {
		return $return;
	}

	if ( node_is_internal_url( $url ) ) {
		$ogp = node_get_ogp_data( $url );
		if ( ! $ogp ) {
			return $return;
		}

		$card = node_blogcard_markup( $ogp, $url );
		return '' !== $card ? node_blogcard_defer( $card ) : $return;
	}

	$host = (string) parse_url( $url, PHP_URL_HOST );
	$ogp  = array(
		'title'       => (string) ( $data->title ?? $url ),
		'description' => '',
		'image'       => (string) ( $data->thumbnail_url ?? '' ),
		'favicon'     => 'https://www.google.com/s2/favicons?domain=' . rawurlencode( $host ) . '&sz=64',
		'site_name'   => (string) ( $data->provider_name ?? $host ),
		'is_internal' => false,
	);

	if ( '' === $ogp['title'] ) {
		$ogp['title'] = $url;
	}

	if ( '' === $ogp['site_name'] ) {
		$ogp['site_name'] = $host;
	}

	// oEmbed 経由でもストアカードの見た目を揃える。
	$store = node_store_provider( $url );
	if ( ! empty( $store ) ) {
		$ogp['store']     = $store['slug'];
		$ogp['site_name'] = $store['name'];
	}

	$card = node_blogcard_markup( $ogp, $url );
	return '' !== $card ? node_blogcard_defer( $card ) : $return;
}

/**
 * oEmbed 失敗時の通常リンクを Node ブログカードへ差し替える。
 *
 * @param string $output WordPress 標準が生成したリンク HTML。
 * @param string $url    元 URL。
 * @return string
 */
function node_embed_maybe_make_link( string $output, string $url ): string {
	// X / Google マップ / YouTube 等は「カード」ではなく「埋め込み」を優先。
	$embed = node_special_embed( $url );
	if ( '' !== $embed ) {
		return node_blogcard_defer( $embed );
	}

	$card = node_render_blogcard( $url );
	return '' !== $card ? node_blogcard_defer( $card ) : $output;
}

/**
 * ブログカードショートコード
 *
 * @param array<string, string>|string $atts ショートコード属性。
 * @return string
 */
function node_blogcard_shortcode( $atts ): string {
	$atts = shortcode_atts(
		array(
			'url'         => '',
			'title'       => '',
			'description' => '',
			'image'       => '',
			'show_image'  => 'true',
		),
		$atts,
		'blogcard'
	);

	return node_render_blogcard(
		(string) $atts['url'],
		true,
		array(
			'title'       => (string) $atts['title'],
			'description' => (string) $atts['description'],
			'image'       => (string) $atts['image'],
			// `show_image="false"` / `"no"` / `"0"` でサムネイルを消す。
			'show_image'  => ! in_array( strtolower( trim( (string) $atts['show_image'] ) ), array( 'false', 'no', '0', 'off' ), true ),
		)
	);
}

/**
 * 本文中の URL 単独行をブログカードへ自動変換
 *
 * @param string $content 投稿本文。
 * @return string
 */
function node_auto_blogcard( string $content ): string {
	return $content;
}

/**
 * Luminous Nexus 互換エイリアス
 */
if ( ! function_exists( 'luminous_nexus_get_ogp_data' ) ) {
	function luminous_nexus_get_ogp_data( $url ) {
		return node_get_ogp_data( (string) $url );
	}
}

if ( ! function_exists( 'luminous_nexus_blogcard_shortcode' ) ) {
	function luminous_nexus_blogcard_shortcode( $atts ) {
		return node_blogcard_shortcode( $atts );
	}
}

/**
 * Google マップを oEmbed プロバイダーとして登録する。
 *
 * Google マップは公式 oEmbed を持たないため、ブロックエディタでは URL を貼っても
 * 埋め込みブロックにできない。テーマ内に自前の oEmbed エンドポイント
 * （/wp-json/node/v1/maps-oembed）を用意し、それを指すプロバイダーを登録することで、
 * エディタでもフロントでも「埋め込みブロック」として扱えるようにする
 * （X・YouTube は WordPress 標準の oEmbed / ディスカバリで既にブロック化できる）。
 */
function node_register_map_oembed_provider(): void {
	$endpoint = home_url( '/wp-json/node/v1/maps-oembed' );
	wp_oembed_add_provider( '#https?://(www\.)?google\.[a-z.]+/maps/.*#i', $endpoint, true );
	wp_oembed_add_provider( '#https?://maps\.google\.[a-z.]+/.*#i', $endpoint, true );
	wp_oembed_add_provider( '#https?://maps\.app\.goo\.gl/.*#i', $endpoint, true );
	wp_oembed_add_provider( '#https?://goo\.gl/maps/.*#i', $endpoint, true );
}

/**
 * 自前 Google マップ oEmbed エンドポイントを登録する。
 */
function node_register_maps_oembed_route(): void {
	register_rest_route(
		'node/v1',
		'/maps-oembed',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => 'node_maps_oembed_response',
			'args'                => array(
				'url' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
}

/**
 * Google マップ oEmbed エンドポイントのレスポンス（rich タイプ）。
 *
 * @param WP_REST_Request $request REST リクエスト。
 * @return array<string, mixed>|WP_Error
 */
function node_maps_oembed_response( WP_REST_Request $request ) {
	$url = esc_url_raw( (string) $request->get_param( 'url' ) );

	if ( ! node_is_google_maps_url( $url ) ) {
		return new WP_Error( 'node_not_maps', 'Not a Google Maps URL', array( 'status' => 404 ) );
	}

	$html = node_maps_embed_html( $url );
	if ( '' === $html ) {
		return new WP_Error( 'node_no_embed', 'Could not build a Google Maps embed', array( 'status' => 404 ) );
	}

	return array(
		'version'       => '1.0',
		'type'          => 'rich',
		'provider_name' => 'Google Maps',
		'provider_url'  => 'https://www.google.com/maps',
		'width'         => 800,
		'height'        => 450,
		'html'          => $html,
	);
}

/**
 * フロント側の oEmbed 取得（get_html）を Google マップだけ短絡して自前 iframe を返す。
 *
 * プロバイダー経由のループバック取得を避けつつ、WordPress の oEmbed サニタイズで
 * iframe が改変されるのも防ぐ。エディタ側（get_data）は登録プロバイダーを使う。
 *
 * @param string|null          $result 既存の結果。
 * @param string               $url    対象 URL。
 * @param array<string, mixed> $args   引数。
 * @return string|null
 */
function node_pre_oembed_maps_result( $result, $url, $args ) {
	unset( $args );
	if ( null === $result && node_is_google_maps_url( (string) $url ) ) {
		$html = node_maps_embed_html( (string) $url );
		if ( '' !== $html ) {
			return $html;
		}
	}
	return $result;
}

/**
 * 自サイト URL の oEmbed 取得を短絡し、内部カードを直接返す。
 *
 * 自サイト埋め込みは WordPress の自己 oEmbed（ループバック HTTP 要求）に依存し、要求の
 * 成否・タイミングでカードが出たり出なかったりする。ここで url_to_postid ベースに短絡して
 * ループバックを完全に排除し、内部カードを毎回確実に生成する。node_blogcard_defer() で
 * base64 プレースホルダ化しているため、キャッシュ後の別リクエストでも復元できる。
 *
 * @param string|null          $result 既存の結果。
 * @param string               $url    対象 URL。
 * @param array<string, mixed> $args   引数。
 * @return string|null
 */
function node_pre_oembed_internal_result( $result, $url, $args ) {
	unset( $args );
	if ( null !== $result || ! node_is_internal_url( (string) $url ) || ! node_internal_url_to_postid( (string) $url ) ) {
		return $result;
	}

	$card = node_render_blogcard( (string) $url );
	if ( '' === $card || str_contains( $card, 'm3-blogcard__fallback' ) ) {
		return $result;
	}

	return node_blogcard_defer( $card );
}

/**
 * ブロックエディタ用: `node/blogcard` ブロックのフロント側レンダリング。
 *
 * @param array<string, mixed> $attributes ブロック属性。
 * @return string
 */
function node_render_blogcard_block( array $attributes ): string {
	$url = esc_url_raw( (string) ( $attributes['url'] ?? '' ) );
	if ( '' === $url ) {
		return '';
	}

	return node_render_blogcard(
		$url,
		false,
		array(
			'title'       => (string) ( $attributes['title'] ?? '' ),
			'description' => (string) ( $attributes['description'] ?? '' ),
			'image'       => (string) ( $attributes['image'] ?? '' ),
			'show_image'  => ! array_key_exists( 'showImage', $attributes ) || (bool) $attributes['showImage'],
		)
	);
}

/**
 * `node/blogcard` ブロックを登録する。
 *
 * エディタ上でカードのプレビューを出すためのブロック。URL 単独行の自動変換
 * （oEmbed 経路）はそのまま残るため、既存記事の書き方は変わらない。
 */
function node_register_blogcard_block(): void {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	register_block_type(
		'node/blogcard',
		array(
			'api_version'     => 2,
			'attributes'      => array(
				'url'         => array(
					'type'    => 'string',
					'default' => '',
				),
				'title'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'description' => array(
					'type'    => 'string',
					'default' => '',
				),
				'image'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'showImage'   => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'render_callback' => 'node_render_blogcard_block',
		)
	);
}

/**
 * エディタ用スクリプトを読み込む。
 */
function node_enqueue_blogcard_editor_assets(): void {
	$relative = '/assets/js/blogcard-editor.js';
	$path     = get_template_directory() . $relative;

	if ( ! file_exists( $path ) ) {
		return;
	}

	wp_enqueue_script(
		'node-blogcard-editor',
		get_template_directory_uri() . $relative,
		array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-hooks' ),
		(string) filemtime( $path ),
		true
	);
}

/**
 * エディタからカードのプレビュー HTML を取得する REST ルートを登録する。
 */
function node_register_blogcard_preview_route(): void {
	register_rest_route(
		'node/v1',
		'/blogcard-preview',
		array(
			'methods'             => 'GET',
			'permission_callback' => static function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'callback'            => 'node_blogcard_preview_response',
			'args'                => array(
				'url'         => array(
					'required' => true,
					'type'     => 'string',
				),
				'title'       => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'image'       => array( 'type' => 'string' ),
				'show_image'  => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
		)
	);
}

/**
 * カードプレビューのレスポンス。
 *
 * フロントと同じ node_render_blogcard() を通すため、エディタで見えるものが
 * そのまま公開後の表示になる（取得結果のキャッシュも共有する）。
 *
 * @param WP_REST_Request $request REST リクエスト。
 * @return array<string, mixed>|WP_Error
 */
function node_blogcard_preview_response( WP_REST_Request $request ) {
	$url = esc_url_raw( (string) $request->get_param( 'url' ) );
	if ( '' === $url ) {
		return new WP_Error( 'node_blogcard_bad_url', 'URL が空です', array( 'status' => 400 ) );
	}

	$html = node_render_blogcard(
		$url,
		false,
		array(
			'title'       => (string) $request->get_param( 'title' ),
			'description' => (string) $request->get_param( 'description' ),
			'image'       => (string) $request->get_param( 'image' ),
			'show_image'  => (bool) $request->get_param( 'show_image' ),
		)
	);

	if ( '' === $html ) {
		return new WP_Error( 'node_blogcard_no_card', 'カードを生成できませんでした', array( 'status' => 404 ) );
	}

	$store = node_store_provider( $url );

	return array(
		'html'      => $html,
		'store'     => (string) ( $store['slug'] ?? '' ),
		// URL から組み立てたカード（m3-blogcard--fallback）も「取得できていない」印として返す。
		// エディタ側はこれを見て、著者に題名の手入力を促す。
		'fallback'  => str_contains( $html, 'm3-blogcard--fallback' ) || str_contains( $html, 'm3-blogcard__fallback' ),
	);
}

add_shortcode( 'blogcard', 'node_blogcard_shortcode' );
add_action( 'init', 'node_register_blogcard_block' );
add_action( 'enqueue_block_editor_assets', 'node_enqueue_blogcard_editor_assets' );
add_action( 'rest_api_init', 'node_register_blogcard_preview_route' );
add_action( 'init', 'node_register_map_oembed_provider' );
add_action( 'rest_api_init', 'node_register_maps_oembed_route' );
add_filter( 'pre_oembed_result', 'node_pre_oembed_internal_result', 10, 3 );
add_filter( 'pre_oembed_result', 'node_pre_oembed_maps_result', 10, 3 );
add_filter( 'oembed_dataparse', 'node_oembed_dataparse', 10, 3 );
add_filter( 'embed_maybe_make_link', 'node_embed_maybe_make_link', 10, 2 );
add_filter( 'the_content', 'node_auto_blogcard', 11 );
// wpautop(10) の後にプレースホルダを実カード/埋め込みへ復元する。autoembed(8) 由来の
// 破壊を回避するため、優先度は必ず 10 より大きくすること。
// 21固定: lightbox(20)より必ず後に走らせ、カード内画像がlightboxリンクで包まれるのを防ぐ
// （従来はrequire順でのみ保たれていた脆い不変条件の明示化。STRUCTURAL-REVIEW-1.2 F-8）
add_filter( 'the_content', 'node_blogcard_hydrate', 21 );
add_action( 'wp_footer', 'node_print_twitter_widgets' );
