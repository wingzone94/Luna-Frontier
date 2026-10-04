<?php
/** Read-only audit and additive repair of locally stored image references. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Node_Image_Repair {
	const JOB = 'luna_image_repair_job';
	const PREFIX = 'luna_ir_';
	const ACTION = 'luna_image_repair';
	private static int $lock_connection = 0;

	/** Session-owned database lock: disconnect/crash releases it, never expires mid-write.
	 * Shared by both independent themes because uploads are shared. Records are separate.
	 */
	public static function lock_name(): string {
		global $wpdb;
		return 'image-repair-' . md5( DB_NAME . ':' . $wpdb->prefix );
	}

	public static function lock(): bool {
		global $wpdb;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) ) ) { return false; }
		self::$lock_connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		return true;
	}

	public static function unlock(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		self::$lock_connection = 0;
	}

	private static function assert_lock(): void {
		global $wpdb;
		if ( self::$lock_connection && ( self::$lock_connection !== (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) || self::$lock_connection !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', self::lock_name() ) ) ) ) {
			throw new RuntimeException( 'DB接続が切断されました。再開してください。' );
		}
	}

	/** Verify the durable value, not the request's option cache. */
	public static function persist( string $key, array $value ): void {
		global $wpdb;
		self::assert_lock();
		if ( self::JOB === $key ) { self::persist( self::PREFIX . $value['id'] . '_job', $value ); }
		update_option( $key, $value, false );
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		if ( maybe_unserialize( $stored ) !== $value ) {
			wp_cache_delete( $key, 'options' );
			throw new RuntimeException( '記録を保存できません。処理を停止しました。再開してください。' );
		}
	}


	/** Resolve only real uploads directories; reject symlinks, traversal and wrappers. */
	public static function path( string $relative ): ?string {
		$root = realpath( wp_get_upload_dir()['basedir'] );
		if ( ! $root || '' === $relative || preg_match( '~(^/|\\\\|\x00|:|(?:^|/)\.\.?(?:/|$))~', $relative ) ) { return null; }
		$path = $root . '/' . $relative;
		$parent = realpath( dirname( $path ) );
		if ( ! $parent || ( $parent !== $root && 0 !== strpos( $parent, $root . '/' ) ) || is_link( $path ) ) { return null; }
		$real = realpath( $path );
		if ( false !== $real && 0 !== strpos( $real, $root . '/' ) ) { return null; }
		return $path;
	}

	public static function relative( string $url ): ?string {
		$base = wp_parse_url( wp_get_upload_dir()['baseurl'] );
		$parts = wp_parse_url( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( ! is_array( $parts ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) { return null; }
		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) { return null; }
		if ( isset( $parts['host'] ) && ( strtolower( $parts['host'] ) !== strtolower( $base['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $base['port'] ?? null ) ) ) { return null; }
		$prefix = trailingslashit( rawurldecode( $base['path'] ?? '' ) );
		$path = rawurldecode( $parts['path'] ?? '' );
		if ( 0 !== strpos( $path, $prefix ) ) { return null; }
		$relative = substr( $path, strlen( $prefix ) );
		return self::path( $relative ) ? $relative : null;
	}

	public static function image( string $relative ): ?array {
		$path = self::path( $relative );
		if ( ! $path || ! is_file( $path ) || filesize( $path ) > 100 * MB_IN_BYTES ) { return null; }
		$info = @getimagesize( $path );
		return is_array( $info ) && in_array( $info['mime'] ?? '', array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ? $info : null;
	}

	public static function url( string $relative ): string {
		return trailingslashit( wp_get_upload_dir()['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}

	/** Explicit attachment lineage only. Never guess by adding -scaled to arbitrary names. */
	public static function lineage( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		$files = array();
		$original = get_post_meta( $id, '_node_image_original_metadata', true );
		if ( is_array( $original ) && ! empty( $original['file'] ) && self::path( $original['file'] ) ) {
			$files[] = $original['file'];
			if ( ! empty( $original['original_image'] ) && wp_basename( $original['original_image'] ) === $original['original_image'] ) {
				$files[] = ( '.' === dirname( $original['file'] ) ? '' : dirname( $original['file'] ) . '/' ) . $original['original_image'];
			}
		}
		$full = get_post_meta( $id, '_wp_attached_file', true );
		if ( is_string( $full ) && self::path( $full ) ) { $files[] = $full; }
		if ( is_array( $meta ) && ! empty( $meta['file'] ) && self::path( $meta['file'] ) ) {
			$files[] = $meta['file'];
			$dir = dirname( $meta['file'] );
			$prefix = '.' === $dir ? '' : $dir . '/';
			if ( ! empty( $meta['original_image'] ) && wp_basename( $meta['original_image'] ) === $meta['original_image'] ) { $files[] = $prefix . $meta['original_image']; }
		}
		foreach ( array( '_node_ic_original_file', '_node_ic_redirect_from' ) as $key ) {
			$value = get_post_meta( $id, $key, true );
			if ( is_string( $value ) && self::path( $value ) ) { $files[] = $value; }
		}
		return array_values( array_unique( $files ) );
	}

	/** Bind each target to its own file family, never to arbitrary files on the same ID. */
	public static function sources( int $id, string $target ): array {
		$sources = array();
		$matches = static function ( string $file ) use ( $target ): bool {
			$stem = preg_replace( '/\.[^.\/]+$/', '', $file );
			return $target === $file || (bool) preg_match( '~^' . preg_quote( $stem, '~' ) . '-[1-9][0-9]{0,4}x[1-9][0-9]{0,4}\.' . preg_quote( pathinfo( $file, PATHINFO_EXTENSION ), '~' ) . '$~i', $target );
		};
		foreach ( array( wp_get_attachment_metadata( $id ), get_post_meta( $id, '_node_image_original_metadata', true ), get_post_meta( $id, '_node_ic_original_metadata', true ) ) as $meta ) {
			if ( ! is_array( $meta ) || empty( $meta['file'] ) ) { continue; }
			$file = $meta['file'];
			$prefix = '.' === dirname( $file ) ? '' : dirname( $file ) . '/';
			$original = $prefix . ( $meta['original_image'] ?? '' );
			// WordPress's exact -scaled relationship preserves composition; editor -e files do not.
			$scaled_pair = ! empty( $meta['original_image'] ) && $file === preg_replace( '/(\.[^.]+)$/', '-scaled$1', $original );
			$belongs = $matches( $file );
			foreach ( $meta['sizes'] ?? array() as $size ) {
				if ( $target === $prefix . ( $size['file'] ?? '' ) ) { $belongs = true; }
			}
			if ( $belongs ) { return $scaled_pair ? array( $file, $original ) : array( $file ); }
			if ( $scaled_pair && $matches( $original ) ) { return array( $original, $file ); }
		}
		foreach ( self::lineage( $id ) as $file ) { if ( $matches( $file ) ) { $sources[] = $file; } }
		return array_values( array_unique( $sources ) );
	}

	/** Extract saved attributes without serializing HTML or Gutenberg blocks. */
	public static function references( WP_Post $post ): array {
		$refs = array();
		$tags = new WP_HTML_Tag_Processor( $post->post_content );
		while ( $tags->next_tag() ) {
			$tag = $tags->get_tag();
			if ( ! in_array( $tag, array( 'IMG', 'SOURCE', 'A' ), true ) ) { continue; }
			$id = 0;
			if ( preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', (string) $tags->get_attribute( 'class' ), $m ) ) { $id = (int) $m[1]; }
			foreach ( array( 'src', 'srcset', 'data-src', 'data-srcset', 'data-lazy-src', 'data-lazy-srcset', 'href' ) as $attr ) {
				$value = $tags->get_attribute( $attr );
				if ( ! is_string( $value ) || '' === $value || ( 'A' === $tag && 'href' !== $attr ) ) { continue; }
				$urls = array( $value );
				if ( false !== strpos( $attr, 'srcset' ) ) {
					$urls = array_map( static fn( $item ) => preg_split( '/\s+/', trim( $item ) )[0], explode( ',', $value ) );
				}
				foreach ( $urls as $url ) {
					if ( 'href' === $attr && ! preg_match( '/\.(?:png|jpe?g|webp|gif)(?:[?#]|$)/i', $url ) ) { continue; }
					if ( 0 === strpos( $url, 'data:' ) ) { continue; } // Inline lazy placeholders are not missing uploads.
					$refs[] = array( 'url' => $url, 'id' => $id, 'context' => $attr );
				}
			}
		}
		$walk = static function ( array $blocks ) use ( &$walk, &$refs ): void {
			foreach ( $blocks as $block ) {
				$attrs = $block['attrs'] ?? array();
				if ( in_array( $block['blockName'] ?? '', array( 'core/image', 'core/gallery', 'core/media-text', 'core/cover' ), true ) ) {
					foreach ( array( 'url', 'href', 'mediaUrl' ) as $key ) {
						if ( ! empty( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) { $refs[] = array( 'url' => $attrs[ $key ], 'id' => (int) ( $attrs['id'] ?? $attrs['mediaId'] ?? 0 ), 'context' => 'block:' . $key ); }
					}
					foreach ( $attrs['images'] ?? array() as $image ) {
						if ( ! empty( $image['url'] ) ) { $refs[] = array( 'url' => $image['url'], 'id' => (int) ( $image['id'] ?? 0 ), 'context' => 'gallery' ); }
					}
				}
				$walk( $block['innerBlocks'] ?? array() );
			}
		};
		$walk( parse_blocks( $post->post_content ) );
		$ids = array_filter( array_column( $refs, 'id' ) );
		$featured = (int) get_post_thumbnail_id( $post );
		if ( $featured ) { $ids[] = $featured; }
		foreach ( array_unique( $ids ) as $id ) {
			$meta = wp_get_attachment_metadata( $id );
			$full = get_post_meta( $id, '_wp_attached_file', true );
			if ( is_string( $full ) && '' !== $full ) { $refs[] = array( 'url' => self::url( $full ), 'id' => $id, 'context' => 'attachment' ); }
			if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
				$prefix = '.' === dirname( $meta['file'] ) ? '' : dirname( $meta['file'] ) . '/';
				foreach ( $meta['sizes'] ?? array() as $size ) {
					if ( ! empty( $size['file'] ) ) { $refs[] = array( 'url' => self::url( $prefix . $size['file'] ), 'id' => $id, 'context' => 'metadata' ); }
				}
			}
		}
		return $refs;
	}

	public static function inspect( array $ref ): array {
		$row = array_merge( $ref, array( 'classification' => '', 'status' => '判定不能', 'evidence' => '', 'source' => '', 'width' => 0, 'height' => 0 ) );
		$relative = self::relative( $ref['url'] );
		$row['relative'] = $relative;
		if ( null === $relative ) { $row['classification'] = '外部URL・uploads対象外'; $row['evidence'] = '外部通信は行いません。403や遅延読み込みをファイル消失として扱いません。'; return $row; }
		if ( self::image( $relative ) ) { $row['status'] = '正常'; return $row; }
		$path = self::path( $relative );
		if ( file_exists( $path ) ) { $row['classification'] = '画像として検証できない既存ファイル'; $row['evidence'] = 'HTML・破損・未対応形式・サイズ制限。既存ファイルは上書きしません。'; return $row; }
		$id = (int) $row['id'];
		if ( ! $id ) { $id = attachment_url_to_postid( self::url( $relative ) ); }
		if ( ! $id ) {
			global $wpdb;
			// Metadata includes historical sizes. Bounded lookup; ambiguous matches stay unresolved.
			$candidates = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_node_ic_original_file','_node_ic_redirect_from') AND meta_value LIKE %s LIMIT 21", '%' . $wpdb->esc_like( wp_basename( $relative ) ) . '%' ) );
			$matches = array();
			foreach ( $candidates as $candidate ) { if ( self::dimensions( (int) $candidate, $relative ) ) { $matches[] = (int) $candidate; } }
			if ( 1 === count( $matches ) ) { $id = $matches[0]; }
		}
		$row['id'] = $id;
		$dimensions = $id ? self::dimensions( $id, $relative ) : null;
		if ( ! $dimensions ) { $row['classification'] = '同一画像の根拠不足'; $row['evidence'] = '添付IDとメタデータのファイル名を照合できません。'; return $row; }
		$row = array_merge( $row, $dimensions );
		$row['classification'] = $dimensions['legacy'] ? '現在のメタデータにない旧派生URL' : '添付メタデータ参照先の欠損';
		$source_found = false;
		foreach ( self::sources( $id, $relative ) as $source ) {
			$info = self::image( $source );
			if ( ! $info ) { continue; }
			$source_found = true;
			// Never synthesize an unknown crop, upscale, or flatten an animated GIF.
			if ( $info[0] * $info[1] > 50000000 || 'image/gif' === $info['mime'] || ! $row['width'] || ! $row['height'] || $row['width'] > $info[0] || $row['height'] > $info[1] ) { continue; }
			if ( abs( $info[0] / $info[1] - $row['width'] / $row['height'] ) > 0.01 ) { continue; }
			$row['source'] = $source;
			$row['source_hash'] = hash_file( 'sha256', self::path( $source ) );
			$row['status'] = '修復可能';
			$row['evidence'] = '添付メタデータの同一画像系統・実ファイル・縦横比を確認。欠損URLそのものを再生成し、本文・ブロック属性は保持。';
			return $row;
		}
		$row['status'] = $source_found ? '判定不能' : '復元元が必要';
		$row['evidence'] = $source_found ? '元画像は存在しますが、切り抜き・拡大・寸法不明のため自動再生成しません。' : '記録された原画像・scaled画像がありません。バックアップが必要です。';
		return $row;
	}

	public static function dimensions( int $id, string $relative ): ?array {
		if ( 'attachment' !== get_post_type( $id ) ) { return null; }
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
			if ( $relative === $meta['file'] ) { return array( 'width' => (int) ( $meta['width'] ?? 0 ), 'height' => (int) ( $meta['height'] ?? 0 ), 'legacy' => false ); }
			$prefix = '.' === dirname( $meta['file'] ) ? '' : dirname( $meta['file'] ) . '/';
			foreach ( $meta['sizes'] ?? array() as $size ) {
				if ( $relative === $prefix . ( $size['file'] ?? '' ) ) { return array( 'width' => (int) $size['width'], 'height' => (int) $size['height'], 'legacy' => false ); }
			}
		}
		foreach ( self::lineage( $id ) as $source ) {
			$stem = preg_replace( '/\.[^.\/]+$/', '', $source );
			$ext = pathinfo( $source, PATHINFO_EXTENSION );
			if ( preg_match( '~^' . preg_quote( $stem, '~' ) . '-([1-9][0-9]{0,4})x([1-9][0-9]{0,4})\.' . preg_quote( $ext, '~' ) . '$~i', $relative, $m ) ) {
				return array( 'width' => (int) $m[1], 'height' => (int) $m[2], 'legacy' => true );
			}
		}
		return null;
	}

	public static function snapshot( int $post_id, int $id ): array {
		global $wpdb;
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_status FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
		$meta = static function ( int $object_id, string $key ) use ( $wpdb ) {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1", $object_id, $key ) );
			return null === $value ? '' : maybe_unserialize( $value );
		};
		return array( 'content' => $post->post_content ?? '', 'post_status' => $post->post_status ?? false, 'metadata' => $meta( $id, '_wp_attachment_metadata' ), 'attached_file' => $meta( $id, '_wp_attached_file' ), 'thumbnail' => $meta( $post_id, '_thumbnail_id' ), 'original_metadata' => $meta( $id, '_node_image_original_metadata' ), 'ic_original' => $meta( $id, '_node_ic_original_file' ), 'redirect_from' => $meta( $id, '_node_ic_redirect_from' ) );
	}

	/** Write journal before publication. Exclusive create prevents overwriting existing files. */
	public static function repair( array $row, string $journal_key ): array {
		$existing = get_option( $journal_key );
		$target = self::path( $row['relative'] );
		// A crash after publication but before the final journal must be recoverable.
		if ( is_array( $existing ) && 'prepared' === $existing['state'] && $target && is_file( $target ) ) {
			if ( hash_file( 'sha256', $target ) !== $existing['hash'] || self::snapshot( $row['post_id'], $row['id'] ) !== $row['snapshot'] ) {
				throw new RuntimeException( '競合: 公開途中のファイルまたは記事が変更されています。' );
			}
			$existing['state'] = 'done';
			self::persist( $journal_key, $existing );
			self::invalidate( $row );
			do_action( 'node_image_repaired', $row['post_id'], $row['id'], $row['url'] );
			return array( 'state' => 'done', 'generated' => $row['relative'] );
		}
		$current = self::inspect( $row );
		if ( '正常' === $current['status'] ) { return array( 'state' => 'already_ok' ); }
		if ( '修復可能' !== $current['status'] || ( $row['source_hash'] ?? '' ) !== ( $current['source_hash'] ?? '' ) || self::snapshot( $row['post_id'], $row['id'] ) !== $row['snapshot'] ) { throw new RuntimeException( '検査後に変更されたか、修復条件を満たしません。再検査してください。' ); }
		$existing = get_option( $journal_key );
		if ( is_array( $existing ) ) {
			if ( in_array( $existing['state'], array( 'prepared', 'not_published' ), true ) && ! file_exists( self::path( $current['relative'] ) ) ) { delete_option( $journal_key ); }
			else { throw new RuntimeException( '前回処理の記録があります。復元または再検査してください。' ); }
		}
		$source = self::path( $current['source'] );
		$target = self::path( $current['relative'] );
		$mime = wp_check_filetype( $target )['type'];
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) { throw new RuntimeException( '未対応の出力形式です。' ); }
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) { throw new RuntimeException( $editor->get_error_message() ); }
		$result = $editor->resize( $current['width'], $current['height'], false );
		if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
		$temp = tempnam( dirname( $target ), '.node-repair-' );
		if ( ! $temp ) { throw new RuntimeException( '一時ファイルを作成できません。' ); }
		$saved_path = $temp;
		try {
			$saved = $editor->save( $temp, $mime );
			if ( is_wp_error( $saved ) ) { throw new RuntimeException( $saved->get_error_message() ); }
			$saved_path = $saved['path'];
			$info = @getimagesize( $saved_path );
			if ( ! $info || $info[0] !== $current['width'] || $info[1] !== $current['height'] || $info['mime'] !== $mime ) { throw new RuntimeException( '生成画像の形式または寸法が一致しません。' ); }
			$journal = array( 'row' => $row, 'relative' => $current['relative'], 'hash' => hash_file( 'sha256', $saved_path ), 'state' => 'prepared', 'created_at' => gmdate( 'c' ) );
			if ( ! add_option( $journal_key, $journal, '', false ) ) { throw new RuntimeException( '変更前の記録を保存できません。' ); }
			self::persist( $journal_key, $journal );
			// Recheck after potentially expensive image processing.
			if ( self::snapshot( $row['post_id'], $row['id'] ) !== $row['snapshot'] || self::path( $current['relative'] ) !== $target ) { throw new RuntimeException( '生成中に変更を検出しました。' ); }
			if ( hash_file( 'sha256', $source ) !== $row['source_hash'] ) { throw new RuntimeException( '生成中に元画像が変更されました。' ); }
			// Set readable permissions before publishing, so interruption cannot leave a 0600 image.
			if ( ! chmod( $saved_path, 0644 ) ) { throw new RuntimeException( '公開用の権限を設定できません。' ); }
			// Atomic, no-overwrite publication within the same uploads filesystem.
			self::assert_lock();
			if ( ! @link( $saved_path, $target ) ) { throw new RuntimeException( '既存ファイルまたはファイルシステム制限により安全に公開できません。' ); }
			if ( hash_file( 'sha256', $target ) !== $journal['hash'] ) { throw new RuntimeException( '書き込みを確認できません。記録を保持しています。' ); }

			$journal['state'] = 'done';
			self::persist( $journal_key, $journal );
			self::invalidate( $row );
			do_action( 'node_image_repaired', $row['post_id'], $row['id'], $row['url'] );
			return array( 'state' => 'done', 'generated' => $current['relative'] );
		} finally {
			if ( is_file( $temp ) ) { unlink( $temp ); }
			if ( $saved_path !== $temp && is_file( $saved_path ) ) { unlink( $saved_path ); }
		}
	}

	private static function invalidate( array $row ): void {
		clean_post_cache( $row['post_id'] ); clean_post_cache( $row['id'] );
		if ( function_exists( 'wp_cache_post_change' ) ) { wp_cache_post_change( $row['post_id'] ); }
		if ( function_exists( 'w3tc_flush_post' ) ) { w3tc_flush_post( $row['post_id'] ); }
	}

	public static function restore( string $key ): array {
		$journal = get_option( $key );
		if ( ! is_array( $journal ) ) { return array( 'state' => 'not_changed', 'message' => '修復による変更はありません。' ); }
		$row = $journal['row'];
		$target = self::path( $journal['relative'] );
		if ( self::snapshot( $row['post_id'], $row['id'] ) !== $row['snapshot'] || ! $target || ( file_exists( $target ) && hash_file( 'sha256', $target ) !== $journal['hash'] ) ) { throw new RuntimeException( '競合: 修復後の本文・メタデータ・ファイルの変更を保持しました。' ); }
		if ( ! file_exists( $target ) ) {
			$journal['state'] = 'not_published'; $journal['retained_file'] = false;
			self::persist( $key, $journal );
			return array( 'state' => 'not_published', 'message' => '生成ファイルは存在しません。本文・添付情報への書き戻しは不要です。' );
		}
		// The repaired URL was already referenced before repair. Deleting it would
		// recreate the original failure, even when only one article uses it.
		// Do not claim file rollback: finalize the review and report retention explicitly.
		$journal['state'] = 'retained';
		$journal['retained_file'] = true;
		self::persist( $key, $journal );
		return array( 'state' => 'retained', 'message' => '本文・メタデータは未変更。共有参照を保護するため生成画像を保持しました。' );
	}
}

/** Older standalone compressor copies may win the embedded-plugin loader race.
 * Retain their files too; PHP redirects cannot protect requests served by the web server.
 */
add_filter( 'wp_delete_file', static function ( $file ) {
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
		if ( 'Node_IC_Converter' === ( $frame['class'] ?? '' ) && in_array( $frame['function'] ?? '', array( 'convert', 'restore' ), true ) ) { return ''; }
	}
	return $file;
}, PHP_INT_MAX );

// Older standalone versions expose this option but no filter for old derivative deletion.
add_filter( 'pre_option_node_ic_keep_original', static fn() => '1' );

// Neutralize destructive retries queued before switching from Node 1.4.1.
add_action( 'node_featured_webp_retry_delete', static function ( $id ): void {
	delete_post_meta( (int) $id, '_node_featured_webp_pending_delete' );
	delete_post_meta( (int) $id, '_node_featured_webp_delete_attempts' );
} );
