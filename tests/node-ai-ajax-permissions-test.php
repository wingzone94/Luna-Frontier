<?php
/**
 * AI処理の投稿単位権限を検証する。
 *
 * @group ajax
 */
class Node_AI_Ajax_Permissions_Test extends WP_Ajax_UnitTestCase {
	public function test_author_cannot_run_ai_actions_on_another_authors_post(): void {
		require_once dirname( __DIR__ ) . '/plugins-embedded/node-ai-tools/includes/ajax-handlers.php';

		$owner_id  = $this->factory->user->create( array( 'role' => 'author' ) );
		$other_id  = $this->factory->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->factory->post->create(
			array(
				'post_author'  => $owner_id,
				'post_content' => '権限確認用の記事本文。',
			)
		);
		wp_set_current_user( $other_id );

		$actions = array(
			'node_generate_ai_summary' => array( 'node_ai_ajax_generate_summary', 'node_ai_generate_action' ),
			'node_ai_fact_check'      => array( 'node_ai_ajax_fact_check', 'node_ai_fact_check_action' ),
		);

		foreach ( $actions as $action => $settings ) {
			add_action( 'wp_ajax_' . $action, $settings[0] );
			$_POST = array(
				'post_id' => $post_id,
				'nonce'   => wp_create_nonce( $settings[1] ),
			);
			try {
				$this->_handleAjax( $action );
			} catch ( WPAjaxDieContinueException $e ) {
				// wp_send_json_error() の正常な終了。
			}
			$response = json_decode( $this->_last_response, true );
			$this->assertFalse( $response['success'] );
			$this->assertSame( 'この記事を編集する権限がありません。', $response['data']['message'] );
			remove_action( 'wp_ajax_' . $action, $settings[0] );
			$this->_last_response = '';
		}

		$this->assertSame( '', get_post_meta( $post_id, '_node_ai_summary', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_node_ai_fact_check', true ) );
	}

	public function test_author_can_pass_post_permission_check_on_own_post(): void {
		require_once dirname( __DIR__ ) . '/plugins-embedded/node-ai-tools/includes/ajax-handlers.php';
		$author_id = $this->factory->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->factory->post->create(
			array( 'post_author' => $author_id, 'post_content' => '権限確認用の記事本文。' )
		);
		wp_set_current_user( $author_id );
		add_action( 'wp_ajax_node_generate_ai_summary', 'node_ai_ajax_generate_summary' );
		$_POST = array( 'post_id' => $post_id, 'nonce' => wp_create_nonce( 'node_ai_generate_action' ) );

		try {
			$this->_handleAjax( 'node_generate_ai_summary' );
		} catch ( WPAjaxDieContinueException $e ) {
			// API処理前の応答を検査する。
		}
		remove_action( 'wp_ajax_node_generate_ai_summary', 'node_ai_ajax_generate_summary' );

		$response = json_decode( $this->_last_response, true );
		$this->assertNotSame( 'この記事を編集する権限がありません。', $response['data']['message'] ?? '' );
		$this->assertSame( 3, (int) get_post_meta( $post_id, '_node_ai_max_lines', true ) );
	}
}
