<?php
/**
 * アイキャッチ画像を WebP に置き換えるエンジン。
 *
 * GD を直接叩かず wp_get_image_editor() を経由する。回転・カラープロファイル・
 * Imagick/GD の選択を WP コアに任せられ、将来 Imagick が入った環境でもそのまま動く。
 *
 * @package Node_Image_Compressor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Node_IC_Converter' ) ) {
	return;
}

final class Node_IC_Converter {

	public const CRON_HOOK = 'node_ic_convert_event';

	/** 置き換え対象にする MIME。GIF（アニメ）と SVG は対象外。 */
	public const SOURCE_MIMES = array( 'image/jpeg', 'image/png' );

	public const META_ORIGINAL_FILE  = '_node_ic_original_file';
	public const META_ORIGINAL_MIME  = '_node_ic_original_mime';
	public const META_ORIGINAL_BYTES = '_node_ic_original_bytes';
	public const META_WEBP_BYTES     = '_node_ic_webp_bytes';
	public const META_CONVERTED_AT   = '_node_ic_converted_at';
	public const META_SKIP           = '_node_ic_skip';
	/** 実際に採用した WebP 品質（自動モードで何段目が選ばれたか分かるように残す）。 */
	public const META_QUALITY_USED   = '_node_ic_quality_used';
	/** 置き換え前の相対パス。消えた旧 URL からの転送に使う逆引きキー。 */
	public const META_REDIRECT_FROM  = '_node_ic_redirect_from';
	/** 元ファイルを削除して置き換えた場合に立つ（復元できないことを示す）。 */
	public const META_ORIGINAL_REMOVED = '_node_ic_original_removed';

	public const DEFAULT_QUALITY = 82;

	/**
	 * 自動モードで試す品質（高い順）。上から順に書き出し、目標サイズを満たした時点で止める。
	 * きれいなまま十分小さくなる画像は 88 で終わり、重い写真だけ段階的に落ちていく。
	 */
	public const AUTO_QUALITY_STEPS = array( 88, 82, 76, 70 );

	/** 自動モードの目標: 元ファイルのこの割合以下になれば十分とみなす。 */
	public const AUTO_TARGET_RATIO = 0.7;

	/**
	 * この環境で WebP を書き出せるか。
	 */
	public static function is_supported(): bool {
		return wp_image_editor_supports(
			array(
				'mime_type' => 'image/webp',
				'methods'   => array( 'resize', 'save' ),
			)
		);
	}

	/**
	 * プラグイン機能全体が有効か。
	 */
	public static function is_enabled(): bool {
		return '1' === (string) get_option( 'node_ic_enabled', '1' );
	}

	/**
	 * アイキャッチ設定時の自動置き換えが有効か。
	 */
	public static function is_auto_enabled(): bool {
		return self::is_enabled() && '1' === (string) get_option( 'node_ic_auto', '1' );
	}

	public static function get_quality(): int {
		$quality = (int) get_option( 'node_ic_quality', self::DEFAULT_QUALITY );
		return max( 1, min( 100, $quality ) );
	}

	/**
	 * 品質を自動で決めるか。既定は自動。
	 */
	public static function is_auto_quality(): bool {
		return 'manual' !== (string) get_option( 'node_ic_quality_mode', 'auto' );
	}

	/**
	 * 実際に試す品質の並び（高い順）。
	 *
	 * @return int[]
	 */
	public static function quality_candidates(): array {
		if ( ! self::is_auto_quality() ) {
			return array( self::get_quality() );
		}

		/**
		 * 自動モードで試す品質を差し替えられるようにする。
		 *
		 * @param int[] $steps 高い順の品質リスト。
		 */
		$steps = (array) apply_filters( 'node_ic_auto_quality_steps', self::AUTO_QUALITY_STEPS );
		$steps = array_values( array_filter( array_map( 'intval', $steps ) ) );

		return $steps ?: array( self::DEFAULT_QUALITY );
	}

	/**
	 * 消えた旧 URL を新しい WebP へ転送するか。既定は有効。
	 */
	public static function redirects_old_urls(): bool {
		return '0' !== (string) get_option( 'node_ic_redirect', '1' );
	}

	/**
	 * 置き換え後に元の JPEG/PNG を残すか。
	 * 既定は残さない（＝本当に置き換える）。
	 */
	public static function keeps_original(): bool {
		return '1' === (string) get_option( 'node_ic_keep_original', '0' );
	}

	/**
	 * 元ファイルが残っていて復元できるか。
	 */
	public static function is_restorable( int $attachment_id ): bool {
		if ( ! self::is_converted( $attachment_id ) ) {
			return false;
		}

		if ( ! self::is_owned_by_current_user( $attachment_id ) ) {
			return false;
		}

		if ( '1' === (string) get_post_meta( $attachment_id, self::META_ORIGINAL_REMOVED, true ) ) {
			return false;
		}

		return '' !== (string) get_post_meta( $attachment_id, self::META_ORIGINAL_FILE, true );
	}

	/**
	 * すでに置き換え済みか。
	 */
	public static function is_converted( int $attachment_id ): bool {
		return '' !== (string) get_post_meta( $attachment_id, self::META_CONVERTED_AT, true );
	}

	/**
	 * スキップ理由（無ければ空文字）。
	 */
	public static function get_skip_reason( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_SKIP, true );
	}

	/**
	 * いま操作している人が、その画像をアップロードした本人か。
	 *
	 * cron やコマンドラインにはログインユーザーが居ない。そこで弾くと自動置き換えが
	 * 一切動かなくなるため、ユーザー文脈が無いときは判定しない。自動置き換えの予約は
	 * node_ic_schedule_conversion() が「操作した人」の文脈で can_convert() を通すので、
	 * 実行時に改めて確認しなくても他人の画像がキューに入ることはない。
	 */
	public static function is_owned_by_current_user( int $attachment_id ): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return true;
		}

		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return false;
		}

		// 取り込み・移行で入った画像はアップロード者が記録されていない（post_author = 0）。
		// これを弾いても守られる相手が居らず、過去の画像が丸ごと対象外になるだけなので許可する
		$author = (int) $post->post_author;
		$owned  = ( 0 === $author || $author === (int) $user_id );

		/**
		 * 所有者の判定を上書きできるようにする（複数人で運用する場合の逃げ道）。
		 *
		 * @param bool $owned         本人のアップロードか。
		 * @param int  $attachment_id 対象のアタッチメント ID。
		 * @param int  $user_id       操作しているユーザー ID。
		 */
		return (bool) apply_filters( 'node_ic_is_owner', $owned, $attachment_id, $user_id );
	}

	/**
	 * 置き換え可能かどうか。$reason に不可の理由を返す。
	 */
	public static function can_convert( int $attachment_id, ?string &$reason = null ): bool {
		$reason = '';

		if ( ! self::is_enabled() ) {
			$reason = 'WebP への置き換えが無効化されています。';
			return false;
		}

		if ( ! self::is_supported() ) {
			$reason = 'このサーバーの画像ライブラリが WebP に対応していません。';
			return false;
		}

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			$reason = 'メディアが見つかりません。';
			return false;
		}

		if ( self::is_converted( $attachment_id ) ) {
			$reason = 'すでに置き換え済みです。';
			return false;
		}

		if ( ! in_array( $post->post_mime_type, self::SOURCE_MIMES, true ) ) {
			$reason = 'JPEG / PNG ではないため対象外です。';
			return false;
		}

		// 取り違えを防ぐため、自分がアップロードした画像だけを置き換えられるようにする
		if ( ! self::is_owned_by_current_user( $attachment_id ) ) {
			$reason = '他の人がアップロードした画像のため置き換えできません。';
			return false;
		}

		$skip = self::get_skip_reason( $attachment_id );
		if ( '' !== $skip ) {
			$reason = $skip;
			return false;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			$reason = '元ファイルが見つかりません。';
			return false;
		}

		/**
		 * 外部から置き換えを拒否できるようにする。
		 *
		 * @param bool $can           置き換えてよいか。
		 * @param int  $attachment_id 対象のアタッチメント ID。
		 */
		$can = (bool) apply_filters( 'node_ic_can_convert', true, $attachment_id );
		if ( ! $can ) {
			$reason = 'フィルタにより除外されています。';
		}

		return $can;
	}

	/**
	 * アイキャッチ 1 件を WebP に置き換える。
	 *
	 * 元の JPEG/PNG フルサイズは「元ファイルを残す」設定が有効なときだけ残る。
	 * 既定では削除して本当に置き換える。
	 * 削除するのは置き換え前の中間サイズだけ。
	 *
	 * @return array{ok:bool,before:int,after:int,message:string}
	 */
	public static function convert( int $attachment_id ): array {
		$attachment_id = (int) $attachment_id;

		$reason = '';
		if ( ! self::can_convert( $attachment_id, $reason ) ) {
			return self::result( false, 0, 0, $reason );
		}

		// 同一アタッチメントへの二重実行を防ぐ（cron と管理画面の一括置き換えが重なるケース）
		$lock_key = 'node_ic_lock_' . $attachment_id;
		if ( get_transient( $lock_key ) ) {
			return self::result( false, 0, 0, '置き換え処理が進行中です。' );
		}
		set_transient( $lock_key, 1, 60 );

		try {
			$source_path = (string) get_attached_file( $attachment_id );
			$before      = (int) filesize( $source_path );
			$source_mime = (string) get_post_mime_type( $attachment_id );

			$webp_path = self::webp_path_for( $source_path );
			$target    = (int) floor( $before * self::AUTO_TARGET_RATIO );
			$after     = 0;
			$quality   = 0;

			// 品質を高いほうから試し、目標サイズに収まった時点で止める。
			// 同じパスへ上書きしていくので、ループを抜けた時点のファイルが採用結果になる
			foreach ( self::quality_candidates() as $candidate ) {
				$editor = wp_get_image_editor( $source_path );
				if ( is_wp_error( $editor ) ) {
					return self::result( false, $before, 0, '画像エディタを初期化できませんでした: ' . $editor->get_error_message() );
				}

				$editor->set_quality( $candidate );
				$saved = $editor->save( $webp_path, 'image/webp' );

				if ( is_wp_error( $saved ) ) {
					return self::result( false, $before, 0, 'WebP の書き出しに失敗しました: ' . $saved->get_error_message() );
				}

				// save() は実際に使ったパスを返す。衝突時に別名になることがあるので必ず受け直す
				$webp_path = isset( $saved['path'] ) ? (string) $saved['path'] : $webp_path;
				$after     = file_exists( $webp_path ) ? (int) filesize( $webp_path ) : 0;
				$quality   = $candidate;

				if ( $after > 0 && $after <= $target ) {
					break;
				}
			}

			// WebP のほうが大きいなら意味がないので巻き戻す。
			// PNG のスクリーンショットや単色イラストでは実際によく起きる
			if ( $after <= 0 || $after >= $before ) {
				if ( file_exists( $webp_path ) ) {
					wp_delete_file( $webp_path );
				}
				$message = 'WebP のほうが大きくなるため置き換えませんでした。';
				update_post_meta( $attachment_id, self::META_SKIP, $message );
				return self::result( false, $before, $after, $message );
			}

			// 差し替え前の中間サイズを控える（差し替え後はメタが上書きされて追えなくなる）
			$old_size_paths = self::intermediate_paths( $attachment_id );

			$original_file = _wp_relative_upload_path( $source_path );

			update_attached_file( $attachment_id, $webp_path );
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => 'image/webp',
				)
			);

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata( $attachment_id, $webp_path );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}

			// 旧中間サイズは常に削除する（新しい WebP のサイズが作り直されているため）
			foreach ( $old_size_paths as $old_path ) {
				if ( $old_path !== $source_path && file_exists( $old_path ) ) {
					wp_delete_file( $old_path );
				}
			}

			// 元のフルサイズは設定次第。残せば復元でき、消せばディスクを使わない。
			// 消した場合は旧 URL への直リンクが 404 になるため、復元も不可になる
			$removed_original = false;
			if ( ! self::keeps_original() && file_exists( $source_path ) ) {
				wp_delete_file( $source_path );
				$removed_original = ! file_exists( $source_path );
			}

			update_post_meta( $attachment_id, self::META_ORIGINAL_REMOVED, $removed_original ? '1' : '0' );
			// 旧 URL からの転送に使う。元を残す設定でも、あとで消えたときに効くよう常に残す
			update_post_meta( $attachment_id, self::META_REDIRECT_FROM, $original_file );
			update_post_meta( $attachment_id, self::META_ORIGINAL_FILE, $removed_original ? '' : $original_file );
			update_post_meta( $attachment_id, self::META_ORIGINAL_MIME, $source_mime );
			update_post_meta( $attachment_id, self::META_ORIGINAL_BYTES, $before );
			update_post_meta( $attachment_id, self::META_WEBP_BYTES, $after );
			update_post_meta( $attachment_id, self::META_QUALITY_USED, $quality );
			update_post_meta( $attachment_id, self::META_CONVERTED_AT, current_time( 'mysql' ) );
			delete_post_meta( $attachment_id, self::META_SKIP );

			node_ic_flush_target_cache();

			return self::result(
				true,
				$before,
				$after,
				sprintf(
					'%s → %s に置き換えました。%s',
					size_format( $before ),
					size_format( $after ),
					$removed_original ? '（元ファイルは削除済み）' : ''
				)
			);
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * 置き換えを取り消し、元の JPEG/PNG に戻す。
	 *
	 * @return array{ok:bool,before:int,after:int,message:string}
	 */
	public static function restore( int $attachment_id ): array {
		$attachment_id = (int) $attachment_id;

		if ( ! self::is_owned_by_current_user( $attachment_id ) ) {
			return self::result( false, 0, 0, '他の人がアップロードした画像のため復元できません。' );
		}

		if ( ! self::is_converted( $attachment_id ) ) {
			return self::result( false, 0, 0, '置き換えられていないため復元できません。' );
		}

		if ( '1' === (string) get_post_meta( $attachment_id, self::META_ORIGINAL_REMOVED, true ) ) {
			return self::result( false, 0, 0, '元ファイルは削除済みのため復元できません。' );
		}

		$relative = (string) get_post_meta( $attachment_id, self::META_ORIGINAL_FILE, true );
		if ( '' === $relative ) {
			return self::result( false, 0, 0, '元ファイルの記録がありません。' );
		}

		$uploads       = wp_get_upload_dir();
		$original_path = trailingslashit( $uploads['basedir'] ) . ltrim( $relative, '/' );

		if ( ! file_exists( $original_path ) ) {
			return self::result( false, 0, 0, '元ファイルが見つかりません: ' . $relative );
		}

		$original_mime = (string) get_post_meta( $attachment_id, self::META_ORIGINAL_MIME, true );
		if ( '' === $original_mime ) {
			$original_mime = 'image/jpeg';
		}

		// WebP 側の実体（フルサイズ + 中間サイズ）を控えてから差し戻す
		$webp_path  = (string) get_attached_file( $attachment_id );
		$webp_sizes = self::intermediate_paths( $attachment_id );

		update_attached_file( $attachment_id, $original_path );
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $original_mime,
			)
		);

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $original_path );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		foreach ( $webp_sizes as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		if ( $webp_path !== $original_path && file_exists( $webp_path ) ) {
			wp_delete_file( $webp_path );
		}

		delete_post_meta( $attachment_id, self::META_ORIGINAL_FILE );
		delete_post_meta( $attachment_id, self::META_ORIGINAL_MIME );
		delete_post_meta( $attachment_id, self::META_ORIGINAL_BYTES );
		delete_post_meta( $attachment_id, self::META_WEBP_BYTES );
		delete_post_meta( $attachment_id, self::META_QUALITY_USED );
		delete_post_meta( $attachment_id, self::META_REDIRECT_FROM );
		delete_post_meta( $attachment_id, self::META_CONVERTED_AT );
		delete_post_meta( $attachment_id, self::META_ORIGINAL_REMOVED );

		node_ic_flush_target_cache();

		return self::result( true, 0, 0, '元の画像に復元しました。' );
	}

	/**
	 * 置き換え後のパス（拡張子を .webp に差し替えたもの）。
	 */
	public static function webp_path_for( string $path ): string {
		$dir  = dirname( $path );
		$base = pathinfo( $path, PATHINFO_FILENAME );
		return trailingslashit( $dir ) . $base . '.webp';
	}

	/**
	 * 現在のメタから中間サイズの実ファイルパスを集める。
	 *
	 * @return string[]
	 */
	private static function intermediate_paths( int $attachment_id ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return array();
		}

		$full = (string) get_attached_file( $attachment_id );
		$dir  = trailingslashit( dirname( $full ) );

		$paths = array();
		foreach ( $metadata['sizes'] as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			$paths[] = $dir . $size['file'];
		}

		return array_unique( $paths );
	}

	/**
	 * @return array{ok:bool,before:int,after:int,message:string}
	 */
	private static function result( bool $ok, int $before, int $after, string $message ): array {
		return array(
			'ok'      => $ok,
			'before'  => $before,
			'after'   => $after,
			'message' => $message,
		);
	}
}
