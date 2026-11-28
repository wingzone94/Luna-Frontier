<?php
/**
 * 記事本文中の公式URLを検証根拠として取得する
 *
 * Google Search グラウンディングが使えない（無料枠を使い切った・無効設定）場合でも、
 * 記事が参照している一次情報そのものを読めば裏取りできることが多い。
 * ただし取得は「公開ページを必要最小限だけ読む」に限定する:
 * - robots.txt で拒否されているパスは取得しない
 * - ログイン・ペイウォール・CAPTCHA の回避は一切しない（200 以外は捨てる）
 * - 取得件数と本文長に上限を設ける
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_AI_Fact_Check_Sources {

	/** 取得を有効にするか（サイト設定）。 */
	public const ENABLED_OPTION = 'node_ai_fc_fetch_sources';

	/**
	 * 一次情報（公式）とみなすホストの手がかり。
	 *
	 * ここに載らないホストは official=false として扱う（不明を公式扱いしない）。
	 *
	 * @return array<int, string>
	 */
	public static function official_host_patterns(): array {
		return (array) apply_filters(
			'node_ai_fc_official_host_patterns',
			array(
				'/(^|\.)go\.jp$/',
				'/(^|\.)lg\.jp$/',
				'/(^|\.)ac\.jp$/',
				'/^(support|docs|developer|developers|help|press|newsroom|about|blog)\./',
				'/(^|\.)nintendo\.(co\.jp|com)$/',
				'/(^|\.)apple\.com$/',
				'/(^|\.)microsoft\.com$/',
				'/(^|\.)google\.com$/',
				'/(^|\.)sony\.(jp|com|co\.jp)$/',
				'/(^|\.)samsung\.com$/',
				'/(^|\.)anthropic\.com$/',
				'/(^|\.)openai\.com$/',
			)
		);
	}

	/**
	 * 一次情報として扱わないホスト（まとめ・SNS・百科事典・ECなど）。
	 *
	 * @return array<int, string>
	 */
	public static function non_primary_hosts(): array {
		return (array) apply_filters(
			'node_ai_fc_non_primary_hosts',
			array(
				'x.com',
				'twitter.com',
				'facebook.com',
				'instagram.com',
				'youtube.com',
				'youtu.be',
				'wikipedia.org',
				'note.com',
				'qiita.com',
				'zenn.dev',
				'amazon.co.jp',
				'amazon.com',
				'rakuten.co.jp',
				'hatena.ne.jp',
				'hatenablog.com',
				'ameblo.jp',
				'livedoor.jp',
				'blogspot.com',
				'wordpress.com',
				'medium.com',
				'fc2.com',
			)
		);
	}

	/** 管理者が追加した公式ドメイン（1行1件）。 */
	public const OFFICIAL_HOSTS_OPTION = 'node_ai_fc_official_hosts';

	/**
	 * 最初から公式（一次情報）として扱うドメイン。
	 *
	 * 設定を書かなくても主要メーカー・プラットフォームの公式サイトを一次情報として
	 * 扱えるようにするためのプリセット。サブドメインは自動で同一扱いになる
	 * （support.apple.com → apple.com）。除外リスト（まとめ・SNS・EC）が優先される。
	 *
	 * @return array<int, string>
	 */
	public static function preset_official_hosts(): array {
		return (array) apply_filters(
			'node_ai_fc_preset_official_hosts',
			array(
				// ゲーム
				'nintendo.co.jp',
				'nintendo.com',
				'playstation.com',
				'sie.com',
				'xbox.com',
				'sega.jp',
				'capcom.co.jp',
				'square-enix.com',
				'jp.square-enix.com',
				'bandainamcoent.co.jp',
				'konami.com',
				'koeitecmo.co.jp',
				'atlus.co.jp',
				'valvesoftware.com',
				'steampowered.com',
				'epicgames.com',
				'cyberpunk.net',
				'cdprojektred.com',
				// プラットフォーム・AI
				'google.com',
				'blog.google',
				'android.com',
				'chromium.org',
				'apple.com',
				'microsoft.com',
				'openai.com',
				'anthropic.com',
				'meta.com',
				'adobe.com',
				'mozilla.org',
				'wordpress.org',
				'php.net',
				// ハードウェア
				'sony.jp',
				'sony.com',
				'samsung.com',
				'intel.com',
				'amd.com',
				'nvidia.com',
				'qualcomm.com',
				'asus.com',
				'lenovo.com',
				'dell.com',
				'hp.com',
				'sharp.co.jp',
				'panasonic.com',
				'canon.jp',
				'nikon.com',
				'anker.com',
				// 通信（日本）
				'docomo.ne.jp',
				'au.com',
				'kddi.com',
				'softbank.jp',
				'ymobile.jp',
				'ahamo.com',
			)
		);
	}

	/**
	 * 管理画面から登録された公式ドメイン。
	 *
	 * @return array<int, string>
	 */
	public static function configured_official_hosts(): array {
		$stored = (string) get_option( self::OFFICIAL_HOSTS_OPTION, '' );
		$hosts  = array();

		foreach ( preg_split( '/[\r\n,]+/', $stored ) ?: array() as $line ) {
			$host = strtolower( trim( (string) $line ) );
			$host = (string) preg_replace( '#^https?://#', '', $host );
			$host = trim( explode( '/', $host )[0] );

			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * 登録可能なドメインの単位（example.co.jp / example.com）を返す。
	 *
	 * サブドメインの違いで公式判定が外れないようにするために使う。
	 */
	public static function registrable_domain( string $host ): string {
		$host  = strtolower( trim( $host ) );
		$parts = array_values( array_filter( explode( '.', $host ) ) );
		$count = count( $parts );

		if ( $count < 2 ) {
			return $host;
		}

		// co.jp / ne.jp / com.au のような2階層TLD
		$second_level = array( 'co', 'ne', 'or', 'ac', 'go', 'lg', 'ed', 'gr', 'com', 'net', 'org', 'gov', 'edu' );

		if ( $count >= 3 && in_array( $parts[ $count - 2 ], $second_level, true ) && 2 === strlen( $parts[ $count - 1 ] ) ) {
			return implode( '.', array_slice( $parts, -3 ) );
		}

		return implode( '.', array_slice( $parts, -2 ) );
	}

	/**
	 * 取得しても検証根拠にならないホスト（動画・SNS・ログイン必須サービス）。
	 *
	 * 実測: YouTube 埋め込みのある記事では、動画URLが取得枠（既定3件）を占有し、
	 * 記事の主題である公式サイトが押し出されていた。動画ページは本文が
	 * ほとんど取れないため、根拠としても使えない。
	 *
	 * @return array<int, string>
	 */
	public static function skip_hosts(): array {
		return (array) apply_filters(
			'node_ai_fc_skip_hosts',
			array(
				'youtube.com',
				'youtu.be',
				'youtube-nocookie.com',
				'x.com',
				'twitter.com',
				'instagram.com',
				'facebook.com',
				'tiktok.com',
				'threads.net',
				'nicovideo.jp',
				'twitch.tv',
				'open.spotify.com',
				'music.apple.com',
			)
		);
	}

	/**
	 * そのホストは取得対象から外すか。
	 */
	public static function is_skipped_host( string $host ): bool {
		$host = strtolower( trim( $host ) );

		foreach ( self::skip_hosts() as $skip ) {
			$skip = strtolower( (string) $skip );
			if ( $host === $skip || str_ends_with( $host, '.' . $skip ) ) {
				return true;
			}
		}

		return false;
	}

	public static function is_enabled(): bool {
		$enabled = '0' !== (string) get_option( self::ENABLED_OPTION, '1' );

		return (bool) apply_filters( 'node_ai_fc_fetch_sources_enabled', $enabled );
	}

	/**
	 * ホストが公式（一次情報）らしいか。
	 */
	public static function is_official_host( string $host ): bool {
		$host = strtolower( trim( $host ) );
		if ( '' === $host ) {
			return false;
		}

		foreach ( self::non_primary_hosts() as $blocked ) {
			if ( $host === $blocked || str_ends_with( $host, '.' . $blocked ) ) {
				return false;
			}
		}

		$domain = self::registrable_domain( $host );

		// プリセット + 管理画面で追加されたドメイン
		$known = array_merge( self::preset_official_hosts(), self::configured_official_hosts() );

		foreach ( $known as $configured ) {
			$configured = strtolower( trim( (string) $configured ) );

			if ( '' === $configured ) {
				continue;
			}

			if ( $host === $configured || $domain === self::registrable_domain( $configured ) ) {
				return true;
			}
		}

		foreach ( self::official_host_patterns() as $pattern ) {
			if ( preg_match( (string) $pattern, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 取得したページ自身の署名（og:site_name / canonical / JSON-LD）を読む。
	 *
	 * ホスト名のリストだけでは未知のメーカー公式サイトを拾えないため、
	 * 「そのページを出しているのが誰か」をページ側の宣言から判定する。
	 *
	 * @return array{site_name: string, canonical_host: string, org_host: string, is_news: bool}
	 */
	public static function extract_site_signals( string $html ): array {
		$signals = array(
			'site_name'      => '',
			'canonical_host' => '',
			'org_host'       => '',
			'is_news'        => false,
		);

		if ( preg_match( '#<meta[^>]+property=["\']og:site_name["\'][^>]*content=["\']([^"\']+)#i', $html, $m )
			|| preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]*property=["\']og:site_name["\']#i', $html, $m ) ) {
			$signals['site_name'] = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}

		if ( preg_match( '#<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)#i', $html, $m ) ) {
			$signals['canonical_host'] = strtolower( (string) wp_parse_url( $m[1], PHP_URL_HOST ) );
		}

		// 報道機関は一次資料ではないため、公式扱いしない（優先順位では別枠）。
		// NewsArticle は企業の公式ブログ（blog.google 等）でも使われるため判定に使わない。
		// 発行主体が報道機関だと明示している場合のみ報道扱いにする
		if ( preg_match( '#"@type"\s*:\s*"NewsMediaOrganization"#i', $html ) ) {
			$signals['is_news'] = true;
		}

		// 組織自身のサイトかどうかは JSON-LD の Organization.url で分かることが多い。
		if ( preg_match_all( '#"@type"\s*:\s*"(Organization|Corporation|GovernmentOrganization|EducationalOrganization)"(.{0,400}?)"url"\s*:\s*"([^"]+)"#is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$host = strtolower( (string) wp_parse_url( $match[3], PHP_URL_HOST ) );
				if ( '' !== $host ) {
					$signals['org_host'] = $host;
					break;
				}
			}
		}

		return $signals;
	}

	/**
	 * ページ本体の署名も見たうえで一次情報（公式）かを判定する。
	 *
	 * @param string $host    取得したホスト。
	 * @param string $html    取得した HTML。
	 * @return array{official: bool, source_type: string}
	 */
	public static function judge_source( string $host, string $html ): array {
		$host   = strtolower( trim( $host ) );
		$domain = self::registrable_domain( $host );

		foreach ( self::non_primary_hosts() as $blocked ) {
			if ( $host === $blocked || str_ends_with( $host, '.' . $blocked ) ) {
				return array(
					'official'    => false,
					'source_type' => 'non_primary',
				);
			}
		}

		// 明示登録・既知パターンは無条件で公式。
		if ( self::is_official_host( $host ) ) {
			return array(
				'official'    => true,
				'source_type' => 'official_page',
			);
		}

		$signals = self::extract_site_signals( $html );

		if ( $signals['is_news'] ) {
			return array(
				'official'    => false,
				'source_type' => 'news',
			);
		}

		// 「自分のサイトだ」と名乗っているだけでは一次情報とみなさない。
		// 報道メディアも自社ドメインで Organization を宣言し og:site_name がドメイン名と一致するため、
		// これを公式扱いにすると報道記事が一次資料に格上げされ、リスク判定まで押し上げてしまう
		// （実測: automaton-media.com が任天堂の一次情報として扱われた）。
		// 一次情報と認めるのは、明示登録・既知パターン・公式サイト情報から引いたホストだけにする。
		$self_published = ( '' !== $signals['org_host'] && self::registrable_domain( $signals['org_host'] ) === $domain )
			|| ( '' !== $signals['site_name'] && self::site_name_matches_domain( $signals['site_name'], $domain ) );

		return array(
			'official'    => false,
			'source_type' => $self_published ? 'self_published' : 'article_link',
		);
	}

	/**
	 * サイト名がドメインの識別部分と一致するか（Nintendo → nintendo.co.jp）。
	 */
	public static function site_name_matches_domain( string $site_name, string $domain ): bool {
		$label = strtolower( explode( '.', $domain )[0] ?? '' );
		if ( '' === $label || strlen( $label ) < 4 ) {
			return false;
		}

		$name = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', $site_name ) );

		return '' !== $name && ( $name === $label || ( strlen( $name ) >= 4 && false !== strpos( $label, $name ) ) );
	}

	/**
	 * 記事本文から検証に使えそうなURLを抽出する（公式らしいものを優先）。
	 *
	 * @param string $content 記事の生本文（HTML 可）。
	 * @return array<int, string>
	 */
	public static function extract_urls( string $content ): array {
		if ( ! preg_match_all( '#https?://[^\s"\'<>()\[\]]+#i', $content, $matches ) ) {
			return array();
		}

		$self_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$urls      = array();

		foreach ( $matches[0] as $raw ) {
			$url = esc_url_raw( rtrim( (string) $raw, '.,、。)' ) );
			if ( '' === $url ) {
				continue;
			}

			if ( function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $url ) ) {
				continue;
			}

			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host || $host === $self_host ) {
				continue;
			}

			// 画像・動画・アーカイブなど本文でないものは読まない。
			if ( preg_match( '/\.(png|jpe?g|gif|webp|svg|mp4|mp3|zip|pdf)(\?|$)/i', $url ) ) {
				continue;
			}

			// 動画・SNS は本文が取れず、取得枠だけを消費するため候補にしない。
			if ( self::is_skipped_host( $host ) ) {
				continue;
			}

			$urls[ $url ] = true;
		}

		$urls = array_keys( $urls );

		usort(
			$urls,
			static function ( string $a, string $b ): int {
				$oa = self::is_official_host( (string) wp_parse_url( $a, PHP_URL_HOST ) ) ? 0 : 1;
				$ob = self::is_official_host( (string) wp_parse_url( $b, PHP_URL_HOST ) ) ? 0 : 1;

				return $oa <=> $ob;
			}
		);

		return $urls;
	}

	/**
	 * robots.txt で許可されているパスか（User-agent: * の Disallow のみを見る簡易判定）。
	 */
	public static function is_allowed_by_robots( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$origin    = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
		$cache_key = 'node_ai_fc_robots_' . md5( $origin );
		$rules     = get_transient( $cache_key );

		if ( ! is_array( $rules ) ) {
			$response = wp_safe_remote_get(
				$origin . '/robots.txt',
				array(
					'timeout'             => 8,
					'redirection'         => 2,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => 64 * KB_IN_BYTES,
					'user-agent'          => self::user_agent(),
				)
			);

			$rules = array();

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$rules = self::parse_robots( (string) wp_remote_retrieve_body( $response ) );
			}

			set_transient( $cache_key, $rules, 12 * HOUR_IN_SECONDS );
		}

		$path = (string) ( $parts['path'] ?? '/' );
		$path = '' === $path ? '/' : $path;

		foreach ( $rules as $disallow ) {
			if ( '' !== $disallow && 0 === strpos( $path, (string) $disallow ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * robots.txt から User-agent: * の Disallow パスを取り出す。
	 *
	 * @return array<int, string>
	 */
	public static function parse_robots( string $body ): array {
		$applies   = false;
		$disallows = array();

		foreach ( preg_split( '/\R/', $body ) ?: array() as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', (string) $line ) ?? '' );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^user-agent\s*:\s*(.+)$/i', $line, $m ) ) {
				$applies = ( '*' === trim( $m[1] ) );
				continue;
			}

			if ( $applies && preg_match( '/^disallow\s*:\s*(.*)$/i', $line, $m ) ) {
				$path = trim( $m[1] );
				if ( '' !== $path ) {
					$disallows[] = $path;
				}
			}
		}

		return $disallows;
	}

	private static function user_agent(): string {
		return 'Mozilla/5.0 (compatible; NodeFactCheck/1.0; +' . home_url( '/' ) . ')';
	}

	/**
	 * 記事に含まれる公式URLを取得し、検証根拠として使えるテキストにして返す。
	 *
	 * @param string $content 記事の生本文。
	 * @param int    $limit   取得する最大件数。
	 * @return array<int, array<string, mixed>>
	 */
	public static function collect( string $content, int $limit = 0 ): array {
		if ( ! self::is_enabled() ) {
			return array();
		}

		return self::fetch_urls( self::extract_urls( $content ), $limit );
	}

	/**
	 * 指定した URL を取得して検証根拠にする（記事内リンク・自動発見の共通処理）。
	 *
	 * @param array<int, string> $urls  取得候補。
	 * @param int                $limit 取得件数の上限。
	 * @return array<int, array<string, mixed>>
	 */
	public static function fetch_urls( array $urls, int $limit = 0, array $known_official_hosts = array() ): array {
		$limit    = $limit > 0 ? $limit : (int) apply_filters( 'node_ai_fc_source_limit', 3 );
		$max_char = (int) apply_filters( 'node_ai_fc_source_max_chars', 1200 );
		$sources  = array();

		foreach ( $urls as $url ) {
			if ( count( $sources ) >= $limit ) {
				break;
			}

			if ( self::is_skipped_host( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
				continue;
			}

			if ( ! self::is_allowed_by_robots( $url ) ) {
				continue;
			}

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 12,
					'redirection'         => 3,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => 2 * MB_IN_BYTES,
					'user-agent'          => self::user_agent(),
				)
			);

			// ログインやペイウォールの回避はしない。200 以外はそのまま諦める。
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$body = (string) wp_remote_retrieve_body( $response );

			// 上限を超えるページは捨てるのではなく切り詰める。
			// 400KB 固定で捨てていたため、公式ブログ（blog.google 等）が黙って根拠から
			// 落ちていた。本文抽出は前方の要素で足りる
			$max_bytes = (int) apply_filters( 'node_ai_fc_source_max_bytes', 3000000 );
			if ( strlen( $body ) > $max_bytes ) {
				$body = substr( $body, 0, $max_bytes );
			}

			$text = self::extract_text( $body );
			if ( '' === $text ) {
				continue;
			}

			$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$verdict = self::judge_source( $host, $body );

			// 公式サイト情報（Wikidata の「公式ウェブサイト」）から引いたホストは一次情報として扱う
			if ( ! $verdict['official'] && in_array( $host, array_map( 'strtolower', $known_official_hosts ), true ) ) {
				$verdict = array(
					'official'    => true,
					'source_type' => 'official_page',
				);
			}

			$sources[] = array(
				'url'         => $url,
				'title'       => self::extract_title( $body ),
				'host'        => $host,
				'official'    => $verdict['official'],
				'source_type' => $verdict['source_type'],
				'checked_at'  => current_time( 'mysql' ),
				'text'        => mb_substr( $text, 0, $max_char ),
			);
		}

		return $sources;
	}

	/**
	 * HTML から本文らしいテキストを抜き出す。
	 */
	public static function extract_text( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|nav|header|footer|aside|form|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	public static function extract_title( string $html ): string {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			return sanitize_text_field( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}

		return '';
	}
}
