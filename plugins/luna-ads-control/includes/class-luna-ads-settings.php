<?php
/**
 * 設定の既定値・保存・サニタイズ。
 *
 * @package Luna_Ads_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定の読み書きを担当する。
 *
 * 設定は1つのオプション（配列）にまとめ、管理画面の項目は8つに固定する。
 */
final class Luna_Ads_Settings {

	const OPTION = 'luna_ads_settings';

	/**
	 * 管理画面の8項目。
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'     => false,
			'code_source' => 'plugin',
			'delivery'    => 'manual',
			'client_id'   => '',
			'slots'       => array(
				'in_article'     => array(
					'enabled'   => false,
					'code'      => '',
					'paragraph' => 3,
				),
				'article_bottom' => array(
					'enabled' => false,
					'code'    => '',
				),
				'infeed'         => array(
					'enabled' => false,
					'code'    => '',
					'every'   => 3,
				),
				'sidebar'        => array(
					'enabled' => false,
					'code'    => '',
				),
			),
			'exclude'     => array(
				'post_ids'     => '',
				'category_ids' => '',
				'logged_in'    => false,
			),
		);
	}

	/**
	 * 保存済み設定を既定値とマージして返す。
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {
		$stored   = get_option( self::OPTION, array() );
		$defaults = self::defaults();
		$stored   = is_array( $stored ) ? $stored : array();

		$settings = wp_parse_args( $stored, $defaults );

		$settings['enabled']     = (bool) $settings['enabled'];
		$settings['code_source'] = in_array( $settings['code_source'], array( 'plugin', 'theme', 'external' ), true )
			? $settings['code_source']
			: 'plugin';
		$settings['delivery']    = in_array( $settings['delivery'], array( 'manual', 'auto', 'manual_auto' ), true )
			? $settings['delivery']
			: 'manual';
		$settings['client_id']   = (string) $settings['client_id'];

		$stored_slots = isset( $stored['slots'] ) && is_array( $stored['slots'] ) ? $stored['slots'] : array();
		$settings['slots'] = array();
		foreach ( $defaults['slots'] as $slot => $slot_defaults ) {
			$slot_stored            = isset( $stored_slots[ $slot ] ) && is_array( $stored_slots[ $slot ] )
				? $stored_slots[ $slot ]
				: array();
			$settings['slots'][ $slot ] = wp_parse_args( $slot_stored, $slot_defaults );

			if ( isset( $settings['slots'][ $slot ]['enabled'] ) ) {
				$settings['slots'][ $slot ]['enabled'] = (bool) $settings['slots'][ $slot ]['enabled'];
			}
			if ( isset( $settings['slots'][ $slot ]['code'] ) ) {
				$settings['slots'][ $slot ]['code'] = (string) $settings['slots'][ $slot ]['code'];
			}
		}

		$stored_exclude = isset( $stored['exclude'] ) && is_array( $stored['exclude'] ) ? $stored['exclude'] : array();
		$settings['exclude'] = wp_parse_args( $stored_exclude, $defaults['exclude'] );
		$settings['exclude']['logged_in'] = (bool) $settings['exclude']['logged_in'];

		return $settings;
	}

	/**
	 * 指定スロットの設定を返す。
	 *
	 * @param string $slot スロット名。
	 * @return array<string,mixed>
	 */
	public static function slot( $slot ) {
		$settings = self::get();
		return isset( $settings['slots'][ $slot ] ) ? $settings['slots'][ $slot ] : array();
	}

	/**
	 * 設定を保存する（管理画面外・テスト用）。
	 *
	 * @param array<string,mixed> $input 入力値。
	 * @return array<string,mixed> サニタイズ後の値。
	 */
	public static function update( $input ) {
		$sanitized = self::sanitize( $input );
		update_option( self::OPTION, $sanitized );
		return $sanitized;
	}

	/**
	 * 有効化時は既定値だけを入れ、広告配信は有効にしない。
	 *
	 * 既に設定がある場合は上書きしない（無効化→再有効化で設定を失わない）。
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/**
	 * 管理画面の設定項目数（8）。
	 *
	 * @return int
	 */
	public static function field_count() {
		return 8;
	}

	/**
	 * 現在のユーザーが広告設定を変更できるか。
	 *
	 * @return bool
	 */
	public static function user_can_manage() {
		/**
		 * 広告設定を変更できる権限。
		 *
		 * @param string $capability 権限名。
		 */
		$capability = apply_filters( 'luna_ads_capability', 'manage_options' );
		if ( ! is_string( $capability ) || '' === $capability ) {
			$capability = 'manage_options';
		}
		return current_user_can( $capability );
	}

	/**
	 * 入力値を検証して保存可能な配列に変換する。
	 *
	 * @param mixed $input 入力値。
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		$out = array();

		$out['enabled']     = ! empty( $input['enabled'] );
		$out['code_source'] = isset( $input['code_source'] ) && in_array( $input['code_source'], array( 'plugin', 'theme', 'external' ), true )
			? $input['code_source']
			: 'plugin';
		$out['delivery']    = isset( $input['delivery'] ) && in_array( $input['delivery'], array( 'manual', 'auto', 'manual_auto' ), true )
			? $input['delivery']
			: 'manual';
		$out['client_id']   = self::sanitize_client_id( isset( $input['client_id'] ) ? $input['client_id'] : '' );

		$slots = isset( $input['slots'] ) && is_array( $input['slots'] ) ? $input['slots'] : array();

		$out['slots'] = array();
		foreach ( $defaults['slots'] as $slot => $slot_defaults ) {
			$slot_input = isset( $slots[ $slot ] ) && is_array( $slots[ $slot ] ) ? $slots[ $slot ] : array();

			$out['slots'][ $slot ] = array(
				'enabled' => ! empty( $slot_input['enabled'] ),
				'code'    => self::sanitize_ad_code( isset( $slot_input['code'] ) ? $slot_input['code'] : '' ),
			);

			if ( 'in_article' === $slot ) {
				$out['slots'][ $slot ]['paragraph'] = self::sanitize_range( $slot_input, 'paragraph', 1, 20, 3 );
			}
			if ( 'infeed' === $slot ) {
				$out['slots'][ $slot ]['every'] = self::sanitize_range( $slot_input, 'every', 1, 20, 3 );
			}
		}

		$exclude = isset( $input['exclude'] ) && is_array( $input['exclude'] ) ? $input['exclude'] : array();

		$out['exclude'] = array(
			'post_ids'     => self::sanitize_id_list( isset( $exclude['post_ids'] ) ? $exclude['post_ids'] : '' ),
			'category_ids' => self::sanitize_id_list( isset( $exclude['category_ids'] ) ? $exclude['category_ids'] : '' ),
			'logged_in'    => ! empty( $exclude['logged_in'] ),
		);

		/**
		 * 保存前の設定値を拡張する。
		 *
		 * @param array<string,mixed> $out   サニタイズ後の値。
		 * @param array<string,mixed> $input 生の入力値。
		 */
		return apply_filters( 'luna_ads_sanitized_settings', $out, $input );
	}

	/**
	 * AdSense クライアントID（ca-pub-数字）だけを許可する。
	 *
	 * @param mixed $value 入力値。
	 * @return string
	 */
	private static function sanitize_client_id( $value ) {
		$value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
		return preg_match( '/^ca-pub-\d{10,20}$/', $value ) ? $value : '';
	}

	/**
	 * 数値項目を範囲内に丸める。
	 *
	 * @param array<string,mixed> $input    入力配列。
	 * @param string              $key      キー。
	 * @param int                 $min      最小値。
	 * @param int                 $max      最大値。
	 * @param int                 $fallback 未入力時の値。
	 * @return int
	 */
	private static function sanitize_range( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return $fallback;
		}
		return max( $min, min( $max, absint( $input[ $key ] ) ) );
	}

	/**
	 * カンマ区切りのIDを正規化する。
	 *
	 * @param mixed $value 入力値。
	 * @return string
	 */
	private static function sanitize_id_list( $value ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		$ids   = array_filter( array_map( 'absint', explode( ',', $value ) ) );
		$ids   = array_values( array_unique( $ids ) );
		return implode( ',', $ids );
	}

	/**
	 * 広告コードを許可リストでサニタイズする。
	 *
	 * 設定を変更できるのは管理者だけだが、保存時にも許可タグ・属性へ限定する。
	 * イベントハンドラ（onclick 等）は許可しない。
	 *
	 * @param mixed $code 広告コード。
	 * @return string
	 */
	public static function sanitize_ad_code( $code ) {
		$code = trim( (string) wp_unslash( (string) $code ) );
		if ( '' === $code ) {
			return '';
		}

		$attributes = array(
			'class'                   => true,
			'id'                      => true,
			'style'                   => true,
			'src'                     => true,
			'href'                    => true,
			'alt'                     => true,
			'title'                   => true,
			'width'                   => true,
			'height'                  => true,
			'loading'                 => true,
			'decoding'                => true,
			'async'                   => true,
			'defer'                   => true,
			'crossorigin'             => true,
			'type'                    => true,
			'nonce'                   => true,
			'target'                  => true,
			'rel'                     => true,
			'frameborder'             => true,
			'allow'                   => true,
			'allowfullscreen'         => true,
			'referrerpolicy'          => true,
			'sandbox'                 => true,
			'data-ad-client'          => true,
			'data-ad-slot'            => true,
			'data-ad-format'          => true,
			'data-ad-layout'          => true,
			'data-ad-layout-key'      => true,
			'data-full-width-responsive' => true,
			'data-slot'               => true,
			'data-no-fill'            => true,
			'json'                    => true,
		);

		$allowed = array(
			'ins'    => $attributes,
			'script' => $attributes,
			'iframe' => $attributes,
			'amp-ad' => $attributes,
			'div'    => $attributes,
			'span'   => $attributes,
			'a'      => $attributes,
			'img'    => $attributes,
			'p'      => $attributes,
			'br'     => array(),
		);

		/**
		 * 広告コードで許可するHTML。
		 *
		 * @param array<string,array<string,bool>> $allowed 許可タグと属性。
		 */
		$allowed = apply_filters( 'luna_ads_allowed_html', $allowed );

		return trim( wp_kses( $code, $allowed ) );
	}
}
