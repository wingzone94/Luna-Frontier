<?php
/**
 * アイキャッチ WebP 変換（Node Image Compressor）の自動テスト
 *
 * @package Node_Image_Compressor
 */

class Node_Image_Compressor_Test extends WP_UnitTestCase {

	private $uploader_id;

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'Node_IC_Converter' ) ) {
			require_once dirname( __DIR__ ) . '/plugins-embedded/node-image-compressor/includes/class-webp-converter.php';
			require_once dirname( __DIR__ ) . '/plugins-embedded/node-image-compressor/includes/query.php';
			require_once dirname( __DIR__ ) . '/plugins-embedded/node-image-compressor/includes/hooks-featured-image.php';
			require_once dirname( __DIR__ ) . '/plugins-embedded/node-image-compressor/includes/redirect.php';
		}

		update_option( 'node_ic_enabled', '1' );
		// 既定は元ファイル削除。復元まわりのテストは個別に残す設定へ切り替える
		update_option( 'node_ic_keep_original', '0' );

		// 所有者チェックが働くよう、アップロード者としてログインした状態にする
		$this->uploader_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->uploader_id );
	}

	public function tear_down() {
		delete_option( 'node_ic_enabled' );
		delete_option( 'node_ic_quality' );
		delete_option( 'node_ic_quality_mode' );
		delete_option( 'node_ic_keep_original' );
		delete_option( 'node_ic_redirect' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * WebP が書き出せない環境ではテストをスキップする。
	 */
	private function require_webp(): void {
		if ( ! Node_IC_Converter::is_supported() ) {
			$this->markTestSkipped( 'この環境では WebP を書き出せません。' );
		}
	}

	/**
	 * JPEG で圧縮しづらい（＝WebP のほうが確実に小さくなる）写真風の画像を作る。
	 */
	private function make_jpeg( int $width = 480, int $height = 360 ): string {
		$image = imagecreatetruecolor( $width, $height );

		for ( $x = 0; $x < $width; $x++ ) {
			for ( $y = 0; $y < $height; $y++ ) {
				$color = imagecolorallocate(
					$image,
					( $x * 7 + $y * 3 ) % 256,
					( $x * 3 + $y * 11 ) % 256,
					( $x * 13 + $y * 5 ) % 256
				);
				imagesetpixel( $image, $x, $y, $color );
			}
		}

		$path = wp_tempnam( 'node-ic-source.jpg' );
		// wp_tempnam は .tmp を付けるので、拡張子付きに置き換える
		$path = preg_replace( '/\.tmp$/', '.jpg', $path );
		imagejpeg( $image, $path, 92 );
		imagedestroy( $image );

		return $path;
	}

	/**
	 * 実ファイル付きのアタッチメントを作る。
	 */
	private function make_attachment(): int {
		$source = $this->make_jpeg();
		$id     = $this->factory->attachment->create_upload_object( $source );

		if ( file_exists( $source ) ) {
			unlink( $source );
		}

		return (int) $id;
	}

	// --- 変換 ---------------------------------------------------------------

	public function test_convert_replaces_attachment_with_webp() {
		$this->require_webp();

		$id       = $this->make_attachment();
		$original = get_attached_file( $id );

		$result = Node_IC_Converter::convert( $id );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'image/webp', get_post_mime_type( $id ) );
		$this->assertStringEndsWith( '.webp', (string) get_attached_file( $id ) );
		$this->assertFileExists( (string) get_attached_file( $id ) );

		// 削減できていること
		$this->assertGreaterThan( 0, $result['before'] );
		$this->assertLessThan( $result['before'], $result['after'] );

		// 記録が残ること
		$this->assertNotEmpty( get_post_meta( $id, Node_IC_Converter::META_CONVERTED_AT, true ) );
		$this->assertSame( 'image/jpeg', get_post_meta( $id, Node_IC_Converter::META_ORIGINAL_MIME, true ) );
		$this->assertTrue( Node_IC_Converter::is_converted( $id ) );

		unset( $original );
	}

	public function test_convert_keeps_original_file_when_option_is_on() {
		$this->require_webp();

		update_option( 'node_ic_keep_original', '1' );

		$id       = $this->make_attachment();
		$original = (string) get_attached_file( $id );

		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		// 「元ファイルを残す」がオンなら、旧 URL への直リンクを 404 にしない
		$this->assertFileExists( $original );
		$this->assertNotSame( $original, (string) get_attached_file( $id ) );
		$this->assertTrue( Node_IC_Converter::is_restorable( $id ) );
	}

	public function test_convert_keeps_original_by_default() {
		$this->require_webp();

		$id       = $this->make_attachment();
		$original = (string) get_attached_file( $id );

		$result = Node_IC_Converter::convert( $id );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertFileExists( $original );
		$this->assertSame( '0', get_post_meta( $id, Node_IC_Converter::META_ORIGINAL_REMOVED, true ) );
		$this->assertTrue( Node_IC_Converter::is_restorable( $id ) );
	}

	public function test_restore_succeeds_with_default_original_retention() {
		$this->require_webp();

		$id = $this->make_attachment();
		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		$result = Node_IC_Converter::restore( $id );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
	}

	public function test_convert_regenerates_sizes_as_webp() {
		$this->require_webp();

		$id = $this->make_attachment();
		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertIsArray( $metadata );
		$this->assertNotEmpty( $metadata['sizes'] );

		foreach ( $metadata['sizes'] as $size ) {
			$this->assertStringEndsWith( '.webp', $size['file'] );
			$this->assertSame( 'image/webp', $size['mime-type'] );
		}
	}

	public function test_convert_twice_is_rejected() {
		$this->require_webp();

		$id = $this->make_attachment();
		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		$second = Node_IC_Converter::convert( $id );
		$this->assertFalse( $second['ok'] );
		$this->assertStringContainsString( '置き換え済み', $second['message'] );
	}

	// --- 復元 ---------------------------------------------------------------

	public function test_restore_returns_to_original() {
		$this->require_webp();

		update_option( 'node_ic_keep_original', '1' );

		$id       = $this->make_attachment();
		$original = (string) get_attached_file( $id );

		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );
		$webp = (string) get_attached_file( $id );

		$result = Node_IC_Converter::restore( $id );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
		$this->assertSame( $original, (string) get_attached_file( $id ) );
		$this->assertFileExists( $original );
		$this->assertFileExists( $webp );

		// メタが掃除されていること
		$this->assertFalse( Node_IC_Converter::is_converted( $id ) );
		$this->assertSame( '', (string) get_post_meta( $id, Node_IC_Converter::META_ORIGINAL_FILE, true ) );
	}

	public function test_restore_without_conversion_fails() {
		$id     = $this->make_attachment();
		$result = Node_IC_Converter::restore( $id );

		$this->assertFalse( $result['ok'] );
	}

	// --- 対象判定 -----------------------------------------------------------

	public function test_can_convert_rejects_non_jpeg_png() {
		$gif = $this->factory->attachment->create_object(
			array(
				'file'           => 'anim.gif',
				'post_mime_type' => 'image/gif',
			)
		);

		$reason = '';
		$this->assertFalse( Node_IC_Converter::can_convert( (int) $gif, $reason ) );
		$this->assertStringContainsString( 'JPEG', $reason );
	}

	public function test_can_convert_respects_skip_meta() {
		$this->require_webp();

		$id = $this->make_attachment();
		update_post_meta( $id, Node_IC_Converter::META_SKIP, '手動でスキップ指定されています。' );

		$reason = '';
		$this->assertFalse( Node_IC_Converter::can_convert( $id, $reason ) );
		$this->assertStringContainsString( 'スキップ', $reason );
	}

	public function test_can_convert_respects_filter() {
		$this->require_webp();

		$id = $this->make_attachment();
		add_filter( 'node_ic_can_convert', '__return_false' );

		$reason = '';
		$this->assertFalse( Node_IC_Converter::can_convert( $id, $reason ) );

		remove_filter( 'node_ic_can_convert', '__return_false' );
	}

	public function test_disabled_option_stops_conversion() {
		$this->require_webp();

		$id = $this->make_attachment();
		update_option( 'node_ic_enabled', '0' );

		$result = Node_IC_Converter::convert( $id );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
	}

	// --- 自動変換の予約 -----------------------------------------------------

	public function test_setting_thumbnail_schedules_conversion() {
		$this->require_webp();

		update_option( 'node_ic_auto', '1' );

		$id      = $this->make_attachment();
		$post_id = $this->factory->post->create();

		set_post_thumbnail( $post_id, $id );

		$this->assertNotFalse(
			wp_next_scheduled( Node_IC_Converter::CRON_HOOK, array( $id ) ),
			'アイキャッチ設定で変換が予約されること'
		);

		delete_option( 'node_ic_auto' );
	}

	public function test_auto_disabled_does_not_schedule() {
		$this->require_webp();

		update_option( 'node_ic_auto', '0' );

		$id      = $this->make_attachment();
		$post_id = $this->factory->post->create();

		set_post_thumbnail( $post_id, $id );

		$this->assertFalse( wp_next_scheduled( Node_IC_Converter::CRON_HOOK, array( $id ) ) );

		delete_option( 'node_ic_auto' );
	}

	// --- 一覧・集計 ---------------------------------------------------------

	public function test_target_ids_include_featured_images_only() {
		$featured = $this->make_attachment();
		$orphan   = $this->make_attachment();
		$post_id  = $this->factory->post->create();

		set_post_thumbnail( $post_id, $featured );
		node_ic_flush_target_cache();

		$ids = node_ic_get_target_ids();

		$this->assertContains( $featured, $ids );
		$this->assertNotContains( $orphan, $ids );
	}

	// --- 所有者チェック -----------------------------------------------------

	public function test_can_convert_rejects_other_users_upload() {
		$this->require_webp();

		$id = $this->make_attachment();

		// 別のユーザーとして操作する
		$other = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );

		$reason = '';
		$this->assertFalse( Node_IC_Converter::can_convert( $id, $reason ) );
		$this->assertStringContainsString( '他の人がアップロードした', $reason );

		$result = Node_IC_Converter::convert( $id );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $id ) );
	}

	public function test_restore_rejects_other_users_upload() {
		$this->require_webp();

		update_option( 'node_ic_keep_original', '1' );

		$id = $this->make_attachment();
		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		$other = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );

		$result = Node_IC_Converter::restore( $id );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( '他の人がアップロードした', $result['message'] );
		$this->assertFalse( Node_IC_Converter::is_restorable( $id ) );
	}

	public function test_orphaned_uploads_are_allowed() {
		$this->require_webp();

		$id = $this->make_attachment();
		// 取り込み画像のようにアップロード者が記録されていない状態を作る
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => 0,
			)
		);

		$other = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );

		$reason = '';
		$this->assertTrue( Node_IC_Converter::can_convert( $id, $reason ), $reason );
	}

	public function test_owner_check_is_skipped_without_user_context() {
		$this->require_webp();

		$id = $this->make_attachment();

		// cron や WP-CLI にはログインユーザーが居ない。そこで弾くと自動置き換えが止まる
		wp_set_current_user( 0 );

		$reason = '';
		$this->assertTrue( Node_IC_Converter::can_convert( $id, $reason ), $reason );
	}

	public function test_owner_check_can_be_relaxed_by_filter() {
		$this->require_webp();

		$id    = $this->make_attachment();
		$other = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );

		add_filter( 'node_ic_is_owner', '__return_true' );
		$reason = '';
		$this->assertTrue( Node_IC_Converter::can_convert( $id, $reason ), $reason );
		remove_filter( 'node_ic_is_owner', '__return_true' );
	}

	// --- 品質の自動決定 -----------------------------------------------------

	public function test_auto_quality_is_the_default() {
		$this->assertTrue( Node_IC_Converter::is_auto_quality() );
		$this->assertSame( Node_IC_Converter::AUTO_QUALITY_STEPS, Node_IC_Converter::quality_candidates() );
	}

	public function test_manual_mode_uses_the_configured_quality() {
		update_option( 'node_ic_quality_mode', 'manual' );
		update_option( 'node_ic_quality', 55 );

		$this->assertFalse( Node_IC_Converter::is_auto_quality() );
		$this->assertSame( array( 55 ), Node_IC_Converter::quality_candidates() );
	}

	public function test_auto_quality_records_the_step_it_settled_on() {
		$this->require_webp();

		$id = $this->make_attachment();
		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		$used = (int) get_post_meta( $id, Node_IC_Converter::META_QUALITY_USED, true );
		$this->assertContains( $used, Node_IC_Converter::AUTO_QUALITY_STEPS );
	}

	public function test_auto_quality_stops_early_when_target_is_met() {
		$this->require_webp();

		// 1 段目で十分小さくなるなら、それ以上品質を落とさない
		add_filter( 'node_ic_auto_quality_steps', static fn() => array( 90, 20 ) );

		$id = $this->make_attachment();
		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		$used   = (int) get_post_meta( $id, Node_IC_Converter::META_QUALITY_USED, true );
		$before = (int) get_post_meta( $id, Node_IC_Converter::META_ORIGINAL_BYTES, true );
		$after  = (int) get_post_meta( $id, Node_IC_Converter::META_WEBP_BYTES, true );

		if ( $after <= $before * Node_IC_Converter::AUTO_TARGET_RATIO ) {
			$this->assertSame( 90, $used, '目標を満たしたら品質 90 で止まること' );
		} else {
			$this->assertSame( 20, $used, '目標に届かなければ最後まで試すこと' );
		}

		remove_all_filters( 'node_ic_auto_quality_steps' );
	}

	// --- 旧 URL の転送 ------------------------------------------------------

	public function test_replaced_full_size_url_resolves_to_webp() {
		$this->require_webp();

		$id       = $this->make_attachment();
		$original = _wp_relative_upload_path( (string) get_attached_file( $id ) );

		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		$target = node_ic_resolve_replacement_url( $original );

		$this->assertStringEndsWith( '.webp', $target );
		$this->assertSame( wp_get_attachment_url( $id ), $target );
	}

	public function test_replaced_intermediate_size_url_resolves_to_same_size_webp() {
		$this->require_webp();

		$id       = $this->make_attachment();
		$original = _wp_relative_upload_path( (string) get_attached_file( $id ) );

		// 置き換え前の中間サイズのファイル名を控えておく
		$before_meta = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $before_meta['sizes'] );
		$size      = reset( $before_meta['sizes'] );
		$old_sized = trailingslashit( dirname( $original ) ) . $size['file'];

		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		$target = node_ic_resolve_replacement_url( $old_sized );

		$this->assertStringEndsWith( '.webp', $target );
		// 同じ寸法の WebP へ送られること
		$this->assertStringContainsString( $size['width'] . 'x' . $size['height'], $target );
	}

	public function test_unknown_path_resolves_to_nothing() {
		$this->assertSame( '', node_ic_resolve_replacement_url( '2026/01/never-existed.jpg' ) );
	}

	public function test_restore_removes_the_redirect_entry() {
		$this->require_webp();

		update_option( 'node_ic_keep_original', '1' );

		$id       = $this->make_attachment();
		$original = _wp_relative_upload_path( (string) get_attached_file( $id ) );

		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );
		$this->assertNotSame( '', node_ic_resolve_replacement_url( $original ) );

		$this->assertTrue( Node_IC_Converter::restore( $id )['ok'] );

		wp_cache_flush();
		$this->assertSame( '', node_ic_resolve_replacement_url( $original ) );
	}

	public function test_redirect_can_be_disabled() {
		update_option( 'node_ic_redirect', '0' );
		$this->assertFalse( Node_IC_Converter::redirects_old_urls() );

		update_option( 'node_ic_redirect', '1' );
		$this->assertTrue( Node_IC_Converter::redirects_old_urls() );
	}

	public function test_redirect_is_on_by_default() {
		$this->assertTrue( Node_IC_Converter::redirects_old_urls() );
	}

	// --- 一覧・集計（つづき） -----------------------------------------------

	public function test_status_reports_pending_then_converted() {
		$this->require_webp();

		$id      = $this->make_attachment();
		$post_id = $this->factory->post->create();
		set_post_thumbnail( $post_id, $id );

		$this->assertSame( 'pending', node_ic_get_status( $id )['state'] );

		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );

		$status = node_ic_get_status( $id );
		$this->assertSame( 'converted', $status['state'] );
		$this->assertGreaterThan( 0, $status['saved'] );
		$this->assertGreaterThan( 0, $status['rate'] );
		$this->assertTrue( $status['restorable'] );
	}

	public function test_status_is_restorable_when_original_kept() {
		$this->require_webp();

		update_option( 'node_ic_keep_original', '1' );

		$id      = $this->make_attachment();
		$post_id = $this->factory->post->create();
		set_post_thumbnail( $post_id, $id );

		$this->assertTrue( Node_IC_Converter::convert( $id )['ok'] );
		$this->assertTrue( node_ic_get_status( $id )['restorable'] );
	}
}
