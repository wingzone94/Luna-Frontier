<?php
/** Administrator-only, resumable image audit. No work runs on theme activation or frontend. */
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/image-repair.php';

add_action( 'admin_menu', static function (): void {
	add_submenu_page( 'luminous-settings', '画像の検査・修復', '画像の検査・修復', 'manage_options', 'node-image-repair', 'node_image_repair_page' );
} );

function node_image_repair_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	wp_enqueue_script( 'node-image-repair', get_template_directory_uri() . '/assets/js/image-repair.js', array(), NODE_THEME_VERSION, true );
	wp_localize_script( 'node-image-repair', 'nodeImageRepair', array( 'nonce' => wp_create_nonce( 'node_image_repair' ), 'url' => admin_url( 'admin-ajax.php' ) ) );
	?>
	<div class="wrap" id="node-image-repair">
		<h1>画像の検査・修復</h1>
		<p>最初に読み取り専用の検査を実行してください。本文・公開状態・添付メタデータは変更せず、同一画像と確認できた欠損ファイルだけを追加します。検査記録と変更前の状態はデータベースに保存します。</p>
		<p>外部URLは取得せず判定不能とします。ローカルで正常でもCDN・配信設定の問題は別途確認が必要です。</p>
		<fieldset><legend>対象記事</legend>
		<?php foreach ( array( 'publish' => '公開', 'draft' => '下書き', 'future' => '予約', 'private' => '非公開' ) as $status => $label ) : ?>
		<label style="margin-right:16px"><input type="checkbox" name="status" value="<?php echo esc_attr( $status ); ?>" <?php checked( 'publish', $status ); ?>><?php echo esc_html( $label ); ?></label>
		<?php endforeach; ?>
		</fieldset>
		<p><button class="button button-primary" data-task="start">新しい検査</button> <button class="button" data-task="scan">検査を再開</button> <button class="button" data-task="repair">結果を確認して修復／再開</button> <button class="button" data-task="restore">復元確認／再開</button> <button class="button" data-task="stop">一時停止</button></p>
		<p>復元は修復後の編集との競合を検出します。この版は本文・メタデータを変更しないため、それらの書き戻しは不要です。他の記事からの参照を壊さないよう生成ファイルは保持します。</p>
		<p id="node-image-progress" role="status" aria-live="polite"></p>
		<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>記事</th><th>画像URL</th><th>添付ID</th><th>分類</th><th>判定</th><th>修復候補と根拠</th><th>処理結果</th></tr></thead><tbody id="node-image-results"></tbody></table></div>
		<p><button class="button" data-task="previous">前の結果</button> <button class="button" data-task="next">次の結果</button></p>
	</div>
	<?php
}

function node_image_repair_ajax(): void {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( '管理権限が必要です。', 403 ); }
	check_ajax_referer( 'node_image_repair', 'nonce' );
	$task = sanitize_key( $_POST['task'] ?? 'status' );
	$job = get_option( Node_Image_Repair::JOB );
	// Do not expire a lock while a worker could still be writing files.
	$lock = 'node_image_repair_worker';
	$token = wp_generate_uuid4();
	if ( ! add_option( $lock, array( 'token' => $token, 'started' => time() ), '', false ) ) {
		wp_send_json_error( '処理中です。異常終了でロックが残った場合は実行プロセスが終了したことを確認し、管理者が node_image_repair_worker オプションを削除してください。', 409 );
	}
	try {
		global $wpdb;
		if ( 'start' === $task ) {
			$statuses = array_values( array_intersect( array( 'publish', 'draft', 'future', 'private' ), array_map( 'sanitize_key', (array) ( $_POST['statuses'] ?? array( 'publish' ) ) ) ) );
			if ( ! $statuses ) { throw new RuntimeException( '対象の公開状態を選択してください。' ); }
			$job = array( 'id' => str_replace( '-', '', wp_generate_uuid4() ), 'statuses' => $statuses, 'cursor' => 0, 'scanned' => 0, 'count' => 0, 'repair_cursor' => 0, 'restore_cursor' => 0, 'phase' => 'scan', 'max_id' => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) );
			// Previous runs and journals intentionally retained under their immutable run IDs.
			update_option( Node_Image_Repair::JOB, $job, false );
		} elseif ( ! is_array( $job ) ) { throw new RuntimeException( 'まず検査を開始してください。' ); }
		if ( 'scan' === $task ) {
			if ( 'scan' !== $job['phase'] ) { throw new RuntimeException( '検査は完了しています。' ); }
			$placeholders = implode( ',', array_fill( 0, count( $job['statuses'] ), '%s' ) );
			$args = array_merge( array( $job['cursor'], $job['max_id'] ), $job['statuses'] );
			$posts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type = 'post' AND post_status IN ($placeholders) ORDER BY ID LIMIT 3", $args ) );
			foreach ( $posts as $raw ) {
				$post = new WP_Post( $raw );
				$seen = array();
				foreach ( Node_Image_Repair::references( $post ) as $ref ) {
					$key = $ref['url'] . ':' . $ref['id'];
					if ( isset( $seen[ $key ] ) ) { continue; } $seen[ $key ] = true;
					$row = Node_Image_Repair::inspect( $ref );
					if ( '正常' === $row['status'] ) { continue; }
					$row['post_id'] = $post->ID; $row['title'] = $post->post_title;
					$row['snapshot'] = Node_Image_Repair::snapshot( $post->ID, $row['id'] );
					$row['result'] = '';
					update_option( 'node_ir_' . $job['id'] . '_' . $job['count'], $row, false );
					++$job['count'];
				}
				$job['cursor'] = $post->ID; ++$job['scanned'];
				update_option( Node_Image_Repair::JOB, $job, false );
			}
			if ( count( $posts ) < 3 ) { $job['phase'] = 'review'; }
		}
		if ( in_array( $task, array( 'repair', 'restore' ), true ) ) {
			if ( 'scan' === $job['phase'] ) { throw new RuntimeException( '検査完了後、結果を確認してから実行してください。' ); }
			$cursor_key = $task . '_cursor';
			$index = $job[ $cursor_key ];
			if ( $index < $job['count'] ) {
				$key = 'node_ir_' . $job['id'] . '_' . $index;
				$row = get_option( $key );
				try {
					if ( 'restore' === $task ) {
						$result = Node_Image_Repair::restore( $key . '_journal' );
						$row['result'] = $result['message'] ?? $result['state'];
					} elseif ( '修復可能' === $row['status'] ) {
						$result = Node_Image_Repair::repair( $row, $key . '_journal' );
						$row['result'] = $result['state'];
					}
				} catch ( Throwable $error ) { $row['result'] = '失敗: ' . $error->getMessage(); }
				update_option( $key, $row, false ); ++$job[ $cursor_key ];
			}
			$job['phase'] = $job[ $cursor_key ] >= $job['count'] ? $task . '_complete' : $task;
		}
		update_option( Node_Image_Repair::JOB, $job, false );
		$offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
		$rows = array();
		for ( $i = $offset; $i < min( $offset + 50, $job['count'] ); ++$i ) {
			$row = get_option( 'node_ir_' . $job['id'] . '_' . $i );
			unset( $row['snapshot'] ); $rows[] = $row;
		}
		$response = array( 'job' => $job, 'rows' => $rows );
	} catch ( Throwable $error ) { $failure = $error->getMessage(); }
	finally { delete_option( $lock ); }
	if ( isset( $failure ) ) { wp_send_json_error( $failure ); }
	wp_send_json_success( $response );
}
add_action( 'wp_ajax_node_image_repair', 'node_image_repair_ajax' );
