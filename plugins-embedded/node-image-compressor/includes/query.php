<?php
/**
 * 対象アイキャッチの抽出と集計。
 *
 * 全投稿を WP_Query で回すと重いので、_thumbnail_id のユニーク集合を 1 クエリで取る。
 *
 * @package Node_Image_Compressor
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_ic_get_target_ids' ) ) {
	/**
	 * アイキャッチとして使われているアタッチメント ID の一覧（新しい順）。
	 *
	 * @return int[]
	 */
	function node_ic_get_target_ids(): array {
		$cached = wp_cache_get( 'node_ic_target_ids', 'node-image-compressor' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p
			     ON p.ID = pm.meta_value AND p.post_type = 'attachment'
			 WHERE pm.meta_key = '_thumbnail_id'
			   AND p.post_mime_type LIKE 'image/%'
			 ORDER BY p.ID DESC"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$ids = array_map( 'intval', (array) $ids );

		wp_cache_set( 'node_ic_target_ids', $ids, 'node-image-compressor', 5 * MINUTE_IN_SECONDS );

		return $ids;
	}
}

if ( ! function_exists( 'node_ic_flush_target_cache' ) ) {
	/**
	 * 置き換え・復元のたびに一覧キャッシュを捨てる。
	 */
	function node_ic_flush_target_cache(): void {
		wp_cache_delete( 'node_ic_target_ids', 'node-image-compressor' );
	}
}

if ( ! function_exists( 'node_ic_get_status' ) ) {
	/**
	 * 1 件ぶんの表示用ステータス。
	 *
	 * @return array{state:string,label:string,reason:string,before:int,after:int,saved:int,rate:float,restorable:bool}
	 */
	function node_ic_get_status( int $attachment_id ): array {
		$before = (int) get_post_meta( $attachment_id, Node_IC_Converter::META_ORIGINAL_BYTES, true );
		$after  = (int) get_post_meta( $attachment_id, Node_IC_Converter::META_WEBP_BYTES, true );
		$saved  = max( 0, $before - $after );
		$rate   = $before > 0 ? ( $saved / $before ) * 100 : 0.0;

		if ( Node_IC_Converter::is_converted( $attachment_id ) ) {
			return array(
				'state'      => 'converted',
				'label'      => '置き換え済み',
				'reason'     => Node_IC_Converter::is_restorable( $attachment_id ) ? '' : '元ファイルは削除済みです。',
				'before'     => $before,
				'after'      => $after,
				'saved'      => $saved,
				'rate'       => $rate,
				'restorable' => Node_IC_Converter::is_restorable( $attachment_id ),
			);
		}

		$skip = Node_IC_Converter::get_skip_reason( $attachment_id );
		if ( '' !== $skip ) {
			return array(
				'state'  => 'skipped',
				'label'  => 'スキップ',
				'reason' => $skip,
				'before' => 0,
				'after'  => 0,
				'saved'  => 0,
				'rate'   => 0.0,
				'restorable' => false,
			);
		}

		$reason = '';
		if ( Node_IC_Converter::can_convert( $attachment_id, $reason ) ) {
			return array(
				'state'  => 'pending',
				'label'  => '未置き換え',
				'reason' => '',
				'before' => 0,
				'after'  => 0,
				'saved'  => 0,
				'rate'   => 0.0,
				'restorable' => false,
			);
		}

		return array(
			'state'  => 'ineligible',
			'label'  => '対象外',
			'reason' => $reason,
			'before' => 0,
			'after'  => 0,
			'saved'  => 0,
			'rate'   => 0.0,
			'restorable' => false,
		);
	}
}

if ( ! function_exists( 'node_ic_get_summary' ) ) {
	/**
	 * サマリーカード用の集計。
	 *
	 * @return array{total:int,converted:int,pending:int,skipped:int,ineligible:int,saved:int,before:int,pending_ids:int[]}
	 */
	function node_ic_get_summary(): array {
		$summary = array(
			'total'       => 0,
			'converted'   => 0,
			'pending'     => 0,
			'skipped'     => 0,
			'ineligible'  => 0,
			'saved'       => 0,
			'before'      => 0,
			'pending_ids' => array(),
		);

		foreach ( node_ic_get_target_ids() as $id ) {
			$status = node_ic_get_status( $id );
			++$summary['total'];

			if ( isset( $summary[ $status['state'] ] ) ) {
				++$summary[ $status['state'] ];
			}

			$summary['saved']  += $status['saved'];
			$summary['before'] += $status['before'];

			if ( 'pending' === $status['state'] ) {
				$summary['pending_ids'][] = $id;
			}
		}

		return $summary;
	}
}

if ( ! function_exists( 'node_ic_get_using_posts' ) ) {
	/**
	 * そのアイキャッチを使っている投稿（先頭 3 件まで）。
	 *
	 * @return WP_Post[]
	 */
	function node_ic_get_using_posts( int $attachment_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d
				   AND p.post_status != 'trash'
				 LIMIT 3",
				$attachment_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$posts = array();
		foreach ( (array) $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}
}
