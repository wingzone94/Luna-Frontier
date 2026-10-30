<?php
/**
 * 広告枠の判定と出力。
 *
 * @package Luna_Ads_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 広告を出してよいかの判定と、実際のマークアップ生成を担当する。
 *
 * 出力しない条件を先に確定させ、空のプレースホルダーは一切出さない。
 */
final class Luna_Ads_Renderer {

	/**
	 * 同一リクエスト内で出力済みのスロット。
	 *
	 * @var array<string,bool>
	 */
	private static $rendered = array();

	/**
	 * アーカイブのループ内で数えた投稿数。
	 *
	 * @var int
	 */
	private static $loop_index = 0;

	/**
	 * AdSense ローダーを出力済みか。
	 *
	 * @var bool
	 */
	private static $loader_printed = false;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_the_content' ), 8 );
		add_filter( 'the_content', array( __CLASS__, 'append_bottom_ad' ), 1000 );
		add_action( 'loop_start', array( __CLASS__, 'reset_loop' ) );
		add_action( 'the_post', array( __CLASS__, 'maybe_output_infeed' ) );
		add_action( 'wp_head', array( __CLASS__, 'maybe_output_adsense_loader' ), 1 );
	}

	/**
	 * テスト用にリクエスト内の状態を初期化する。
	 *
	 * @return void
	 */
	public static function reset_state() {
		self::$rendered       = array();
		self::$loop_index     = 0;
		self::$loader_printed = false;
	}

	/**
	 * ループ開始時にカウンタを戻す。
	 *
	 * @return void
	 */
	public static function reset_loop() {
		self::$loop_index = 0;
	}

	/**
	 * 広告設定が有効で、プラグインが手動広告を出力する構成か。
	 *
	 * @param array<string,mixed>|null $settings 設定。省略時は保存値。
	 * @return bool
	 */
	public static function manual_output_allowed( $settings = null ) {
		$settings = null === $settings ? Luna_Ads_Settings::get() : $settings;

		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		if ( 'plugin' !== $settings['code_source'] ) {
			return false;
		}
		return in_array( $settings['delivery'], array( 'manual', 'manual_auto' ), true );
	}

	/**
	 * 指定スロットが有効でコードが入っているか。
	 *
	 * @param string $slot スロット名。
	 * @return bool
	 */
	public static function slot_has_code( $slot ) {
		$config = Luna_Ads_Settings::slot( $slot );
		return ! empty( $config['enabled'] ) && '' !== trim( (string) ( $config['code'] ?? '' ) );
	}

	/**
	 * 広告を出力し得るスロットが1つでもあるか。
	 *
	 * @param array<string,mixed>|null $settings 設定。
	 * @return bool
	 */
	public static function has_active_slot( $settings = null ) {
		$settings = null === $settings ? Luna_Ads_Settings::get() : $settings;
		foreach ( array_keys( $settings['slots'] ) as $slot ) {
			$config = $settings['slots'][ $slot ];
			if ( ! empty( $config['enabled'] ) && '' !== trim( (string) $config['code'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * フロント側CSSが必要か。広告が無効なサイトでは読み込まない。
	 *
	 * @return bool
	 */
	public static function needs_public_styles() {
		$settings = Luna_Ads_Settings::get();
		return self::manual_output_allowed( $settings ) && self::has_active_slot( $settings );
	}

	/**
	 * 本文への広告挿入・記事末尾への追加。
	 *
	 * @param string $content 本文。
	 * @return string
	 */
	public static function filter_the_content( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}
		if ( ! self::is_article_content_context() ) {
			return $content;
		}

		$settings = Luna_Ads_Settings::get();
		if ( ! self::manual_output_allowed( $settings ) ) {
			return $content;
		}

		$post_id = (int) get_the_ID();
		if ( ! self::post_allows_ads( $post_id ) ) {
			return $content;
		}

		$inserted_in_article = false;

		if ( self::slot_has_code( 'in_article' ) && false === strpos( $content, 'data-luna-ad="in_article"' ) ) {
			$ad = self::build_slot( 'in_article', false, false );
			if ( '' !== $ad ) {
				$config    = $settings['slots']['in_article'];
				$paragraph = isset( $config['paragraph'] ) ? max( 1, (int) $config['paragraph'] ) : 3;
				$with_ad   = self::inject_after_paragraph( $content, $ad, $paragraph );
				if ( null !== $with_ad ) {
					$content = $with_ad;
				} elseif ( ! self::slot_has_code( 'article_bottom' ) ) {
					// 指定段落が無い場合は末尾へ回す。末尾広告と重ならないよう、
					// 末尾広告が有効なときは本文中広告を諦める。
					$content = $content . "\n" . $ad;
				}
			}
		}

		return $content;
	}

	/**
	 * 記事末尾の広告。脚注など他の末尾処理より後に追加する。
	 *
	 * @param string $content 本文。
	 * @return string
	 */
	public static function append_bottom_ad( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}
		if ( ! self::is_article_content_context() ) {
			return $content;
		}

		$settings = Luna_Ads_Settings::get();
		if ( ! self::manual_output_allowed( $settings ) ) {
			return $content;
		}
		if ( ! self::post_allows_ads( (int) get_the_ID() ) ) {
			return $content;
		}
		if ( ! self::slot_has_code( 'article_bottom' ) ) {
			return $content;
		}
		if ( false !== strpos( $content, 'data-luna-ad="article_bottom"' ) ) {
			return $content;
		}
		if ( self::theme_owns_slot( 'article_bottom' ) ) {
			return $content;
		}

		$ad = self::build_slot( 'article_bottom', false, false );
		return '' !== $ad ? $content . "\n" . $ad : $content;
	}

	/**
	 * 本文中広告を挿入してよい文脈か。
	 *
	 * @return bool
	 */
	public static function is_article_content_context() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( ! is_singular() || ! is_main_query() ) {
			return false;
		}

		$post_types = apply_filters( 'luna_ads_content_post_types', array( 'post' ) );
		return in_array( get_post_type(), (array) $post_types, true );
	}

	/**
	 * 記事単位・全体設定の除外判定。
	 *
	 * @param int $post_id 投稿ID。0 は投稿コンテキスト外。
	 * @return bool
	 */
	public static function post_allows_ads( $post_id ) {
		$post_id = (int) $post_id;
		$allowed = true;

		if ( $post_id > 0 ) {
			if ( '1' === (string) get_post_meta( $post_id, '_luna_ads_disabled', true ) ) {
				$allowed = false;
			}

			$settings = Luna_Ads_Settings::get();
			$excluded = self::parse_ids( $settings['exclude']['post_ids'] );
			if ( in_array( $post_id, $excluded, true ) ) {
				$allowed = false;
			}

			$excluded_categories = self::parse_ids( $settings['exclude']['category_ids'] );
			if ( $allowed && ! empty( $excluded_categories ) && function_exists( 'has_category' ) ) {
				if ( has_category( $excluded_categories, $post_id ) ) {
					$allowed = false;
				}
			}
		}

		if ( $allowed && ! empty( Luna_Ads_Settings::get()['exclude']['logged_in'] ) && is_user_logged_in() ) {
			$allowed = false;
		}

		/**
		 * 記事に広告を出してよいか。
		 *
		 * @param bool $allowed 判定結果。
		 * @param int  $post_id 投稿ID。
		 */
		return (bool) apply_filters( 'luna_ads_post_allows_ads', $allowed, $post_id );
	}

	/**
	 * スロットのマークアップを返す。無効・未設定・重複時は空文字。
	 *
	 * @param string $slot スロット名。
	 * @return string
	 */
	public static function get_slot_html( $slot ) {
		$slot = self::normalize_slot( $slot );
		if ( '' === $slot ) {
			return '';
		}

		$settings = Luna_Ads_Settings::get();
		if ( ! self::manual_output_allowed( $settings ) ) {
			return '';
		}
		if ( self::is_rendered( $slot ) ) {
			return '';
		}
		if ( ! self::post_allows_ads( (int) get_the_ID() ) ) {
			return '';
		}
		if ( self::theme_owns_slot( $slot ) ) {
			return '';
		}
		if ( ! self::slot_has_code( $slot ) ) {
			return '';
		}

		return self::build_slot( $slot );
	}

	/**
	 * ショートコード `[luna_ad slot="sidebar"]`。
	 *
	 * @param array<string,mixed>|string $atts 属性。
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array( 'slot' => 'sidebar' ),
			$atts,
			'luna_ad'
		);

		return self::get_slot_html( (string) $atts['slot'] );
	}

	/**
	 * アーカイブ・検索結果のインフィード広告。
	 *
	 * `the_post` は各カードの直前に発火するため、直前のカードを閉じた後・
	 * 次のカードを開く前という「カードとカードの間」に出力できる。
	 *
	 * @param mixed $post 現在の投稿。
	 * @return void
	 */
	public static function maybe_output_infeed( $post = null ) {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! is_main_query() || ! ( is_home() || is_archive() || is_search() ) ) {
			return;
		}

		$settings = Luna_Ads_Settings::get();
		if ( ! self::manual_output_allowed( $settings ) ) {
			return;
		}
		if ( ! self::slot_has_code( 'infeed' ) || self::theme_owns_slot( 'infeed' ) ) {
			return;
		}
		if ( $post instanceof WP_Post ) {
			if ( ! self::post_allows_ads( (int) $post->ID ) ) {
				return;
			}
		}

		self::$loop_index++;

		$every = isset( $settings['slots']['infeed']['every'] ) ? max( 1, (int) $settings['slots']['infeed']['every'] ) : 3;
		if ( 0 !== self::$loop_index % $every ) {
			return;
		}

		$markup = self::build_slot( 'infeed', true, false );
		if ( '' !== $markup ) {
			echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 管理者が設定した広告コード。
		}
	}

	/**
	 * 広告コード内に AdSense ローダーが含まれていなければ、一度だけ head に出力する。
	 *
	 * Site Kit など既存の管理元がある場合は出力しない（二重読み込み防止）。
	 *
	 * @return void
	 */
	public static function maybe_output_adsense_loader() {
		if ( self::$loader_printed ) {
			return;
		}

		$settings = Luna_Ads_Settings::get();
		if ( empty( $settings['enabled'] ) || 'plugin' !== $settings['code_source'] ) {
			return;
		}
		if ( '' === (string) $settings['client_id'] ) {
			return;
		}
		if ( self::site_kit_active() ) {
			return;
		}
		if ( self::codes_include_adsense_loader( $settings ) ) {
			return;
		}

		/**
		 * AdSense ローダーをプラグインから出力するか。
		 *
		 * @param bool                $output   出力するか。
		 * @param array<string,mixed> $settings 設定。
		 */
		if ( ! apply_filters( 'luna_ads_output_adsense_loader', true, $settings ) ) {
			return;
		}

		self::$loader_printed = true;

		printf(
			'<script async src="%s" crossorigin="anonymous"></script>' . "\n",
			esc_url( 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . rawurlencode( $settings['client_id'] ) )
		);
	}

	/**
	 * Site Kit が有効か。
	 *
	 * @return bool
	 */
	public static function site_kit_active() {
		$active = defined( 'GOOGLESITEKIT_VERSION' ) || class_exists( 'Google\\Site_Kit\\Plugin' );

		/**
		 * Site Kit 検出結果を上書きする。
		 *
		 * @param bool $active 有効か。
		 */
		return (bool) apply_filters( 'luna_ads_site_kit_active', $active );
	}

	/**
	 * テーマ側が同じ位置の広告を管理しているか。
	 *
	 * 記事末尾は `node_the_ad_template( 'article' )`、インフィードは
	 * `template-parts/ad-infeed.php` / `node_ad_code_infeed` が対応する。
	 * 中身がある場合だけ「テーマが管理している」と判定する。
	 *
	 * @param string $slot スロット名。
	 * @return bool
	 */
	public static function theme_owns_slot( $slot ) {
		$theme_slot = '';
		if ( 'article_bottom' === $slot ) {
			$theme_slot = 'article';
		} elseif ( 'infeed' === $slot ) {
			$theme_slot = 'infeed';
		}

		if ( '' === $theme_slot ) {
			return false;
		}

		/**
		 * テーマ側の広告設定を優先するか（二重挿入防止）。
		 *
		 * @param bool   $defer      優先するか。
		 * @param string $slot       スロット名。
		 * @param string $theme_slot テーマ側のスロット名。
		 */
		if ( ! apply_filters( 'luna_ads_defer_to_theme', true, $slot, $theme_slot ) ) {
			return false;
		}

		$theme_code = trim( (string) get_option( 'node_ad_code_' . $theme_slot, '' ) );
		if ( '' !== $theme_code ) {
			return true;
		}

		if ( ! function_exists( 'locate_template' ) ) {
			return false;
		}

		$file = locate_template( array( 'template-parts/ad-' . $theme_slot . '.php' ) );
		if ( ! is_string( $file ) || '' === $file || ! is_readable( $file ) ) {
			return false;
		}

		$markup = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ローカルテンプレートの読み取り。
		$markup = (string) preg_replace( '/<!--.*?-->/s', '', $markup );

		return (bool) preg_match( '/<(ins|iframe|img|script|amp-ad)\b/i', $markup );
	}

	/**
	 * スロット名を正規化する（ハイフン許可・未知の名前は空）。
	 *
	 * @param string $slot スロット名。
	 * @return string
	 */
	public static function normalize_slot( $slot ) {
		$slot = str_replace( '-', '_', strtolower( trim( (string) $slot ) ) );
		$map  = array(
			'in_article'     => 'in_article',
			'article_bottom' => 'article_bottom',
			'infeed'         => 'infeed',
			'sidebar'        => 'sidebar',
		);
		return isset( $map[ $slot ] ) ? $map[ $slot ] : '';
	}

	/**
	 * スロットのラッパーを生成する。
	 *
	 * @param string $slot       スロット名。
	 * @param bool   $repeatable 同じスロットを複数回出せるか（インフィード用）。
	 * @param bool   $track      リクエスト内の出力済みフラグを立てるか。
	 * @return string
	 */
	private static function build_slot( $slot, $repeatable = false, $track = true ) {
		if ( ! $repeatable && $track && self::is_rendered( $slot ) ) {
			return '';
		}

		$config = Luna_Ads_Settings::slot( $slot );
		$code   = isset( $config['code'] ) ? (string) $config['code'] : '';
		if ( empty( $config['enabled'] ) || '' === trim( $code ) ) {
			return '';
		}

		if ( $track ) {
			self::$rendered[ $slot ] = true;
		}

		$class = 'luna-ad luna-ad--' . str_replace( '_', '-', $slot );

		return '<div class="' . esc_attr( $class ) . '" data-luna-ad="' . esc_attr( $slot ) . '">' .
			do_shortcode( $code ) .
			'</div>';
	}

	/**
	 * 指定した段落の直後に広告を挿入する。段落が足りなければ null。
	 *
	 * @param string $content   本文。
	 * @param string $ad        広告マークアップ。
	 * @param int    $paragraph 何段落目の後か。
	 * @return string|null
	 */
	private static function inject_after_paragraph( $content, $ad, $paragraph ) {
		$paragraph = max( 1, (int) $paragraph );
		$offset    = 0;
		$seen      = 0;

		while ( false !== ( $position = stripos( $content, '</p>', $offset ) ) ) {
			$seen++;
			if ( $seen >= $paragraph ) {
				$insert_at = $position + 4;
				return substr( $content, 0, $insert_at ) . "\n" . $ad . "\n" . substr( $content, $insert_at );
			}
			$offset = $position + 4;
		}

		return null;
	}

	/**
	 * 出力済みか。
	 *
	 * @param string $slot スロット名。
	 * @return bool
	 */
	private static function is_rendered( $slot ) {
		return ! empty( self::$rendered[ $slot ] );
	}

	/**
	 * 広告コードに AdSense ローダーが含まれるか。
	 *
	 * @param array<string,mixed> $settings 設定。
	 * @return bool
	 */
	private static function codes_include_adsense_loader( $settings ) {
		foreach ( $settings['slots'] as $config ) {
			if ( false !== strpos( (string) $config['code'], 'adsbygoogle.js' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * カンマ区切りID文字列を整数配列にする。
	 *
	 * @param mixed $value 値。
	 * @return int[]
	 */
	private static function parse_ids( $value ) {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $value ) ) );
		return array_values( array_unique( $ids ) );
	}
}
