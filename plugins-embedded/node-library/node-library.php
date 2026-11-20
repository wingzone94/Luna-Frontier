<?php
/**
 * Plugin Name:  Luna Library
 * Plugin URI:   https://github.com/wingzone94/Node
 * Description:  ゲーム・アプリ情報の管理と表示。カスタム投稿タイプによるリスト管理と、記事への紐付け機能を提供。
 * Version:      1.7.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author:       Luminous Core Teams
 * License:      MIT
 * Text Domain:  node-library
 *
 * @package Node_Library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WordPress activation loads the candidate after the active theme. Its embedded
// Library may already own these symbols; the standalone copy takes over on the
// next request. Conditional declarations also prevent function redeclaration.
if ( ! class_exists( 'Node_Library', false ) && ! function_exists( 'node_library_init' ) ) {

define( 'NODE_LIBRARY_VERSION', '1.7.0' );
define( 'NODE_LIBRARY_DIR', plugin_dir_path( __FILE__ ) );
$node_library_embedded_dir = get_template_directory() . '/plugins-embedded/node-library/';
$node_library_is_embedded  = wp_normalize_path( NODE_LIBRARY_DIR ) === wp_normalize_path( $node_library_embedded_dir );
define(
	'NODE_LIBRARY_URL',
	$node_library_is_embedded
		? get_template_directory_uri() . '/plugins-embedded/node-library/'
		: plugin_dir_url( __FILE__ )
);
define( 'NODE_LIBRARY_BADGE_BASE_URL', NODE_LIBRARY_URL . 'assets/images/' );

/**
 * 手動タブ分類で選べるカテゴリ（タブ）の一覧。
 *
 * @return array<string,string> value => 表示ラベル
 */
function node_library_tab_categories(): array {
	return [
		'auto'    => '自動判定',
		'pc'      => 'PC',
		'mobile'  => 'スマホ・タブレット',
		'console' => 'コンソール',
	];
}

/**
 * ストア名（プラットフォーム名）からタブ分類を自動判定する。
 * カテゴリの手動指定が無い（auto）リンクに使う共通ロジック。
 *
 * @param string $platform プラットフォーム名。
 * @return string 'pc' | 'mobile' | 'console'
 */
function node_library_auto_category( string $platform ): string {
	if (
		false !== stripos( $platform, 'nintendo' ) ||
		false !== stripos( $platform, 'switch' ) ||
		false !== stripos( $platform, 'playstation' ) ||
		false !== stripos( $platform, 'ps store' ) ||
		false !== stripos( $platform, 'psn' ) ||
		preg_match( '/(^|\s)ps\s?[345](\s|$)/i', $platform ) ||
		false !== stripos( $platform, 'xbox' )
	) {
		return 'console';
	}

	// Mac App Store / GeForce NOW はデスクトップ・クラウド向けなので「App Store」判定より先に PC とする。
	if ( false !== stripos( $platform, 'mac' ) || false !== stripos( $platform, 'geforce' ) ) {
		return 'pc';
	}

	if (
		false !== stripos( $platform, 'ios' ) ||
		false !== stripos( $platform, 'apple' ) ||
		false !== stripos( $platform, 'ipad' ) ||
		false !== stripos( $platform, 'app store' ) ||
		false !== stripos( $platform, 'android' ) ||
		false !== stripos( $platform, 'google play' ) ||
		false !== stripos( $platform, 'amazon' )
	) {
		return 'mobile';
	}

	return 'pc';
}

/**
 * リンクのカテゴリ値を正規化する（保存・表示・API 共通）。
 * 'pc' | 'mobile' | 'console' のみ手動指定として有効、それ以外は 'auto'。
 *
 * @param mixed $value 入力値。
 * @return string
 */
function node_library_normalize_category( $value ): string {
	$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
	return in_array( $value, [ 'pc', 'mobile', 'console' ], true ) ? $value : 'auto';
}

/**
 * ストアリンクごとの対応ハード候補。
 *
 * @return array<string, string>
 */
function node_library_hardware_options(): array {
	return [
		'auto'              => '自動判定',
		'windows-pc'        => 'Windows PC',
		'mac'               => 'Mac',
		'iphone-ipad'       => 'iPhone / iPad',
		'android'           => 'Android',
		'amazon-fire'       => 'Amazon Fire / Amazon Appstore',
		'nintendo-switch'   => 'Nintendo Switch',
		'nintendo-switch-2' => 'Nintendo Switch 2',
		'playstation-4'     => 'PlayStation 4',
		'playstation-5'     => 'PlayStation 5',
		'playstation-crossgen' => 'PlayStation 4 / 5',
		'xbox-one'          => 'Xbox One',
		'xbox-series'       => 'Xbox Series X|S',
		'xbox-crossgen'     => 'Xbox One / Xbox Series X|S',
	];
}

/**
 * 対応ハード値を保存用に正規化する。
 *
 * @param mixed $value 入力値。
 * @return string
 */
function node_library_normalize_hardware( $value ): string {
	$value = is_string( $value ) ? sanitize_key( $value ) : '';
	return array_key_exists( $value, node_library_hardware_options() ) ? $value : 'auto';
}

/**
 * 既存入力のplatform名から対応ハードを推定する。
 *
 * @param string $platform プラットフォーム名。
 * @return string
 */
function node_library_infer_hardware_from_platform( string $platform ): string {
	$platform = strtolower( $platform );
	$has_ps4 = false !== strpos( $platform, 'playstation 4' ) || false !== strpos( $platform, 'ps4' );
	$has_ps5 = false !== strpos( $platform, 'playstation 5' ) || false !== strpos( $platform, 'ps5' );
	$has_xbox_one = false !== strpos( $platform, 'xbox one' );
	$has_xbox_series = false !== strpos( $platform, 'series' ) || false !== strpos( $platform, 'x|s' ) || false !== strpos( $platform, 's・x' );

	if ( false !== strpos( $platform, 'switch 2' ) || false !== strpos( $platform, 'switch2' ) ) return 'nintendo-switch-2';
	if ( false !== strpos( $platform, 'switch' ) || false !== strpos( $platform, 'nintendo' ) ) return 'nintendo-switch';
	if ( $has_ps4 && $has_ps5 ) return 'playstation-crossgen';
	if ( $has_ps5 ) return 'playstation-5';
	if ( $has_ps4 ) return 'playstation-4';
	if ( $has_xbox_one && $has_xbox_series ) return 'xbox-crossgen';
	if ( $has_xbox_series ) return 'xbox-series';
	if ( $has_xbox_one ) return 'xbox-one';
	if ( false !== strpos( $platform, 'amazon' ) ) return 'amazon-fire';
	if ( false !== strpos( $platform, 'android' ) || false !== strpos( $platform, 'google play' ) ) return 'android';
	if ( false !== strpos( $platform, 'ios' ) || false !== strpos( $platform, 'ipad' ) || false !== strpos( $platform, 'iphone' ) || false !== strpos( $platform, 'app store' ) ) return 'iphone-ipad';
	if ( false !== strpos( $platform, 'mac' ) ) return 'mac';
	if ( false !== strpos( $platform, 'windows' ) || false !== strpos( $platform, 'pc' ) ) return 'windows-pc';

	return 'auto';
}

/**
 * Luna Library Main Class
 */
final class Node_Library {
	private const CARD_REFERENCE_META        = '_node_library_card_reference';
	private const LIBRARY_DEPENDENCY_META    = '_node_library_card_dependency';
	private const REUSABLE_DEPENDENCY_META   = '_node_library_reusable_block_dependency';
	private const REFERENCE_INDEX_VERSION    = '3';
	private const REWRITE_SLUG               = 'library';
	private const LEGACY_REWRITE_SLUG        = 'node-library';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'template_redirect', [ $this, 'redirect_legacy_library_urls' ], 0 );
		add_filter( 'query_vars', [ $this, 'register_library_archive_query_var' ] );
		add_action( 'pre_get_posts', 'node_library_filter_archive_query', 20 );
		add_filter( 'posts_where', 'node_library_archive_posts_where', 10, 2 );
		add_filter( 'posts_clauses', 'node_library_archive_posts_clauses', 10, 2 );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_meta_boxes' ] );
		add_action( 'wp_after_insert_post', [ $this, 'synchronize_library_card_references_after_save' ], 20, 4 );
		add_action( 'before_delete_post', [ $this, 'remove_library_card_references_before_delete' ], 10, 2 );
		add_action( 'after_delete_post', [ $this, 'synchronize_references_after_dependency_delete' ], 10, 2 );
		add_action( 'init', [ $this, 'migrate_library_card_references' ], 20 );
		
		// テーマのフックに応答（タグの下に表示）
		add_action( 'luminous_after_tags', [ $this, 'render_library_card_on_post' ] );

		// 管理画面用アセット
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Gutenberg ブロックの登録
		add_action( 'init', [ $this, 'register_gutenberg_block' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_assets' ] );

		// REST API Endpoint 登録
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * REST API Routes
	 */
	public function register_rest_routes(): void {
		register_rest_route( 'node-library/v1', '/fetch-ogp', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_fetch_ogp' ],
			'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
			'args'                => [
				'url' => [ 'required' => true, 'sanitize_callback' => 'esc_url_raw' ],
			],
		] );

		register_rest_route( 'node-library/v1', '/blog-card-preview', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_blog_card_preview' ],
			'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
			'args'                => [
				'url' => [ 'required' => true, 'sanitize_callback' => 'esc_url_raw' ],
				'title' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'description' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'image' => [
					'default'           => '',
					'sanitize_callback' => 'esc_url_raw',
				],
			],
		] );

		register_rest_route( 'node-library/v1', '/items', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_list_items' ],
			'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
		] );


	}

	/**
	 * ライブラリ項目一覧（ブロックエディタ用）
	 */
	public function handle_list_items( $request ) {
		$posts = get_posts(
			array(
				'post_type'      => 'node_library',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$items = array_map(
			static function ( $post ) {
				$summary = (string) get_post_meta( $post->ID, '_node_library_summary', true );
				$links   = get_post_meta( $post->ID, '_node_library_links', true );
				$link_count = is_array( $links ) ? count( $links ) : 0;

				return array(
					'id'         => $post->ID,
					'title'      => $post->post_title,
					'summary'    => $summary,
					'link_count' => $link_count,
				);
			},
			$posts
		);

		return rest_ensure_response( $items );
	}

	/** Normalize UTF-8, Shift_JIS and EUC-JP pages before OGP parsing. */
	public static function normalize_ogp_encoding( string $html, string $type ): string {
		$encoding = '';
		if ( preg_match( '/charset\s*=\s*["\x27]?([a-zA-Z0-9_-]+)/i', $type, $m ) || preg_match( '/<meta\b[^>]*charset\s*=\s*["\x27]?([a-zA-Z0-9_-]+)/i', substr( $html, 0, 8192 ), $m ) ) {
			$encoding = strtolower( $m[1] );
		}
		$encodings = array( 'shift_jis' => 'SJIS-win', 'shift-jis' => 'SJIS-win', 'sjis' => 'SJIS-win', 'windows-31j' => 'SJIS-win', 'euc-jp' => 'EUC-JP', 'utf-8' => 'UTF-8', 'utf8' => 'UTF-8' );
		if ( isset( $encodings[ $encoding ] ) && function_exists( 'mb_convert_encoding' ) ) {
			$html = mb_convert_encoding( $html, 'UTF-8', $encodings[ $encoding ] );
		} elseif ( 1 !== preg_match( '//u', $html ) && function_exists( 'mb_detect_encoding' ) ) {
			$detected = mb_detect_encoding( $html, array( 'UTF-8', 'SJIS-win', 'EUC-JP' ), true );
			if ( $detected ) {
				$html = mb_convert_encoding( $html, 'UTF-8', $detected );
			}
		}
		return wp_check_invalid_utf8( $html, true );
	}

	/**
	 * URL からストア種別に応じた初期属性を抽出する。
	 */
	public static function extract_product_store_fields( string $url ): array {
		$fields = [];
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( 'store-jp.nintendo.com' === $host ) {
			$fields['nintendo_url'] = $url;
		} elseif ( 'rakuten.co.jp' === $host || str_ends_with( $host, '.rakuten.co.jp' ) ) {
			$fields['rakuten_url'] = $url;
		} elseif ( 'amazon.co.jp' === $host || str_ends_with( $host, '.amazon.co.jp' ) || 'amazon.com' === $host || str_ends_with( $host, '.amazon.com' ) || 'amzn.to' === $host || 'amzn.asia' === $host ) {
			if ( preg_match( '~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})(?:[/?#]|$)~i', $url, $matches ) ) {
				$fields['asin'] = strtoupper( $matches[1] );
			} else {
				$fields['amazon_url'] = $url;
			}
		}

		return $fields;
	}

	/**
	 * My Nintendo Store URL から任天堂の公開ソフト検索 JSON API 経由で商品情報を取得する。
	 *
	 * store-jp.nintendo.com は Queue-it / Akamai による待合室・ボット対策があるため、
	 * 待合室回避は行わず、公開 JSON API（search.nintendo.jp）が使えるソフトのみ取得する。
	 */
	private function fetch_nintendo_api_product_data( string $url ): ?array {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = '';
		$key   = '';

		if ( preg_match( '#/item/software/(?:[A-Z]_?)?([0-9]{14})\b#i', $path, $matches ) ) {
			$key   = 'nsuid_' . $matches[1];
			$query = 'nsuid_s:' . $matches[1];
		} elseif ( preg_match( '#/item/software/(?:HAC|BEE)_[A-Z]_([A-Z0-9]{5})\b#i', $path, $matches ) ) {
			$icode = strtoupper( $matches[1] );
			$key   = 'icode_' . $icode;
			$query = 'icode_s:' . $icode;
		}

		if ( '' === $query ) {
			return null;
		}

		$transient_key = 'node_lib_nintendo_api_v1_' . md5( $key );
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) && ! empty( $cached['title'] ) ) {
			return $cached;
		}

		$api_url  = add_query_arg(
			[
				'fq'    => $query,
				'limit' => 1,
			],
			'https://search.nintendo.jp/nintendo_soft/search.json'
		);
		$response = wp_safe_remote_get(
			$api_url,
			[
				'timeout'     => 5,
				'redirection' => 2,
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[node-library] Nintendo API fetch failed: ' . $response->get_error_message() );
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			error_log( '[node-library] Nintendo API fetch failed: HTTP ' . $status );
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$item    = $decoded['result']['items'][0] ?? null;
		if ( ! is_array( $item ) ) {
			return null;
		}

		$title = isset( $item['title'] ) && is_scalar( $item['title'] ) ? trim( (string) $item['title'] ) : '';
		$image = '';
		$iurl  = isset( $item['iurl'] ) && is_scalar( $item['iurl'] ) ? trim( (string) $item['iurl'] ) : '';
		if ( 1 === preg_match( '/^[a-f0-9]{64}$/i', $iurl ) ) {
			$image = 'https://img-eshop.cdn.nintendo.net/i/' . strtolower( $iurl ) . '.jpg';
		} elseif ( str_starts_with( $iurl, '/' ) ) {
			$image = 'https://www.nintendo.com' . $iurl;
		} elseif ( preg_match( '~^https?://~i', $iurl ) ) {
			$image = esc_url_raw( $iurl, [ 'http', 'https' ] );
		}

		$price     = '';
		$raw_price = $item['current_price'] ?? $item['dprice'] ?? $item['pprice'] ?? null;
		if ( is_numeric( $raw_price ) && (float) $raw_price > 0 ) {
			$price = '￥' . number_format( (int) round( (float) $raw_price ) );
		}

		if ( '' === $title && '' === $image && '' === $price ) {
			return null;
		}

		$data = [
			'title'  => $title,
			'image'  => $image,
			'price'  => $price,
			'source' => 'nintendo_api',
		];
		set_transient( $transient_key, $data, DAY_IN_SECONDS );

		return $data;
	}

	/** Extract only Product structured data, never arbitrary recommendation images. */
	private static function structured_product_metadata( string $html, string $url ): array {
		$result = array( 'title' => '', 'image' => '' );
		$visit = static function ( $value, int $depth = 0 ) use ( &$visit, $url ) {
			if ( ! is_array( $value ) || $depth > 8 ) {
				return null;
			}
			if ( in_array( 'Product', (array) ( $value['@type'] ?? array() ), true ) ) {
				$candidate_url = $value['url'] ?? $value['offers']['url'] ?? '';
				$product_url = is_string( $candidate_url ) ? $candidate_url : '';
				if ( '' === $product_url || wp_parse_url( $product_url, PHP_URL_PATH ) === wp_parse_url( $url, PHP_URL_PATH ) ) {
					return $value;
				}
			}
			foreach ( $value as $child ) {
				if ( is_array( $child ) ) {
					$found = $visit( $child, $depth + 1 );
					if ( is_array( $found ) ) {
						return $found;
					}
				}
			}
			return null;
		};
		if ( preg_match_all( '~<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script\s*>~is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $json ) {
				$item = $visit( json_decode( $json, true ) );
				if ( ! is_array( $item ) ) {
					continue;
				}
				$image = $item['image'] ?? '';
				if ( is_array( $image ) && isset( $image[0] ) ) {
					$image = $image[0];
				}
				if ( is_array( $image ) ) {
					$image = $image['url'] ?? $image['contentUrl'] ?? '';
				}
				$result['title'] = is_string( $item['name'] ?? null ) ? sanitize_text_field( $item['name'] ) : '';
				$result['image'] = is_string( $image ) && '' !== $image ? esc_url_raw( WP_Http::make_absolute_url( $image, $url ), array( 'https', 'http' ) ) : '';
				break;
			}
		}
		return $result;
	}

	/**
	 * OGP 取得失敗時のフォールバックレスポンスを生成する（登録フローを止めない）。
	 */
	private function build_ogp_fallback_response( string $url, string $log_reason, string $status = 'fetch_failed' ): WP_REST_Response {
		error_log( sprintf( '[node-library] Product metadata: %s (%s)', $status, (string) wp_parse_url( $url, PHP_URL_HOST ) ) );

		$payload = array_merge(
			[
				'title'     => '',
				'image'     => '',
				'price'     => '',
				'ogp_error' => true,
				'partial'   => false,
				'message'   => 'blocked' === $status ? '商品ページへのアクセスが制限されています。ブラウザーから商品情報を取り込めます。' : ( 'image_missing' === $status ? '商品画像が見つかりません。ブラウザーから商品情報を取り込めます。' : 'OGPを取得できませんでした。手入力またはブラウザーから取り込めます。' ),
				'status'    => $status,
				'source'    => 'none',
			],
			self::extract_product_store_fields( $url )
		);

		return rest_ensure_response( $payload );
	}

	/** Match actual Amazon hosts, never lookalike domains or query strings. */
	private static function is_amazon_product_host( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return in_array( $host, array( 'amazon.co.jp', 'www.amazon.co.jp', 'amazon.com', 'www.amazon.com' ), true );
	}

	/** Amazon product pages can omit og:image while providing a main image. */
	private static function amazon_product_image( string $html ): string {
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'IMG' ) ) {
			if ( ! in_array( $tags->get_attribute( 'id' ), array( 'landingImage', 'imgBlkFront', 'main-image' ), true ) ) {
				continue;
			}
			$candidates = array( $tags->get_attribute( 'data-old-hires' ) );
			$images = json_decode( (string) $tags->get_attribute( 'data-a-dynamic-image' ), true );
			if ( is_array( $images ) ) {
				// Largest variant first; src can be a transparent loading pixel.
				uasort( $images, static function ( $a, $b ) {
					$area_a = is_array( $a ) ? (float) ( $a[0] ?? 0 ) * (float) ( $a[1] ?? 0 ) : 0;
					$area_b = is_array( $b ) ? (float) ( $b[0] ?? 0 ) * (float) ( $b[1] ?? 0 ) : 0;
					return $area_b <=> $area_a;
				} );
				$candidates = array_merge( $candidates, array_keys( $images ) );
			}
			$candidates[] = $tags->get_attribute( 'src' );
			foreach ( $candidates as $candidate ) {
				$image = is_string( $candidate ) ? esc_url_raw( $candidate, array( 'https', 'http' ) ) : '';
				if ( '' !== $image && wp_parse_url( $image, PHP_URL_HOST ) && ! preg_match( '~(?:transparent-pixel|\.gif(?:[?#]|$))~i', $image ) ) {
					return $image;
				}
			}
		}
		return '';
	}

	/**
	 * OGP 取得ハンドラ (自己完結型・グレースフルデグラデーション対応)
	 */
	public function handle_fetch_ogp( $request ) {
		$url = trim( (string) $request->get_param( 'url' ) );
		if ( '' === $url || ! preg_match( '~^https?://~i', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) || wp_parse_url( $url, PHP_URL_USER ) || wp_parse_url( $url, PHP_URL_PASS ) ) {
			return new WP_Error( 'invalid_url', '有効な商品 URL（http/https）を入力してください。', [ 'status' => 400 ] );
		}

		$host         = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$store_fields = self::extract_product_store_fields( $url );

		if ( 'store-jp.nintendo.com' === $host ) {
			$nintendo_data = $this->fetch_nintendo_api_product_data( $url );
			if ( is_array( $nintendo_data ) ) {
				return rest_ensure_response(
					array_merge(
						$nintendo_data,
						$store_fields,
						[
							'ogp_error' => false,
							'status'    => '' !== $nintendo_data['title'] && '' !== $nintendo_data['image'] ? 'complete' : 'partial',
							'partial'   => '' === $nintendo_data['title'] || '' === $nintendo_data['image'],
						]
					)
				);
			}
		}

		$transient_key = 'node_lib_ogp_utf8_v5_' . md5( $url );
		$data          = get_transient( $transient_key );

		if ( false === $data ) {
			$request_args = [
				'timeout'     => 8,
				'redirection' => 'store-jp.nintendo.com' === $host ? 0 : 3,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
			];
			$response     = wp_safe_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				return $this->build_ogp_fallback_response( $url, $response->get_error_message() );
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $status_code ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				$reason   = '' !== $location ? 'HTTP ' . $status_code . ' -> ' . $location : 'HTTP ' . $status_code;
				return $this->build_ogp_fallback_response( $url, $reason, ( in_array( $status_code, array( 403, 429 ), true ) || preg_match( '~(?:store-jp-wr\.nintendo\.com|queue-it\.net)~i', $location ) ) ? 'blocked' : 'fetch_failed' );
			}

			$html = (string) wp_remote_retrieve_body( $response );
			if ( '' === trim( $html ) ) {
				return $this->build_ogp_fallback_response( $url, 'empty body' );
			}

			// Queue-it 待合室ページを商品 OGP として誤認・キャッシュしない。
			if ( str_contains( $html, 'store-jp-wr.nintendo.com' ) || str_contains( $html, 'queue-it.net' ) || preg_match( '~(?:action=[\"\'][^\"\']*validateCaptcha|id=[\"\']captchacharacters|<title[^>]*>\s*Robot Check)~i', $html ) ) {
				return $this->build_ogp_fallback_response( $url, 'Challenge page', 'blocked' );
			}

			$html = self::normalize_ogp_encoding( $html, (string) wp_remote_retrieve_header( $response, 'content-type' ) );

			// Parse normalized UTF-8 directly: legacy charset meta tags must not
			// cause libxml to reinterpret the already converted document.
			$data = [ 'title' => '', 'image' => '', 'price' => '', 'source' => 'ogp' ];
			$tags = new WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag( 'META' ) ) {
				$property = strtolower( (string) $tags->get_attribute( 'property' ) );
				$value    = $tags->get_attribute( 'content' );
				if ( 'og:title' === $property && is_string( $value ) ) {
					$data['title'] = trim( $value );
				} elseif ( 'og:image' === $property && is_string( $value ) ) {
					$data['image'] = trim( $value );
				} elseif ( ( 'og:price:amount' === $property || 'product:price:amount' === $property ) && is_string( $value ) && '' === $data['price'] ) {
					$amount = trim( $value );
					if ( is_numeric( $amount ) && (float) $amount > 0 ) {
						$data['price'] = '￥' . number_format( (int) round( (float) $amount ) );
					}
				}
			}


			if ( 'store-jp.nintendo.com' === $host ) {
				$product = self::structured_product_metadata( $html, $url );
				foreach ( array( 'title', 'image' ) as $field ) {
					if ( '' === $data[ $field ] && '' !== $product[ $field ] ) {
						$data[ $field ] = $product[ $field ];
						$data['source'] = 'product_page';
					}
				}
			}

			if ( '' === $data['title'] && preg_match( '~<title\b[^>]*>(.*?)</title\s*>~is', $html, $title ) ) {
				$data['title'] = trim( html_entity_decode( wp_strip_all_tags( $title[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}

			// Amazon product images are not always exposed as Open Graph metadata.
			$resolved_url = $url;
			if ( isset( $response['http_response'] ) && $response['http_response'] instanceof WP_HTTP_Requests_Response ) {
				$resolved_url = $response['http_response']->get_response_object()->url;
			}
			$amazon_url = self::is_amazon_product_host( $resolved_url ) ? $resolved_url : $url;
			if ( self::is_amazon_product_host( $amazon_url ) ) {
				$data['resolved_store_fields'] = self::extract_product_store_fields( $amazon_url );
				if ( '' === $data['image'] ) {
					$data['image'] = self::amazon_product_image( $html );
					if ( '' !== $data['image'] ) {
						$data['source'] = 'product_page';
					}
				}
				if ( '' === $data['image'] ) {
					return $this->build_ogp_fallback_response( $url, 'Amazon product image unavailable', 'image_missing' );
				}
			}

			if ( '' === $data['title'] && '' === $data['image'] ) {
				return $this->build_ogp_fallback_response( $url, 'OGP tags not found', 'metadata_missing' );
			}

			set_transient( $transient_key, $data, '' !== $data['image'] ? WEEK_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		}

		$store_fields = array_merge( $store_fields, $data['resolved_store_fields'] ?? [] );
		$is_partial = '' === ( $data['title'] ?? '' ) || '' === ( $data['image'] ?? '' );

		return rest_ensure_response(
			array_merge(
				[
					'title'     => (string) ( $data['title'] ?? '' ),
					'image'     => (string) ( $data['image'] ?? '' ),
					'price'     => (string) ( $data['price'] ?? '' ),
					'ogp_error' => false,
					'partial'   => $is_partial,
					'status'    => '' === ( $data['image'] ?? '' ) ? 'image_missing' : ( $is_partial ? 'partial' : 'complete' ),
					'source'    => (string) ( $data['source'] ?? 'ogp' ),
				],
				$store_fields
			)
		);
	}

	/**
	 * ブログカードのエディタ用プレビューHTML。
	 */
	public function handle_blog_card_preview( $request ) {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( '' === $url ) {
			return new WP_Error( 'node_library_blog_card_bad_url', 'URL が空です', [ 'status' => 400 ] );
		}

		$overrides = [
			'title'       => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'description' => sanitize_text_field( (string) $request->get_param( 'description' ) ),
			'image'       => esc_url_raw( (string) $request->get_param( 'image' ) ),
		];
		$ogp       = function_exists( 'node_get_ogp_data' ) ? node_get_ogp_data( $url ) : false;
		$html      = $this->render_blog_card_block(
			array_merge(
				[ 'url' => $url ],
				array_filter( $overrides )
			)
		);
		if ( '' === $html ) {
			return new WP_Error( 'node_library_blog_card_no_card', 'カードを生成できませんでした', [ 'status' => 404 ] );
		}

		// URL から組み立てたカード（m3-blogcard--fallback）も「取得できていない」印として返す。
		// エディタ側はこれを見て、著者に題名の手入力を促す。
		$is_fallback = str_contains( $html, 'm3-blogcard--fallback' ) || str_contains( $html, 'm3-blogcard__fallback' );

		$title = '' !== $overrides['title'] ? $overrides['title'] : ( is_array( $ogp ) ? (string) ( $ogp['title'] ?? '' ) : '' );
		if ( '' === $title && $is_fallback && function_exists( 'node_blogcard_title_from_url' ) ) {
			$title = node_blogcard_title_from_url( $url );
		}

		return rest_ensure_response(
			[
				'html'     => $html,
				'title'    => $title,
				'fallback' => $is_fallback,
			]
		);
	}

	/**
	 * Gutenberg ブロックの登録
	 */
	public function register_gutenberg_block(): void {
		register_block_type( 'node-library/item-card', [
			'attributes'      => [
				'libraryId' => [
					'type'    => 'number',
					'default' => 0,
				],
			],
			'render_callback' => [ $this, 'render_block' ],
		] );

		register_block_type( 'node-library/blog-card', [
			'attributes'      => [
				'url' => [
					'type'    => 'string',
					'default' => '',
				],
				'title' => [
					'type'    => 'string',
					'default' => '',
				],
				'description' => [
					'type'    => 'string',
					'default' => '',
				],
				'image' => [
					'type'    => 'string',
					'default' => '',
				],
			],
			'render_callback' => [ $this, 'render_blog_card_block' ],
		] );

		register_block_type( 'node-library/product-card', [
			'attributes'      => [
				'title'                 => [ 'type' => 'string', 'default' => '' ],
				'price'                 => [ 'type' => 'string', 'default' => '' ],
				'imageUrl'              => [ 'type' => 'string', 'default' => '' ],
				'amazonUrl'             => [ 'type' => 'string', 'default' => '' ],
				'asin'                  => [ 'type' => 'string', 'default' => '' ],
				'amazonDownload'        => [ 'type' => 'boolean', 'default' => false ],
				'rakutenUrl'            => [ 'type' => 'string', 'default' => '' ],
				'nintendoUrl'           => [ 'type' => 'string', 'default' => '' ],
				'showAmazonDisclosure'  => [ 'type' => 'boolean', 'default' => true ],
				'showRakutenDisclosure' => [ 'type' => 'boolean', 'default' => true ],
			],
			'render_callback' => [ $this, 'render_product_card_block' ],
		] );
	}

	/**
	 * ブロックのレンダリング (Product Card)
	 */
	public function render_product_card_block( $attributes ): string {
		if ( ! function_exists( 'luminous_nexus_render_product_card' ) ) {
			return '';
		}

		return luminous_nexus_render_product_card(
			array(
				'title'                   => $attributes['title'] ?? '',
				'price'                   => $attributes['price'] ?? '',
				'image_url'               => $attributes['imageUrl'] ?? '',
				'amazon_url'              => $attributes['amazonUrl'] ?? '',
				'asin'                    => $attributes['asin'] ?? '',
				'amazon_download'         => $attributes['amazonDownload'] ?? false,
				'rakuten_url'             => $attributes['rakutenUrl'] ?? '',
				'nintendo_url'            => $attributes['nintendoUrl'] ?? '',
				'show_amazon_disclosure'  => $attributes['showAmazonDisclosure'] ?? true,
				'show_rakuten_disclosure' => $attributes['showRakutenDisclosure'] ?? true,
			)
		);
	}

	/**
	 * ブロックのレンダリング (Blog Card)
	 */
	public function render_blog_card_block( $attributes ): string {
		$url = $attributes['url'] ?? '';
		if ( empty( $url ) ) return '';

		$overrides = [
			'title'       => sanitize_text_field( (string) ( $attributes['title'] ?? '' ) ),
			'description' => sanitize_text_field( (string) ( $attributes['description'] ?? '' ) ),
			'image'       => esc_url_raw( (string) ( $attributes['image'] ?? '' ) ),
		];

		if ( function_exists( 'node_render_blogcard' ) ) {
			$html = node_render_blogcard( $url, false, $overrides );
			if ( '' !== $html ) {
				return $html;
			}
		}

		if ( function_exists( 'luminous_nexus_blogcard_shortcode' ) ) {
			$html = luminous_nexus_blogcard_shortcode( [ 'url' => $url ] );
			if ( '' !== $html ) {
				return $html;
			}
		}

		return function_exists( 'node_blogcard_plain_link' )
			? node_blogcard_plain_link( $url )
			: '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
	}

	/**
	 * ブロックエディタ用アセットの読み込み
	 */
	public function enqueue_block_assets(): void {
		wp_enqueue_script( 'node-library-product-import', NODE_LIBRARY_URL . 'assets/js/product-import.js', array(), NODE_LIBRARY_VERSION . '.' . filemtime( NODE_LIBRARY_DIR . 'assets/js/product-import.js' ), true );
		wp_enqueue_script(
			'node-library-block-editor',
			NODE_LIBRARY_URL . 'assets/js/block-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-block-editor', 'node-library-product-import' ],
			NODE_LIBRARY_VERSION . '.' . filemtime( NODE_LIBRARY_DIR . 'assets/js/block-editor.js' ),
			true
		);

		wp_add_inline_script(
			'node-library-block-editor',
			'var nodeLibraryEditor = ' . wp_json_encode(
				array(
					'adminNewUrl'          => admin_url( 'post-new.php?post_type=node_library' ),
					'adminListUrl'         => admin_url( 'edit.php?post_type=node_library' ),
					'iconCss'              => function_exists( 'node_get_icon_font_css' ) ? node_get_icon_font_css() : '',
					'productCardAvailable' => function_exists( 'luminous_nexus_render_product_card' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * 管理画面用スクリプト（ライブラリ編集・投稿紐付け）
	 */
	public function enqueue_admin_assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$allowed = array( 'node_library', 'post' );
		if ( ! in_array( $screen->post_type, $allowed, true ) ) {
			return;
		}

		wp_enqueue_script(
			'node-library-admin-meta',
			NODE_LIBRARY_URL . 'assets/js/admin-meta.js',
			array( 'wp-api-fetch' ),
			NODE_LIBRARY_VERSION,
			true
		);

		wp_localize_script(
			'node-library-admin-meta',
			'nodeLibraryAdmin',
			array(
				'fetchStorePath' => '/node-library/v1/fetch-store',
			)
		);

		wp_add_inline_style(
			'wp-admin',
			'.node-library-admin-fields .link-row.is-hidden{display:none}
			.node-library-metabox-help{color:#646970;font-size:12px;line-height:1.6;margin:8px 0 0}
			.node-library-metabox-preview{margin-top:10px;padding:10px 12px;background:#f6f7f7;border-radius:8px;border:1px solid #dcdcde}
			.node-library-metabox-links{margin-top:8px;display:flex;flex-wrap:wrap;gap:8px}
			.node-library-metabox-links a{font-size:12px}'
		);
	}

	/**
	 * ブロックのレンダリング (サーバーサイド)
	 */
	public function render_block( $attributes ): string {
		$lib_id = $attributes['libraryId'] ?? 0;
		if ( ! $lib_id ) return '';

		$lib_post = get_post( $lib_id );
		if ( ! $lib_post || $lib_post->post_type !== 'node_library' ) return '';

		$game_info = [
			'title'   => $lib_post->post_title,
			'type'    => get_post_meta( $lib_id, '_node_library_type', true ) ?: 'game',
			'summary' => get_post_meta( $lib_id, '_node_library_summary', true ),
			'links'   => get_post_meta( $lib_id, '_node_library_links', true ),
		];

		ob_start();
		include NODE_LIBRARY_DIR . 'templates/card-library.php';
		return ob_get_clean();
	}

	/**
	 * カスタム投稿タイプ 'node_library' の登録
	 */
	public function register_cpt(): void {
		$labels = [
			'name'               => 'Luna Library',
			'singular_name'      => 'ライブラリ項目',
			'menu_name'          => 'Luna Library',
			'add_new'            => '新規追加',
			'add_new_item'       => '新しいゲーム・アプリを追加',
			'edit_item'          => '項目を編集',
			'new_item'           => '新規項目',
			'view_item'          => '項目を表示',
			'search_items'       => 'ライブラリを検索',
			'not_found'          => '見つかりませんでした',
			'not_found_in_trash' => 'ゴミ箱内にありません',
		];

		$args = [
			'labels'              => $labels,
			'public'              => false, 
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true, 
			'query_var'           => true,
			'rewrite'             => [ 'slug' => self::REWRITE_SLUG ],
			'capability_type'     => 'post',
			'has_archive'         => true,
			'hierarchical'        => false,
			'menu_position'       => 20,
			'menu_icon'           => 'dashicons-database-add',
			'supports'            => [ 'title' ],
		];

		register_post_type( 'node_library', $args );

		add_rewrite_tag( '%node_library_type%', '(game|app)' );
		add_rewrite_rule( '^' . self::REWRITE_SLUG . '/(game|app)/page/([0-9]{1,})/?$', 'index.php?post_type=node_library&node_library_type=$matches[1]&paged=$matches[2]', 'top' );
		add_rewrite_rule( '^' . self::REWRITE_SLUG . '/(game|app)/?$', 'index.php?post_type=node_library&node_library_type=$matches[1]', 'top' );

		$current_flush_version = (string) get_option( 'node_library_flushed_version', '0' );
		$current_rewrite_slug  = (string) get_option( 'node_library_rewrite_slug', '' );
		if ( version_compare( $current_flush_version, NODE_LIBRARY_VERSION, '<' ) || self::REWRITE_SLUG !== $current_rewrite_slug ) {
			flush_rewrite_rules();
			update_option( 'node_library_flushed_version', NODE_LIBRARY_VERSION );
			update_option( 'node_library_rewrite_slug', self::REWRITE_SLUG );
		}
	}

	/**
	 * 旧 /node-library/ を /library/ へ 301 転送する。
	 *
	 * アーカイブ、タイプ別、ページ送り、個別項目は先頭セグメントの置換で揃える。
	 * プラグイン内で完結させ、サーバー設定には依存しない。
	 *
	 * @param bool $exit 転送後に終了するか。テストからヘッダだけ確認するときに false。
	 * @return string|null 転送先。対象外のリクエストでは null。
	 */
	public function redirect_legacy_library_urls( bool $exit = true ): ?string {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return null;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		$target      = $this->legacy_library_redirect_url( $request_uri );
		if ( null === $target ) {
			return null;
		}

		wp_safe_redirect( $target, 301 );
		if ( $exit ) {
			exit;
		}

		return $target;
	}

	/**
	 * 旧ライブラリ URL に対応する新 URL。対象外なら null。
	 *
	 * @param string $request_uri リクエスト URI（パスとクエリ）。
	 * @return string|null
	 */
	public function legacy_library_redirect_url( string $request_uri ): ?string {
		$parts = wp_parse_url( $request_uri );
		$path  = ( is_array( $parts ) && isset( $parts['path'] ) && is_string( $parts['path'] ) ) ? $parts['path'] : '';
		if ( '' === $path ) {
			return null;
		}

		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? untrailingslashit( $home_path ) : '';
		$relative  = $path;
		if ( '' !== $home_path && str_starts_with( $path, $home_path . '/' ) ) {
			$relative = substr( $path, strlen( $home_path ) );
		}

		$legacy = preg_quote( self::LEGACY_REWRITE_SLUG, '#' );
		if ( ! preg_match( '#^/' . $legacy . '(?:/|$)#', $relative ) ) {
			return null;
		}

		$rewritten = preg_replace( '#^/' . $legacy . '#', '/' . self::REWRITE_SLUG, $relative, 1 );
		if ( ! is_string( $rewritten ) || $rewritten === $relative ) {
			return null;
		}

		// canonical の2段目301を避ける。パーマリンク設定の末尾スラッシュに揃えて1回で届ける。
		$rewritten = user_trailingslashit( $rewritten );
		$target    = untrailingslashit( home_url( '/' ) ) . $rewritten;
		$query  = ( is_array( $parts ) && isset( $parts['query'] ) && is_string( $parts['query'] ) ) ? $parts['query'] : '';
		if ( '' !== $query ) {
			$target .= '?' . $query;
		}

		return $target;
	}

	/**
	 * Register the type query variable used by dedicated Library archives.
	 *
	 * @param array<int, string> $query_vars Public query variables.
	 * @return array<int, string>
	 */
	public function register_library_archive_query_var( array $query_vars ): array {
		$query_vars[] = 'node_library_type';
		$query_vars[] = 'q';
		$query_vars[] = 'tab';
		$query_vars[] = 'sort';
		$query_vars[] = 'dir';
		return array_values( array_unique( $query_vars ) );
	}

	/**
	 * メタボックスの登録
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'node_library_details',
			'ゲーム・アプリ詳細情報',
			[ $this, 'render_details_meta_box' ],
			'node_library',
			'normal',
			'high'
		);

		add_meta_box(
			'node_post_library_select',
			'ゲーム・アプリ情報の紐付け',
			[ $this, 'render_select_meta_box' ],
			'post',
			'side',
			'default'
		);
	}

	/**
	 * メタボックスの描画
	 */
	public function render_details_meta_box( $post ): void {
		wp_nonce_field( 'node_library_save_details', 'node_library_details_nonce' );
		
		$type    = get_post_meta( $post->ID, '_node_library_type', true );
		$type    = in_array( $type, [ 'game', 'app' ], true ) ? $type : 'game';
		$summary = get_post_meta( $post->ID, '_node_library_summary', true );
		$links   = get_post_meta( $post->ID, '_node_library_links', true );
		if ( ! is_array( $links ) ) $links = [];

		?>
		<div class="node-library-admin-fields">
			<p>
				<label for="node-library-type"><strong>種類:</strong></label><br>
				<select name="node_library_type" id="node-library-type">
					<option value="game" <?php selected( $type, 'game' ); ?>>ゲーム</option>
					<option value="app" <?php selected( $type, 'app' ); ?>>アプリ</option>
				</select>
			</p>
			<p>
				<label><strong>紹介文・レビュー:</strong></label><br>
				<textarea name="node_library_summary" rows="4" style="width:100%;" placeholder="作品の魅力やレビューを自由に記載してください。"><?php echo esc_textarea( $summary ); ?></textarea>
				<span class="description"><?php esc_html_e( 'ストアの説明文を確認・編集するか、紹介文を手入力してください。', 'node-library' ); ?></span>
			</p>
			
			<div id="node-library-links-editor">
				<label><strong>ストア・配信ページリンク:</strong></label>
				<div class="links-container" style="margin-top:10px;" data-max-rows="10">
					<?php
					$filled_count = is_array( $links ) ? count( $links ) : 0;
					$visible_rows = max( 2, min( 10, $filled_count + 1 ) );
					$tab_categories = function_exists( 'node_library_tab_categories' ) ? node_library_tab_categories() : [ 'auto' => '自動判定' ];
					$hardware_options = function_exists( 'node_library_hardware_options' ) ? node_library_hardware_options() : [ 'auto' => '自動判定' ];
					for ( $i = 0; $i < 10; $i++ ) :
						$p       = $links[ $i ]['platform'] ?? '';
						$u       = $links[ $i ]['url'] ?? '';
						$c       = function_exists( 'node_library_normalize_category' ) ? node_library_normalize_category( $links[ $i ]['category'] ?? '' ) : 'auto';
						$h       = function_exists( 'node_library_normalize_hardware' ) ? node_library_normalize_hardware( $links[ $i ]['hardware'] ?? '' ) : 'auto';
						$hidden  = $i >= $visible_rows ? ' is-hidden' : '';
					?>
						<div class="link-row<?php echo esc_attr( $hidden ); ?>" style="display:flex; gap:10px; margin-bottom:8px; align-items:center; flex-wrap:wrap;">
							<span style="min-width:20px; font-weight:bold; color:#666;"><?php echo $i + 1; ?>.</span>
							<input type="text" name="node_library_links[<?php echo $i; ?>][platform]" value="<?php echo esc_attr( $p ); ?>" placeholder="ストア名 (例: Steam, Nintendo Switch 2)" style="flex:1;">
							<input type="text" name="node_library_links[<?php echo $i; ?>][url]" value="<?php echo esc_url( $u ); ?>" placeholder="https://..." style="flex:2;">
							<select name="node_library_links[<?php echo $i; ?>][category]" class="node-library-link-category" title="表示タブ" style="flex:0 0 auto; min-width:140px;">
								<?php foreach ( $tab_categories as $cat_value => $cat_label ) : ?>
									<option value="<?php echo esc_attr( $cat_value ); ?>" <?php selected( $c, $cat_value ); ?>><?php echo esc_html( $cat_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<select name="node_library_links[<?php echo $i; ?>][hardware]" class="node-library-link-hardware" title="対応ハード" style="flex:0 0 auto; min-width:180px;">
								<?php foreach ( $hardware_options as $hardware_value => $hardware_label ) : ?>
									<option value="<?php echo esc_attr( $hardware_value ); ?>" <?php selected( $h, $hardware_value ); ?>><?php echo esc_html( $hardware_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endfor; ?>
				</div>
				<p style="margin-top:8px;">
					<button type="button" class="button button-secondary" id="node-library-add-link">リンク行を追加</button>
				</p>
				<p class="description">ストア名に応じてボタン色・アイコンが自動選択されます。（例: Steam, PlayStation 5, Xbox Series X|S, Microsoft Store (Windows), Microsoft Store（Xbox）, Nintendo Switch, Nintendo Switch 2, App Store）<br>Nintendo Store / PlayStation Store / Microsoft Store（Xbox）は、機種別の商品ページを別行で登録できます。「表示タブ」と「対応ハード」は必要に応じて手動指定できます。「自動判定」のままならストア名から自動分類します。</p>
			</div>
		</div>
		<?php
	}

	/**
	 * 投稿側選択メタボックス
	 */
	public function render_select_meta_box( $post ): void {
		wp_nonce_field( 'node_post_library_save', 'node_post_library_nonce' );
		$selected_id = get_post_meta( $post->ID, '_node_linked_library_id', true );

		$libraries = get_posts([
			'post_type'      => 'node_library',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC'
		]);

		$preview_summary = '';
		if ( $selected_id ) {
			$preview_summary = (string) get_post_meta( (int) $selected_id, '_node_library_summary', true );
		}
		?>
		<p class="node-library-metabox-help">
			紐付けると、記事フッター（タグの下）にゲーム・アプリカードが<strong>自動表示</strong>されます。<br>
			本文の任意位置に入れたい場合は、ブロック追加 → <strong>Luna</strong> カテゴリ →「ライブラリカード」を使ってください。
		</p>
		<select name="node_linked_library_id" id="node-linked-library-select" style="width:100%; margin-top:8px;">
			<option value="">— 連携しない —</option>
			<?php foreach ( $libraries as $lib ) :
				$lib_summary = (string) get_post_meta( $lib->ID, '_node_library_summary', true );
			?>
				<option value="<?php echo esc_attr( (string) $lib->ID ); ?>" data-summary="<?php echo esc_attr( wp_trim_words( $lib_summary, 40 ) ); ?>" <?php selected( (string) $selected_id, (string) $lib->ID ); ?>>
					<?php echo esc_html( $lib->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( empty( $libraries ) ) : ?>
			<p class="node-library-metabox-help" style="margin-top:10px;">
				ライブラリ項目がまだありません。先に項目を登録してください。
			</p>
		<?php endif; ?>
		<div id="node-library-metabox-preview" class="node-library-metabox-preview" <?php echo $selected_id ? '' : 'style="display:none;"'; ?>>
			<strong>選択中の紹介文:</strong>
			<span id="node-library-metabox-preview-text"><?php echo esc_html( $preview_summary ? wp_trim_words( $preview_summary, 40 ) : '（紹介文なし）' ); ?></span>
		</div>
		<div class="node-library-metabox-links">
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=node_library' ) ); ?>">ライブラリ一覧</a>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=node_library' ) ); ?>">新規項目を追加</a>
		</div>
		<?php
	}

	/**
	 * 保存処理
	 */
	public function save_meta_boxes( int $post_id ): void {
		if ( isset( $_POST['node_library_details_nonce'] ) && wp_verify_nonce( $_POST['node_library_details_nonce'], 'node_library_save_details' ) ) {
			if ( isset( $_POST['node_library_type'] ) ) {
				$type = sanitize_key( wp_unslash( $_POST['node_library_type'] ) );
				update_post_meta( $post_id, '_node_library_type', in_array( $type, [ 'game', 'app' ], true ) ? $type : 'game' );
			}
			if ( isset( $_POST['node_library_summary'] ) ) {
				update_post_meta( $post_id, '_node_library_summary', sanitize_textarea_field( $_POST['node_library_summary'] ) );
			}
			if ( isset( $_POST['node_library_links'] ) ) {
				$links = [];
				foreach ( $_POST['node_library_links'] as $link ) {
					if ( ! empty( $link['platform'] ) && ! empty( $link['url'] ) ) {
						$links[] = [
							'platform' => sanitize_text_field( $link['platform'] ),
							'url'      => esc_url_raw( $link['url'] ),
							'category' => node_library_normalize_category( $link['category'] ?? '' ),
							'hardware' => node_library_normalize_hardware( $link['hardware'] ?? '' ),
						];
					}
				}
				update_post_meta( $post_id, '_node_library_links', $links );
			}
		}

		if ( isset( $_POST['node_post_library_nonce'] ) && wp_verify_nonce( $_POST['node_post_library_nonce'], 'node_post_library_save' ) ) {
			if ( isset( $_POST['node_linked_library_id'] ) ) {
				update_post_meta( $post_id, '_node_linked_library_id', sanitize_text_field( $_POST['node_linked_library_id'] ) );
			}
		}
	}

	/**
	 * Keep the reverse lookup synchronized after posts or Library dependencies change.
	 *
	 * Regular posts replace their own index. Dependency posts are selected through
	 * dedicated metadata so a reusable block or Library change stays incremental.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post        Saved post.
	 * @param bool    $update      Whether this is an existing post update.
	 * @param WP_Post $post_before Post object before the update.
	 * @return void
	 */
	public function synchronize_library_card_references_after_save( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {
		if ( 'post' === $post->post_type ) {
			$this->index_post_library_card_references( $post_id, $post, $update );
			return;
		}

		if ( 'wp_block' === $post->post_type ) {
			$this->synchronize_posts_for_dependency( self::REUSABLE_DEPENDENCY_META, $post_id );
		} elseif ( 'node_library' === $post->post_type ) {
			$this->synchronize_posts_for_dependency( self::LIBRARY_DEPENDENCY_META, $post_id );
		}
	}

	/**
	 * Remove reverse lookup data before a post is permanently deleted.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post being deleted.
	 * @return void
	 */
	public function remove_library_card_references_before_delete( int $post_id, WP_Post $post ): void {
		if ( 'post' === $post->post_type ) {
			delete_post_meta( $post_id, self::CARD_REFERENCE_META );
			delete_post_meta( $post_id, self::LIBRARY_DEPENDENCY_META );
			delete_post_meta( $post_id, self::REUSABLE_DEPENDENCY_META );
		}
	}

	/**
	 * Rebuild referencing posts after a reusable block or Library item is deleted.
	 *
	 * @param int     $post_id Deleted post ID.
	 * @param WP_Post $post    Deleted post object.
	 * @return void
	 */
	public function synchronize_references_after_dependency_delete( int $post_id, WP_Post $post ): void {
		if ( 'wp_block' === $post->post_type ) {
			$this->synchronize_posts_for_dependency( self::REUSABLE_DEPENDENCY_META, $post_id );
		} elseif ( 'node_library' === $post->post_type ) {
			$this->synchronize_posts_for_dependency( self::LIBRARY_DEPENDENCY_META, $post_id );
		}
	}

	/**
	 * Index library-card blocks embedded in a regular post for reverse lookup.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Saved post.
	 * @param bool    $update  Whether this is an existing post update.
	 * @return void
	 */
	public function index_post_library_card_references( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$indexable_statuses = array( 'publish', 'future', 'draft', 'pending', 'private' );
		$reference_data     = in_array( $post->post_status, $indexable_statuses, true )
			? $this->get_library_card_reference_data_from_blocks( parse_blocks( $post->post_content ) )
			: array(
				'library_ids'           => array(),
				'library_dependencies'  => array(),
				'reusable_dependencies' => array(),
			);

		$this->replace_post_meta_ids( $post_id, self::CARD_REFERENCE_META, $reference_data['library_ids'] );
		$this->replace_post_meta_ids( $post_id, self::LIBRARY_DEPENDENCY_META, $reference_data['library_dependencies'] );
		$this->replace_post_meta_ids( $post_id, self::REUSABLE_DEPENDENCY_META, $reference_data['reusable_dependencies'] );
	}

	/**
	 * Replace a multi-value integer meta index only when its normalized value changes.
	 *
	 * @param int             $post_id  Post ID.
	 * @param string          $meta_key Meta key.
	 * @param array<int, int> $ids      Indexed IDs.
	 * @return void
	 */
	private function replace_post_meta_ids( int $post_id, string $meta_key, array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$current_ids = array_values(
			array_unique(
				array_filter( array_map( 'absint', get_post_meta( $post_id, $meta_key, false ) ) )
			)
		);

		sort( $ids, SORT_NUMERIC );
		sort( $current_ids, SORT_NUMERIC );

		if ( $current_ids === $ids ) {
			return;
		}

		delete_post_meta( $post_id, $meta_key );
		foreach ( $ids as $id ) {
			add_post_meta( $post_id, $meta_key, (string) $id, false );
		}
	}

	/**
	 * Build the reverse-lookup index for cards already embedded before this feature.
	 *
	 * @return void
	 */
	public function migrate_library_card_references(): void {
		if ( self::REFERENCE_INDEX_VERSION === get_option( 'node_library_card_reference_index_version' ) ) {
			return;
		}

		$this->synchronize_all_post_library_card_references();
		update_option( 'node_library_card_reference_index_version', self::REFERENCE_INDEX_VERSION, false );
	}

	/**
	 * Re-index only posts that depend on the saved/deleted Library item or reusable block.
	 *
	 * Dependency metadata is kept independently from valid card references, so trashing
	 * and restoring a dependency can both be handled without scanning every post.
	 *
	 * @param string $meta_key      Dependency meta key.
	 * @param int    $dependency_id Library item or reusable block ID.
	 * @return void
	 */
	public function synchronize_posts_for_dependency( string $meta_key, int $dependency_id ): void {
		if (
			$dependency_id <= 0 ||
			! in_array( $meta_key, array( self::LIBRARY_DEPENDENCY_META, self::REUSABLE_DEPENDENCY_META ), true )
		) {
			return;
		}

		$post_ids = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => $meta_key,
				'meta_value'             => (string) $dependency_id,
			)
		);

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				$this->index_post_library_card_references( (int) $post_id, $post, true );
			}
		}
	}

	/**
	 * Reconcile the complete reverse-lookup index for every regular post.
	 *
	 * This repairs data created while the plugin was inactive or before a new
	 * synchronization trigger was introduced. Per-save synchronization remains
	 * incremental after this one-time full pass.
	 *
	 * @return void
	 */
	public function synchronize_all_post_library_card_references(): void {
		$post_statuses = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );

		$post_ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => $post_statuses,
				'posts_per_page' => -1,
				'fields'          => 'ids',
				'no_found_rows'   => true,
			)
		);

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				$this->index_post_library_card_references( (int) $post_id, $post, true );
			}
		}
	}

	/**
	 * Extract Luna Library item IDs from nested block content.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array{library_ids: array<int, int>, library_dependencies: array<int, int>, reusable_dependencies: array<int, int>}
	 */
	private function get_library_card_reference_data_from_blocks( array $blocks, array $visited_reusable_blocks = array() ): array {
		$data = array(
			'library_ids'           => array(),
			'library_dependencies'  => array(),
			'reusable_dependencies' => array(),
		);

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( 'node-library/item-card' === ( $block['blockName'] ?? '' ) ) {
				$library_id = absint( $block['attrs']['libraryId'] ?? 0 );
				if ( $library_id ) {
					$data['library_dependencies'][] = $library_id;
					$library_item = get_post( $library_id );
					if ( $library_item instanceof WP_Post && 'node_library' === $library_item->post_type && 'trash' !== $library_item->post_status ) {
						$data['library_ids'][] = $library_id;
					}
				}
			}

			if ( 'core/block' === ( $block['blockName'] ?? '' ) ) {
				$reusable_block_id = absint( $block['attrs']['ref'] ?? 0 );
				if ( $reusable_block_id ) {
					$data['reusable_dependencies'][] = $reusable_block_id;
					if ( ! in_array( $reusable_block_id, $visited_reusable_blocks, true ) ) {
						$reusable_block = get_post( $reusable_block_id );
						if ( $reusable_block instanceof WP_Post && 'wp_block' === $reusable_block->post_type && 'trash' !== $reusable_block->post_status ) {
							$next_visited = array_merge( $visited_reusable_blocks, array( $reusable_block_id ) );
							$nested_data  = $this->get_library_card_reference_data_from_blocks( parse_blocks( $reusable_block->post_content ), $next_visited );
							foreach ( array_keys( $data ) as $key ) {
								$data[ $key ] = array_merge( $data[ $key ], $nested_data[ $key ] );
							}
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$nested_data = $this->get_library_card_reference_data_from_blocks( $block['innerBlocks'], $visited_reusable_blocks );
				foreach ( array_keys( $data ) as $key ) {
					$data[ $key ] = array_merge( $data[ $key ], $nested_data[ $key ] );
				}
			}
		}

		foreach ( array_keys( $data ) as $key ) {
			$data[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', $data[ $key ] ) ) ) );
		}

		return $data;
	}

	/**
	 * 表示処理 (自動挿入)
	 */
	public function render_library_card_on_post( int $post_id ): void {
		// 自動挿入が無効化されている場合は何もしない
		if ( get_option( 'node_library_auto_insert', '1' ) !== '1' ) {
			return;
		}

		$lib_id = get_post_meta( $post_id, '_node_linked_library_id', true );
		if ( empty( $lib_id ) ) {
			$game_info = get_post_meta( $post_id, '_node_game_info', true );
			if ( is_array( $game_info ) && ! empty( $game_info['title'] ) ) {
				include NODE_LIBRARY_DIR . 'templates/card-library.php';
			}
			return;
		}

		$lib_post = get_post( $lib_id );
		if ( ! $lib_post || $lib_post->post_type !== 'node_library' ) return;

		$game_info = [
			'title'   => $lib_post->post_title,
			'type'    => get_post_meta( $lib_id, '_node_library_type', true ) ?: 'game',
			'summary' => get_post_meta( $lib_id, '_node_library_summary', true ),
			'links'   => get_post_meta( $lib_id, '_node_library_links', true ),
		];

		include NODE_LIBRARY_DIR . 'templates/card-library.php';
	}

}

/**
 * 一覧の分類フィルター初期値。既存タブ分類を起点にし、後続の条件はフィルターで足す。
 *
 * @return array<string,string>
 */
function node_library_archive_tab_filters(): array {
	$tabs = node_library_tab_categories();
	$filtered = apply_filters( 'node_library_archive_tab_filters', $tabs );
	if ( ! is_array( $filtered ) ) {
		return $tabs;
	}

	$clean = [];
	foreach ( $filtered as $key => $label ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key || ! is_string( $label ) || '' === trim( $label ) ) {
			continue;
		}
		$clean[ $key ] = $label;
	}

	return $clean ? $clean : $tabs;
}

/**
 * 一覧の並び順。発売日の実値は node_library_archive_release_date_meta_key が指すメタ。
 *
 * @return array<string,string>
 */
function node_library_archive_sort_options(): array {
	$options = [
		''      => '標準',
		'date'  => '発売日',
		'title' => 'タイトル',
	];
	$filtered = apply_filters( 'node_library_archive_sort_options', $options );
	if ( ! is_array( $filtered ) ) {
		return $options;
	}

	$clean = [];
	foreach ( $filtered as $key => $label ) {
		$key = sanitize_key( (string) $key );
		if ( ! is_string( $label ) || '' === trim( $label ) ) {
			continue;
		}
		$clean[ $key ] = $label;
	}

	return isset( $clean[''] ) ? $clean : $options;
}

/**
 * 発売日ソートが読むメタキー。未保存の項目は除外せず末尾に回す。
 * Task L1 の `_node_library_release_date` を既定にし、キー自体はここでは保存しない。
 */
function node_library_archive_release_date_meta_key(): string {
	$key = apply_filters( 'node_library_archive_release_date_meta_key', '_node_library_release_date' );
	return is_string( $key ) ? sanitize_key( $key ) : '';
}

/**
 * リクエストから一覧ツールバーの状態を読む。
 *
 * @param WP_Query|null $query Query whose vars should win over the global request.
 * @return array{q:string,tab:string,sort:string,dir:string}
 */
function node_library_archive_toolbar_state( ?WP_Query $query = null ): array {
	$tabs  = node_library_archive_tab_filters();
	$sorts = node_library_archive_sort_options();
	$q     = node_library_archive_request_text( 'q', $query );
	$q     = trim( (string) preg_replace( '/\s+/u', ' ', $q ) );
	if ( function_exists( 'mb_substr' ) ) {
		$q = mb_substr( $q, 0, 80 );
	} else {
		$q = substr( $q, 0, 80 );
	}

	$tab  = sanitize_key( node_library_archive_request_text( 'tab', $query ) );
	$sort = sanitize_key( node_library_archive_request_text( 'sort', $query ) );
	$dir  = sanitize_key( node_library_archive_request_text( 'dir', $query ) );
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = '';
	}
	if ( '' !== $sort && ! isset( $sorts[ $sort ] ) ) {
		$sort = '';
	}
	if ( ! in_array( $dir, [ 'asc', 'desc' ], true ) ) {
		$dir = '';
	}

	return [
		'q'    => $q,
		'tab'  => $tab,
		'sort' => $sort,
		'dir'  => $dir,
	];
}

/**
 * 公開クエリ変数、なければ GET から文字列を読む。
 *
 * @param WP_Query|null $query Query to read before the global request.
 */
function node_library_archive_request_text( string $key, ?WP_Query $query = null ): string {
	$value = $query instanceof WP_Query ? $query->get( $key ) : get_query_var( $key );
	if ( ! is_string( $value ) || '' === $value ) {
		if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_GET[ $key ] );
			$value = is_string( $raw ) ? $raw : '';
		}
	}

	$value = is_string( $value ) ? sanitize_text_field( $value ) : '';
	return $value;
}

/**
 * 並び方向。タイトルの初期値は昇順、発売日の初期値は降順。
 */
function node_library_archive_effective_dir( string $sort, string $dir ): string {
	if ( in_array( $dir, [ 'asc', 'desc' ], true ) ) {
		return $dir;
	}

	return 'title' === $sort ? 'asc' : 'desc';
}

/**
 * ページ送りや種類タブに、今の検索条件だけを足す。
 */
function node_library_archive_toolbar_query_args(): array {
	$state = node_library_archive_toolbar_state();
	$args  = [];
	if ( '' !== $state['q'] ) {
		$args['q'] = $state['q'];
	}
	if ( '' !== $state['tab'] ) {
		$args['tab'] = $state['tab'];
	}
	if ( '' !== $state['sort'] ) {
		$args['sort'] = $state['sort'];
		$args['dir']  = node_library_archive_effective_dir( $state['sort'], $state['dir'] );
	}

	/**
	 * 後続の条件（価格帯など）をページ送りとタブのリンクへ引き継ぐ。
	 *
	 * @param array<string,string>         $args  引き継ぐクエリ引数。
	 * @param array{q:string,tab:string,sort:string,dir:string} $state ツールバーの状態。
	 */
	$filtered = apply_filters( 'node_library_archive_toolbar_query_args', $args, $state );
	if ( ! is_array( $filtered ) ) {
		return $args;
	}

	$clean = [];
	foreach ( $filtered as $key => $value ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key || is_array( $value ) || is_object( $value ) ) {
			continue;
		}
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			continue;
		}
		$clean[ $key ] = $value;
	}

	return $clean;
}

/**
 * @param string $url Archive or filter URL.
 */
function node_library_archive_with_toolbar_args( string $url ): string {
	$args = node_library_archive_toolbar_query_args();
	if ( ! $args ) {
		return $url;
	}

	return add_query_arg( $args, $url );
}

/**
 * ストアリンクの保存分類。auto のままなら自動判定結果を返す。
 *
 * @param array<string,mixed> $link Link row.
 */
function node_library_link_effective_tab( array $link ): string {
	$stored = node_library_normalize_category( $link['category'] ?? '' );
	if ( 'auto' !== $stored ) {
		return $stored;
	}

	return node_library_auto_category( (string) ( $link['platform'] ?? '' ) );
}

/**
 * 項目が分類タブに合うか。未知の分類は node_library_archive_item_matches_tab に委譲する。
 *
 * @param mixed $links Stored links.
 */
function node_library_item_matches_archive_tab( int $post_id, string $tab, $links ): bool {
	if ( ! is_array( $links ) ) {
		$links = [];
	}

	$matched = apply_filters( 'node_library_archive_item_matches_tab', null, $post_id, $tab, $links );
	if ( null !== $matched ) {
		return (bool) $matched;
	}

	foreach ( $links as $link ) {
		if ( ! is_array( $link ) ) {
			continue;
		}
		$stored = node_library_normalize_category( $link['category'] ?? '' );
		if ( 'auto' === $tab ) {
			if ( 'auto' === $stored ) {
				return true;
			}
			continue;
		}
		if ( node_library_link_effective_tab( $link ) === $tab ) {
			return true;
		}
	}

	return false;
}

/**
 * ライブラリ一覧の検索・分類・ソート。条件が無いときはクエリを変えない。
 *
 * @param WP_Query $query Main query.
 */
function node_library_filter_archive_query( $query ): void {
	if ( ! $query instanceof WP_Query || is_admin() || ! $query->is_main_query() ) {
		return;
	}

	// 個別ページは post_type が node_library でもアーカイブではない。?q= / ?tab= で 404 にしない。
	if ( $query->is_singular() || ! $query->is_post_type_archive( 'node_library' ) ) {
		return;
	}

	$state         = node_library_archive_toolbar_state( $query );
	$has_id_filter = has_filter( 'node_library_archive_filter_post_ids' );
	if ( '' === $state['q'] && '' === $state['tab'] && '' === $state['sort'] && ! $has_id_filter ) {
		return;
	}

	if ( '' !== $state['q'] ) {
		$query->set( 'node_library_keyword', $state['q'] );
	}

	if ( '' !== $state['tab'] || $has_id_filter ) {
		$ids = get_posts(
			[
				'post_type'              => 'node_library',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			]
		);
		$ids = array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : [] ) ) );

		/**
		 * 別テーブル参照の絞り込みを、項目ごとの問い合わせなしで一括適用する。
		 *
		 * @param array<int,int> $ids   候補の投稿 ID。
		 * @param array{q:string,tab:string,sort:string,dir:string} $state ツールバーの状態。
		 */
		$filtered_ids = apply_filters( 'node_library_archive_filter_post_ids', $ids, $state );
		if ( ! is_array( $filtered_ids ) ) {
			$filtered_ids = [];
		}
		$ids = [];
		foreach ( $filtered_ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id > 0 ) {
				$ids[] = $post_id;
			}
		}
		$ids = array_values( array_unique( $ids ) );

		if ( '' !== $state['tab'] ) {
			if ( $ids ) {
				update_meta_cache( 'post', $ids );
			}

			$matched = [];
			foreach ( $ids as $post_id ) {
				$links = get_post_meta( $post_id, '_node_library_links', true );
				if ( node_library_item_matches_archive_tab( $post_id, $state['tab'], $links ) ) {
					$matched[] = $post_id;
				}
			}
			$ids = $matched;
		}

		$query->set( 'post__in', $ids ? $ids : [ 0 ] );
	}

	if ( '' === $state['sort'] ) {
		return;
	}

	$dir = node_library_archive_effective_dir( $state['sort'], $state['dir'] );
	$handled = apply_filters( 'node_library_archive_apply_sort', false, $query, $state['sort'], $dir );
	if ( $handled ) {
		return;
	}

	if ( 'title' === $state['sort'] ) {
		$query->set( 'orderby', 'title' );
		$query->set( 'order', strtoupper( $dir ) );
		return;
	}

	if ( 'date' === $state['sort'] ) {
		$query->set( 'node_library_sort', 'date' );
		$query->set( 'node_library_sort_dir', $dir );
		if ( '' === node_library_archive_release_date_meta_key() ) {
			$query->set( 'orderby', 'date' );
			$query->set( 'order', strtoupper( $dir ) );
		}
	}
}

/**
 * タイトルと既存の紹介文（_node_library_summary）だけをキーワード検索する。
 *
 * @param string   $where SQL WHERE.
 * @param WP_Query $query Query.
 */
function node_library_archive_posts_where( $where, $query ): string {
	if ( ! $query instanceof WP_Query ) {
		return $where;
	}

	$keyword = (string) $query->get( 'node_library_keyword' );
	if ( '' === $keyword ) {
		return $where;
	}

	global $wpdb;
	$like   = '%' . $wpdb->esc_like( $keyword ) . '%';
	$where .= $wpdb->prepare(
		" AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_node_library_summary' AND meta_value LIKE %s ) )",
		$like,
		$like
	);

	return $where;
}

/**
 * 発売日メタが無い項目も残し、空の日付は末尾へ寄せる。
 *
 * @param array<string,string> $clauses SQL clauses.
 * @param WP_Query             $query   Query.
 * @return array<string,string>
 */
function node_library_archive_posts_clauses( $clauses, $query ): array {
	if ( ! $query instanceof WP_Query || 'date' !== $query->get( 'node_library_sort' ) ) {
		return $clauses;
	}

	$meta_key = node_library_archive_release_date_meta_key();
	if ( '' === $meta_key ) {
		return $clauses;
	}

	global $wpdb;
	$dir = 'ASC' === strtoupper( (string) $query->get( 'node_library_sort_dir' ) ) ? 'ASC' : 'DESC';
	$clauses['distinct'] = 'DISTINCT';
	$clauses['join'] .= $wpdb->prepare(
		" LEFT JOIN {$wpdb->postmeta} AS node_library_release_sort ON ( {$wpdb->posts}.ID = node_library_release_sort.post_id AND node_library_release_sort.meta_key = %s ) ",
		$meta_key
	);
	$clauses['orderby'] = "CASE WHEN node_library_release_sort.meta_value IS NULL OR node_library_release_sort.meta_value = '' THEN 1 ELSE 0 END ASC, node_library_release_sort.meta_value {$dir}, {$wpdb->posts}.post_date DESC";

	return $clauses;
}

/**
 * 一覧の検索・分類・ソート。カードの中身は変えない。
 */
function node_library_render_archive_toolbar(): void {
	$state   = node_library_archive_toolbar_state();
	$tabs    = node_library_archive_tab_filters();
	$sorts   = node_library_archive_sort_options();
	$archive = get_post_type_archive_link( 'node_library' );
	if ( ! is_string( $archive ) || '' === $archive ) {
		return;
	}

	$type = sanitize_key( (string) get_query_var( 'node_library_type' ) );
	if ( '' === $type && isset( $_GET['type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_raw = wp_unslash( $_GET['type'] );
		$type     = sanitize_key( is_string( $type_raw ) ? $type_raw : '' );
	}
	$action = in_array( $type, [ 'game', 'app' ], true ) ? trailingslashit( $archive . $type ) : $archive;
	$dir         = node_library_archive_effective_dir( $state['sort'], $state['dir'] );
	$public_tabs = $tabs;
	unset( $public_tabs['auto'] );
	?>
	<form id="node-library-tools" class="node-library-tools" method="get" action="<?php echo esc_url( $action ); ?>" role="search">
		<label class="node-library-tools__field node-library-tools__field--keyword">
			<span><?php esc_html_e( 'キーワード', 'node' ); ?></span>
			<input class="node-library-tools__control" type="search" name="q" maxlength="80" value="<?php echo esc_attr( $state['q'] ); ?>" placeholder="<?php esc_attr_e( 'タイトル・紹介文', 'node' ); ?>">
		</label>
		<label class="node-library-tools__field">
			<span><?php esc_html_e( '分類', 'node' ); ?></span>
			<select class="node-library-tools__control" name="tab">
				<option value=""><?php esc_html_e( 'すべて', 'node' ); ?></option>
				<?php foreach ( $public_tabs as $tab_value => $tab_label ) : ?>
					<option value="<?php echo esc_attr( $tab_value ); ?>" <?php selected( $state['tab'], $tab_value ); ?>><?php echo esc_html( $tab_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="node-library-tools__field">
			<span><span class="material-symbols-outlined" data-icon="sort" aria-hidden="true"></span><?php esc_html_e( '並び順', 'node' ); ?></span>
			<select class="node-library-tools__control" name="sort">
				<?php foreach ( $sorts as $sort_value => $sort_label ) : ?>
					<option value="<?php echo esc_attr( $sort_value ); ?>" <?php selected( $state['sort'], $sort_value ); ?>><?php echo esc_html( $sort_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="node-library-tools__field">
			<span><?php esc_html_e( '順序', 'node' ); ?></span>
			<select class="node-library-tools__control" name="dir">
				<option value="desc" <?php selected( $dir, 'desc' ); ?>><?php esc_html_e( '降順', 'node' ); ?></option>
				<option value="asc" <?php selected( $dir, 'asc' ); ?>><?php esc_html_e( '昇順', 'node' ); ?></option>
			</select>
		</label>
		<button class="node-library-tools__submit" type="submit">
			<span class="material-symbols-outlined" data-icon="search" aria-hidden="true"></span>
			<?php esc_html_e( '検索', 'node' ); ?>
		</button>
		<?php
		/**
		 * フォーム内に後続の条件フィールドを足す。表示だけの追加は node_library_archive_toolbar_after。
		 *
		 * @param array{q:string,tab:string,sort:string,dir:string} $state ツールバーの状態。
		 */
		do_action( 'node_library_archive_toolbar_fields', $state );
		?>
	</form>
	<?php
	do_action( 'node_library_archive_toolbar_after', $state );
}

/**
 * 詳細ページのスペック表。保存済みの種別・紹介文・対応機種・入手先だけを返す。
 *
 * @return array<int, array{label:string,value:string,links?:array<int,array{platform:string,url:string}>}>
 */
function node_library_get_spec_rows( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'node_library' !== $post->post_type ) {
		return [];
	}

	$type = get_post_meta( $post_id, '_node_library_type', true ) === 'app' ? 'app' : 'game';
	$rows = [
		[
			'label' => '種別',
			'value' => 'app' === $type ? 'アプリ' : 'ゲーム',
		],
	];

	$summary = trim( (string) get_post_meta( $post_id, '_node_library_summary', true ) );
	if ( '' !== $summary ) {
		$rows[] = [
			'label' => '紹介文',
			'value' => $summary,
		];
	}

	$hardware = node_library_spec_hardware_labels( $post_id );
	if ( $hardware ) {
		$rows[] = [
			'label' => '対応機種',
			'value' => implode( '、', $hardware ),
		];
	}

	$links = node_library_spec_store_links( $post_id );
	if ( $links ) {
		$rows[] = [
			'label' => '入手先',
			'value' => '',
			'links' => $links,
		];
	}

	$filtered = apply_filters( 'node_library_spec_rows', $rows, $post_id );
	return is_array( $filtered ) ? $filtered : $rows;
}

/**
 * リンクに保存された対応ハードを、既存の表示名へ揃える。
 *
 * @return array<int, string>
 */
function node_library_spec_hardware_labels( int $post_id ): array {
	$links   = get_post_meta( $post_id, '_node_library_links', true );
	$options = node_library_hardware_options();
	$labels  = [];
	if ( ! is_array( $links ) ) {
		return [];
	}

	foreach ( $links as $link ) {
		if ( ! is_array( $link ) ) {
			continue;
		}
		$hardware = node_library_normalize_hardware( $link['hardware'] ?? '' );
		if ( 'auto' === $hardware ) {
			$hardware = node_library_infer_hardware_from_platform( (string) ( $link['platform'] ?? '' ) );
		}
		if ( ! isset( $options[ $hardware ] ) || 'auto' === $hardware ) {
			continue;
		}
		$label = $options[ $hardware ];
		if ( ! in_array( $label, $labels, true ) ) {
			$labels[] = $label;
		}
	}

	return $labels;
}

/**
 * スペック表と JSON-LD が共有する入手先。
 *
 * @return array<int, array{platform:string,url:string}>
 */
function node_library_spec_store_links( int $post_id ): array {
	$stored = get_post_meta( $post_id, '_node_library_links', true );
	$links  = [];
	if ( ! is_array( $stored ) ) {
		return [];
	}

	foreach ( $stored as $link ) {
		if ( ! is_array( $link ) ) {
			continue;
		}
		$platform = sanitize_text_field( (string) ( $link['platform'] ?? '' ) );
		$url      = esc_url_raw( (string) ( $link['url'] ?? '' ) );
		if ( '' === $platform || '' === $url ) {
			continue;
		}
		$links[] = [
			'platform' => $platform,
			'url'      => $url,
		];
	}

	return $links;
}

/**
 * schema.org/Product の土台。Offer は node_library_product_jsonld_offers が配列を返したときだけ入る。
 *
 * @return array<string, mixed>
 */
function node_library_get_product_jsonld( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'node_library' !== $post->post_type ) {
		return [];
	}

	$type    = get_post_meta( $post_id, '_node_library_type', true ) === 'app' ? 'app' : 'game';
	$summary = trim( (string) get_post_meta( $post_id, '_node_library_summary', true ) );
	$data    = [
		'@context' => 'https://schema.org',
		'@type'    => 'Product',
		'name'     => node_library_jsonld_text( get_the_title( $post ) ),
		'url'      => get_permalink( $post ),
		'category' => 'app' === $type ? 'アプリ' : 'ゲーム',
	];
	if ( '' !== $summary ) {
		$data['description'] = node_library_jsonld_text( $summary );
	}

	$hardware = node_library_spec_hardware_labels( $post_id );
	if ( $hardware ) {
		$data['additionalProperty'] = [
			[
				'@type' => 'PropertyValue',
				'name'  => '対応機種',
				'value' => implode( '、', $hardware ),
			],
		];
	}

	$same_as = array_values( array_unique( array_column( node_library_spec_store_links( $post_id ), 'url' ) ) );
	if ( $same_as ) {
		$data['sameAs'] = $same_as;
	}

	$offers = apply_filters( 'node_library_product_jsonld_offers', null, $post_id );
	if ( is_array( $offers ) && $offers ) {
		$data['offers'] = $offers;
	}

	$filtered = apply_filters( 'node_library_product_jsonld', $data, $post_id );
	return is_array( $filtered ) ? $filtered : $data;
}

/**
 * 詳細のスペック表。記事内カードのマークアップは変えない。
 */
function node_library_render_spec_table( int $post_id ): void {
	$rows = node_library_get_spec_rows( $post_id );
	if ( ! $rows ) {
		return;
	}
	?>
	<section class="node-library-single__specs" aria-labelledby="node-library-specs-title">
		<div class="node-library-single__section-heading">
			<span class="material-symbols-outlined" data-icon="info" aria-hidden="true"></span>
			<h2 id="node-library-specs-title"><?php esc_html_e( '仕様', 'node' ); ?></h2>
		</div>
		<table class="node-library-specs">
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				if ( ! is_array( $row ) ) {
					continue;
				}
				$links = ( isset( $row['links'] ) && is_array( $row['links'] ) ) ? $row['links'] : [];
				$value = trim( (string) ( $row['value'] ?? '' ) );
				if ( '' === $value && ! $links ) {
					continue;
				}
				?>
				<tr>
					<th scope="row"><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></th>
					<td>
						<?php if ( $links ) : ?>
							<ul class="node-library-specs__links">
								<?php foreach ( $links as $link ) : ?>
									<?php
									if ( ! is_array( $link ) ) {
										continue;
									}
									$url = esc_url( (string) ( $link['url'] ?? '' ) );
									if ( '' === $url ) {
										continue;
									}
									?>
									<li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) ( $link['platform'] ?? $url ) ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<span class="node-library-specs__text"><?php echo esc_html( $value ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php
	do_action( 'node_library_single_after_specs', $post_id );
}

/**
 * Product の JSON-LD を出力する。価格の Offer はフィルターが返すまで出さない。
 */
function node_library_render_product_jsonld( int $post_id ): void {
	$data = node_library_get_product_jsonld( $post_id );
	if ( ! $data ) {
		return;
	}

	echo '<script type="application/ld+json">';
	echo wp_json_encode(
		$data,
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
	);
	echo '</script>';
}

/**
 * JSON-LD に入れる前に HTML エンティティを文字へ戻す。
 */
function node_library_jsonld_text( string $text ): string {
	return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * 起動
 */
function node_library_init() {
	Node_Library::instance();
}
require_once NODE_LIBRARY_DIR . 'includes/class-library-structured-meta.php';
Node_Library_Structured_Meta::init();
require_once NODE_LIBRARY_DIR . 'includes/class-library-store-id-migration.php';
require_once NODE_LIBRARY_DIR . 'includes/class-library-store.php';
Node_Library_Store::init();
require_once NODE_LIBRARY_DIR . 'includes/class-library-prices.php';
Node_Library_Prices::init();
require_once NODE_LIBRARY_DIR . 'includes/class-library-archive-filters.php';
Node_Library_Archive_Filters::init();
require_once NODE_LIBRARY_DIR . 'includes/class-library-single-extensions.php';
Node_Library_Single_Extensions::init();

add_action( 'plugins_loaded', 'node_library_init' );

/**
 * 無効化時に自前の定期実行を掃除する（ハンドラのないイベントを残さない）。
 */
function node_library_clear_scheduled_events(): void {
	if ( class_exists( 'Node_Library_Prices' ) ) {
		Node_Library_Prices::unschedule();
	}
}
register_deactivation_hook( __FILE__, 'node_library_clear_scheduled_events' );

} // End Library bootstrap guard.
