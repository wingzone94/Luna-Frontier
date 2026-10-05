<?php
/**
 * Node Connect X（Twitter）自動投稿。
 *
 * 記事の新規公開イベント（post_published、予約公開含む）を購読し、
 * テンプレート置換した投稿文を X API v2（POST /2/tweets）へ OAuth 1.0a 署名付きで送信する。
 *
 * 旧実装 inc/scheduler.php の node_post_to_x() からの移管。オプション名
 * （node_x_api_key ほか）と投稿済みメタ（_node_x_posted）は互換のまま引き継ぐ。
 *
 * 設計原則:
 * - 記事1件につき自動投稿は生涯1回（_node_x_posted で永続的に抑止。再公開でも再投稿しない）
 * - 記事側から除外可能（_node_connect_x_skip、メタボックスのチェック）
 * - 送信は cron で非同期化し、公開処理に波及させない
 * - HTTP 4xx（認証・重複・権限）は再送しない。5xx・通信エラーのみ最大2回再送（計3試行）
 *
 * @package Node_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Node_Connect_X_Poster {

	public const CRON_HOOK      = 'node_connect_deliver_x';
	public const OPTION_ENABLED = 'node_connect_x_enabled';

	// 旧 Luminous Settings から引き継ぐオプション名（値の互換維持）。
	public const OPTION_API_KEY             = 'node_x_api_key';
	public const OPTION_API_SECRET          = 'node_x_api_secret';
	public const OPTION_ACCESS_TOKEN        = 'node_x_access_token';
	public const OPTION_ACCESS_TOKEN_SECRET = 'node_x_access_token_secret';
	public const OPTION_TEMPLATE            = 'node_x_post_template';
	public const OPTION_TEMPLATES           = 'node_x_post_templates';

	public const OPTION_SCREEN_NAME = 'node_connect_x_screen_name';

	public const POSTED_META = '_node_x_posted';
	public const SKIP_META   = '_node_connect_x_skip';
	public const TEXT_META   = '_node_connect_x_text';
	public const TEMPLATE_META = '_node_connect_x_template';

	public const MAX_ATTEMPTS = 3;
	public const TIMEOUT      = 10;

	/**
	 * Gutenberg（REST保存 → メタボックス保存の2段階リクエスト）でも除外チェックの
	 * 保存が届くよう、公開検知から送信まで最低この秒数を空ける。
	 */
	public const DELIVERY_DELAY = 30;

	public const DEFAULT_TEMPLATE = "ブログ記事を投稿しました\n「{{title}}」\n{{url}}\n{{tags}}";

	/**
	 * Xの投稿上限（重み付き280 = 日本語約140文字）。CJK等は1文字=重み2、ASCIIは1、URLは常に23。
	 */
	public const X_WEIGHTED_LIMIT = 280;
	public const CUSTOM_TEXT_LIMIT = 140;
	private const URL_WEIGHT      = 23;

	/**
	 * {{summary}} を切り詰める際に {{tags}} 用へ取り置く重み（タグ2〜3個ぶん）。
	 */
	private const TAGS_RESERVE = 40;

	private const RETRY_DELAYS = [ 60, 300 ];

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function register(): void {
		add_action( 'init', [ self::class, 'register_post_meta_fields' ] );
		add_action( 'node_connect_event', [ $this, 'handle' ], 10, 2 );
		add_action( self::CRON_HOOK, [ self::class, 'deliver' ], 10, 2 );
	}

	/**
	 * ブロックエディタの文書設定ペインから保存する投稿別設定をRESTへ公開する。
	 */
	public static function register_post_meta_fields(): void {
		$auth_callback = static function ( bool $allowed, string $meta_key, int $post_id ): bool {
			return current_user_can( 'edit_post', $post_id );
		};

		register_post_meta(
			'post',
			self::TEXT_META,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => [ self::class, 'sanitize_post_text' ],
				'auth_callback'     => $auth_callback,
			]
		);

		register_post_meta(
			'post',
			self::SKIP_META,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => static fn( mixed $value ): string => '1' === (string) $value ? '1' : '',
				'auth_callback'     => $auth_callback,
			]
		);

		register_post_meta(
			'post',
			self::TEMPLATE_META,
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => 'default',
				'show_in_rest'      => true,
				'sanitize_callback' => [ self::class, 'sanitize_template_key' ],
				'auth_callback'     => $auth_callback,
			]
		);
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * @return array{api_key: string, api_secret: string, access_token: string, access_token_secret: string}|null
	 */
	public static function get_credentials(): ?array {
		$credentials = [
			'api_key'             => (string) get_option( self::OPTION_API_KEY, '' ),
			'api_secret'          => (string) get_option( self::OPTION_API_SECRET, '' ),
			'access_token'        => (string) get_option( self::OPTION_ACCESS_TOKEN, '' ),
			'access_token_secret' => (string) get_option( self::OPTION_ACCESS_TOKEN_SECRET, '' ),
		];
		return in_array( '', $credentials, true ) ? null : $credentials;
	}

	public static function get_template( string $key = 'default' ): string {
		$templates = self::get_templates();
		if ( ! isset( $templates[ $key ] ) ) {
			$key = (string) array_key_first( $templates );
		}

		return $templates[ $key ]['content'];
	}

	/**
	 * このブログへ登録されたX投稿文言テンプレートを返す。
	 * 新形式が未保存なら、従来の単一テンプレートを1件目として引き継ぐ。
	 *
	 * @return array<string, array{name: string, content: string}>
	 */
	public static function get_templates(): array {
		$templates = self::sanitize_templates( get_option( self::OPTION_TEMPLATES, [] ) );
		if ( ! empty( $templates ) ) {
			return $templates;
		}

		$legacy_template = (string) get_option( self::OPTION_TEMPLATE, '' );

		return [
			'default' => [
				'name'    => '標準テンプレート',
				'content' => '' !== trim( $legacy_template ) ? $legacy_template : self::DEFAULT_TEMPLATE,
			],
		];
	}

	/**
	 * @return array<string, array{name: string, content: string}>
	 */
	public static function sanitize_templates( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$result = [];
		foreach ( array_values( $value ) as $index => $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}
			$content = trim( sanitize_textarea_field( (string) ( $template['content'] ?? '' ) ) );
			if ( '' === $content ) {
				continue;
			}

			$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) ( $template['id'] ?? '' ) );
			if ( '' === $id || isset( $result[ $id ] ) ) {
				$id = 'template-' . ( $index + 1 );
				while ( isset( $result[ $id ] ) ) {
					$id .= '-copy';
				}
			}
			$name = trim( sanitize_text_field( (string) ( $template['name'] ?? '' ) ) );
			$result[ $id ] = [
				'name'    => '' !== $name ? $name : 'テンプレート ' . ( $index + 1 ),
				'content' => $content,
			];
		}

		return $result;
	}

	public static function sanitize_template_key( mixed $value ): string {
		$key       = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $value );
		$templates = self::get_templates();

		return isset( $templates[ $key ] ) ? $key : (string) array_key_first( $templates );
	}

	/**
	 * イベントバス購読。新規公開のみ・自動投稿は記事1件につき1回だけキューに載せる。
	 *
	 * @param array<string, mixed> $payload
	 */
	public function handle( string $event, array $payload = [] ): void {
		if ( Node_Connect_Event_Bus::EVENT_POST_PUBLISHED !== $event ) {
			return;
		}
		if ( ! self::is_enabled() || null === self::get_credentials() ) {
			return;
		}

		$post_id = (int) ( $payload['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return;
		}
		if ( '' !== (string) get_post_meta( $post_id, self::POSTED_META, true ) ) {
			return;
		}
		if ( wp_next_scheduled( self::CRON_HOOK, [ $post_id, 1 ] ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::DELIVERY_DELAY, self::CRON_HOOK, [ $post_id, 1 ] );
	}

	/**
	 * cron コールバック。送信時点の記事状態・メタで最終判定してから投稿する。
	 */
	public static function deliver( int $post_id, int $attempt ): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		$credentials = self::get_credentials();
		if ( null === $credentials ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		// 送信直前の最終チェック（除外チェック・二重投稿）。
		if ( '1' === (string) get_post_meta( $post_id, self::SKIP_META, true ) ) {
			return;
		}
		if ( '' !== (string) get_post_meta( $post_id, self::POSTED_META, true ) ) {
			return;
		}

		$text   = self::get_post_text( $post );
		$result = self::send_tweet( $credentials, $text );

		Node_Connect_Delivery_Log::add(
			[
				'event'   => 'x_post',
				'label'   => 'X 自動投稿: ' . get_the_title( $post ),
				'status'  => $result['status'],
				'ok'      => $result['ok'],
				'attempt' => $attempt,
			]
		);

		if ( $result['ok'] ) {
			update_post_meta( $post_id, self::POSTED_META, time() );
			return;
		}

		if ( $result['retryable'] && $attempt < self::MAX_ATTEMPTS ) {
			$delay = self::RETRY_DELAYS[ $attempt - 1 ] ?? 300;
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK, [ $post_id, $attempt + 1 ] );
		}
	}

	/**
	 * テンプレートのプレースホルダを記事データで置換する。
	 * {{summary}} は AI要約（_node_ai_summary）を優先し、無ければ抜粋。
	 *
	 * 投稿全体が X_WEIGHTED_LIMIT に収まるよう、{{summary}} は残り予算で切り詰め、
	 * {{tags}}（記事タグのハッシュタグ列）は収まる分だけ末尾に付ける。
	 */
	public static function render_template( string $template, WP_Post $post ): string {
		$categories    = get_the_category( $post->ID );
		$category_name = ! empty( $categories ) ? $categories[0]->name : 'Luna Frontier';

		$text = str_replace(
			[ '{{title}}', '{{url}}', '{{category}}' ],
			[ get_the_title( $post ), (string) get_permalink( $post ), $category_name ],
			$template
		);

		$has_tags = str_contains( $text, '{{tags}}' );

		if ( str_contains( $text, '{{summary}}' ) ) {
			$summary = (string) get_post_meta( $post->ID, '_node_ai_summary', true );
			if ( '' === trim( $summary ) ) {
				$summary = get_the_excerpt( $post );
			}
			$summary = wp_strip_all_tags( $summary );

			$frame  = str_replace( [ '{{summary}}', '{{tags}}' ], '', $text );
			$budget = self::X_WEIGHTED_LIMIT - self::weighted_length( $frame ) - ( $has_tags ? self::TAGS_RESERVE : 0 );
			$text   = str_replace( '{{summary}}', self::trim_to_weight( $summary, max( 0, $budget ) ), $text );
		}

		if ( $has_tags ) {
			$base   = rtrim( str_replace( '{{tags}}', '', $text ) );
			$budget = self::X_WEIGHTED_LIMIT - self::weighted_length( $base );
			$text   = str_replace( '{{tags}}', self::build_hashtags( $post, $budget ), $text );
		}

		return rtrim( $text );
	}

	/**
	 * 記事別投稿文を返す。未設定なら従来の全体テンプレートへフォールバックする。
	 */
	public static function get_post_text( WP_Post $post ): string {
		$custom_text = (string) get_post_meta( $post->ID, self::TEXT_META, true );
		if ( '' !== trim( $custom_text ) ) {
			return self::sanitize_post_text( $custom_text );
		}

		$template_key = self::sanitize_template_key( get_post_meta( $post->ID, self::TEMPLATE_META, true ) );

		return self::render_template( self::get_template( $template_key ), $post );
	}

	/**
	 * 投稿別文面の改行を保ったまま無害化し、140文字以内へ収める。
	 */
	public static function sanitize_post_text( mixed $value ): string {
		$text = trim( sanitize_textarea_field( (string) $value ) );

		if ( mb_strlen( $text ) <= self::CUSTOM_TEXT_LIMIT ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, self::CUSTOM_TEXT_LIMIT - 1 ) ) . '…';
	}

	/**
	 * 記事タグを「#タグ1 #タグ2 …」形式にし、重み予算に収まる分だけ返す。
	 * タグ名の空白と # は除去する（ハッシュタグとして成立させるため）。
	 */
	public static function build_hashtags( WP_Post $post, int $budget ): string {
		$tags = get_the_tags( $post->ID );
		if ( ! is_array( $tags ) ) {
			return '';
		}

		$result = '';
		foreach ( $tags as $tag ) {
			if ( ! $tag instanceof WP_Term ) {
				continue;
			}
			$name = preg_replace( '/[\s#]+/u', '', $tag->name );
			if ( '' === $name ) {
				continue;
			}
			$piece = ( '' === $result ? '' : ' ' ) . '#' . $name;
			if ( self::weighted_length( $result . $piece ) > $budget ) {
				continue; // 収まらないタグは落とす（後続の短いタグは拾う）。
			}
			$result .= $piece;
		}

		return $result;
	}

	/**
	 * Xの文字数カウント近似。URLは長さによらず23、ASCIIは1、それ以外（CJK・絵文字等）は2。
	 */
	public static function weighted_length( string $text ): int {
		$text = (string) preg_replace( '#https?://\S+#', str_repeat( 'x', self::URL_WEIGHT ), $text );

		$length = 0;
		foreach ( mb_str_split( $text ) as $char ) {
			$length += strlen( $char ) <= 1 ? 1 : 2;
		}
		return $length;
	}

	/**
	 * 重み予算に収まるよう末尾を「…」付きで切り詰める。収まっていればそのまま返す。
	 */
	public static function trim_to_weight( string $text, int $budget ): string {
		if ( self::weighted_length( $text ) <= $budget ) {
			return $text;
		}

		$ellipsis_weight = 2;
		if ( $budget < $ellipsis_weight ) {
			return '';
		}

		$target_weight = $budget - $ellipsis_weight;
		$length          = 0;
		$result          = '';
		$parts           = preg_split( '#(https?://\S+)#u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );

		foreach ( (array) $parts as $part ) {
			if ( preg_match( '#^https?://\S+$#u', $part ) ) {
				if ( $length + self::URL_WEIGHT > $target_weight ) {
					break;
				}
				$result .= $part;
				$length += self::URL_WEIGHT;
				continue;
			}

			foreach ( mb_str_split( $part ) as $char ) {
				$char_weight = strlen( $char ) <= 1 ? 1 : 2;
				if ( $length + $char_weight > $target_weight ) {
					break 2;
				}
				$result .= $char;
				$length += $char_weight;
			}
		}

		return rtrim( $result ) . '…';
	}

	/**
	 * OAuth 1.0a Authorization ヘッダーを構築する（HMAC-SHA1）。
	 *
	 * JSONボディのリクエストでは署名ベース文字列に含めるのは OAuth パラメータのみ。
	 * GET のクエリパラメータは $query_params で署名に含める。
	 * $extra_oauth で oauth_callback / oauth_verifier 等を追加できる（3-legged フロー用）。
	 * access_token が空の場合は oauth_token を含めない（request_token 取得時）。
	 *
	 * @param array{api_key: string, api_secret: string, access_token: string, access_token_secret: string} $credentials
	 * @param array<string, string> $query_params
	 * @param array<string, string> $extra_oauth
	 */
	public static function build_oauth_header( array $credentials, string $method, string $url, array $query_params = [], array $extra_oauth = [] ): string {
		$oauth = [
			'oauth_consumer_key'     => $credentials['api_key'],
			'oauth_nonce'            => wp_generate_password( 32, false ),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => (string) time(),
			'oauth_token'            => $credentials['access_token'],
			'oauth_version'          => '1.0',
		] + $extra_oauth;
		if ( '' === $credentials['access_token'] ) {
			unset( $oauth['oauth_token'] );
		}

		$base_params = array_merge( $oauth, $query_params );
		ksort( $base_params );

		$base_string = strtoupper( $method )
			. '&' . rawurlencode( $url )
			. '&' . rawurlencode( http_build_query( $base_params, '', '&', PHP_QUERY_RFC3986 ) );

		$signing_key              = rawurlencode( $credentials['api_secret'] ) . '&' . rawurlencode( $credentials['access_token_secret'] );
		$oauth['oauth_signature'] = base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );

		$parts = [];
		foreach ( $oauth as $key => $value ) {
			$parts[] = rawurlencode( $key ) . '="' . rawurlencode( $value ) . '"';
		}

		return 'OAuth ' . implode( ', ', $parts );
	}

	/**
	 * POST /2/tweets を実行する。
	 *
	 * @param array{api_key: string, api_secret: string, access_token: string, access_token_secret: string} $credentials
	 * @return array{ok: bool, status: string, retryable: bool}
	 */
	private static function send_tweet( array $credentials, string $text ): array {
		$url      = 'https://api.twitter.com/2/tweets';
		$response = wp_remote_post(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => self::build_oauth_header( $credentials, 'POST', $url ),
					'Content-Type'  => 'application/json; charset=utf-8',
				],
				'body'    => wp_json_encode( [ 'text' => $text ] ),
			]
		);

		return self::classify_response( $response );
	}

	/**
	 * 設定画面の認証テスト。GET /2/users/me で資格情報を検証する（投稿はしない）。
	 *
	 * @return array{ok: bool, status: string}
	 */
	public static function test_credentials(): array {
		$credentials = self::get_credentials();
		if ( null === $credentials ) {
			return [
				'ok'     => false,
				'status' => '認証情報が未設定です',
			];
		}

		$url      = 'https://api.twitter.com/2/users/me';
		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [ 'Authorization' => self::build_oauth_header( $credentials, 'GET', $url ) ],
			]
		);

		$result = self::classify_response( $response );
		if ( $result['ok'] ) {
			$body     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$username = $body['data']['username'] ?? null;
			if ( is_string( $username ) && '' !== $username ) {
				$result['status'] .= ' @' . $username;
			}
		}

		return [
			'ok'     => $result['ok'],
			'status' => $result['status'],
		];
	}

	/**
	 * App単位の認証情報（API Key/Secret）だけを返す。「Xと連携」フローの前提条件。
	 *
	 * @return array{api_key: string, api_secret: string, access_token: string, access_token_secret: string}|null
	 */
	public static function get_app_credentials(): ?array {
		$api_key    = (string) get_option( self::OPTION_API_KEY, '' );
		$api_secret = (string) get_option( self::OPTION_API_SECRET, '' );
		if ( '' === $api_key || '' === $api_secret ) {
			return null;
		}
		return [
			'api_key'             => $api_key,
			'api_secret'          => $api_secret,
			'access_token'        => '',
			'access_token_secret' => '',
		];
	}

	/**
	 * 「Xと連携」開始。request_token を取得し、Xの認可画面URLを返す（3-legged OAuth 1.0a）。
	 *
	 * @return string|WP_Error 認可画面URL。
	 */
	public static function start_authorization( string $callback_url ) {
		$credentials = self::get_app_credentials();
		if ( null === $credentials ) {
			return new WP_Error( 'node_connect_x', 'API Key / API Key Secret を先に保存してください。' );
		}

		$url      = 'https://api.twitter.com/oauth/request_token';
		$response = wp_remote_post(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => self::build_oauth_header( $credentials, 'POST', $url, [], [ 'oauth_callback' => $callback_url ] ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'node_connect_x', 'request_token の取得に失敗しました（HTTP ' . wp_remote_retrieve_response_code( $response ) . '）。X Developer Portal でアプリの「User authentication settings」が有効か、Callback URL が登録されているか確認してください。' );
		}

		parse_str( (string) wp_remote_retrieve_body( $response ), $body );
		$token  = (string) ( $body['oauth_token'] ?? '' );
		$secret = (string) ( $body['oauth_token_secret'] ?? '' );
		if ( '' === $token || '' === $secret ) {
			return new WP_Error( 'node_connect_x', 'request_token の応答を解釈できませんでした。' );
		}

		// コールバックで使う token secret を一時保存（10分）。
		set_transient( 'node_connect_x_rt_' . $token, $secret, 10 * MINUTE_IN_SECONDS );

		return 'https://api.twitter.com/oauth/authorize?oauth_token=' . rawurlencode( $token );
	}

	/**
	 * 「Xと連携」完了。verifier を access_token に交換し、トークンとアカウント名を保存する。
	 *
	 * @return true|WP_Error
	 */
	public static function complete_authorization( string $oauth_token, string $oauth_verifier ) {
		$credentials = self::get_app_credentials();
		if ( null === $credentials ) {
			return new WP_Error( 'node_connect_x', 'API Key が未設定です。' );
		}

		$request_secret = (string) get_transient( 'node_connect_x_rt_' . $oauth_token );
		delete_transient( 'node_connect_x_rt_' . $oauth_token );
		if ( '' === $request_secret ) {
			return new WP_Error( 'node_connect_x', '連携セッションの有効期限が切れました。もう一度「Xと連携」からやり直してください。' );
		}

		$credentials['access_token']        = $oauth_token;
		$credentials['access_token_secret'] = $request_secret;

		$url      = 'https://api.twitter.com/oauth/access_token';
		$response = wp_remote_post(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => self::build_oauth_header( $credentials, 'POST', $url, [], [ 'oauth_verifier' => $oauth_verifier ] ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'node_connect_x', 'access_token の取得に失敗しました（HTTP ' . wp_remote_retrieve_response_code( $response ) . '）。' );
		}

		parse_str( (string) wp_remote_retrieve_body( $response ), $body );
		$token  = (string) ( $body['oauth_token'] ?? '' );
		$secret = (string) ( $body['oauth_token_secret'] ?? '' );
		if ( '' === $token || '' === $secret ) {
			return new WP_Error( 'node_connect_x', 'access_token の応答を解釈できませんでした。' );
		}

		update_option( self::OPTION_ACCESS_TOKEN, $token );
		update_option( self::OPTION_ACCESS_TOKEN_SECRET, $secret );
		update_option( self::OPTION_SCREEN_NAME, (string) ( $body['screen_name'] ?? '' ) );

		return true;
	}

	/**
	 * 連携を解除する（アクセストークンとアカウント名を削除。App Key は残す）。
	 */
	public static function disconnect(): void {
		delete_option( self::OPTION_ACCESS_TOKEN );
		delete_option( self::OPTION_ACCESS_TOKEN_SECRET );
		delete_option( self::OPTION_SCREEN_NAME );
	}

	/**
	 * HTTPレスポンスを成否と再送可否に分類する。
	 * 通信エラー・5xx は再送可。4xx（認証不備・重複・権限・レート超過）は再送しない。
	 *
	 * @param array<string, mixed>|WP_Error $response
	 * @return array{ok: bool, status: string, retryable: bool}
	 */
	public static function classify_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			return [
				'ok'        => false,
				'status'    => $response->get_error_message(),
				'retryable' => true,
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return [
			'ok'        => $code >= 200 && $code < 300,
			'status'    => 'HTTP ' . $code,
			'retryable' => $code >= 500,
		];
	}
}
