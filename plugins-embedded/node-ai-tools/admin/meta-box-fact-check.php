<?php
/**
 * ファクトチェックメタボックス UI
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param WP_Post $post 投稿オブジェクト。
 */
function node_ai_render_fact_check_meta_box( WP_Post $post ): void {
	$data = node_ai_get_fact_check_data( $post->ID );
	$approved = (bool) get_post_meta( $post->ID, '_node_ai_fact_check_approved', true );
	$auto_error = (string) get_post_meta( $post->ID, '_node_ai_fact_check_error', true );
	$has_key = function_exists( 'node_ai_author_has_api_key' ) && node_ai_author_has_api_key( (int) $post->post_author );

	wp_nonce_field( 'node_ai_fact_check_action', 'node_ai_fact_check_nonce' );
	?>
	<div class="node-fact-check-meta-box">
		<p class="description">
			選択したAIとLuminous Coreガイドラインによるファクトチェック補助です。
			これは確認箇所の抽出支援であり、真偽の最終判定は必ず人間の編集者が行ってください。
			「編集者が結果を確認済み」にチェックを入れると、記事ページ（1ページ目）に公開表示されます。
		</p>
		<?php if ( $has_key ) : ?>
			<p class="description">
				下書き保存すると自動でファクトチェックが実行されます（約1分後に結果が保存されます。再読み込みで表示）。
				<strong>公開前の実行を推奨します（未実施でも公開はできます）。</strong>
			</p>
		<?php endif; ?>
		<?php if ( '' !== $auto_error ) : ?>
			<p class="node-fact-check__auto-error" style="color:#d63638;">
				前回の自動実行が失敗しました: <?php echo esc_html( $auto_error ); ?>
			</p>
		<?php endif; ?>

		<div id="node_fact_check_results">
			<?php
			if ( null !== $data ) {
				node_ai_render_fact_check_results( $data );
			} else {
				echo '<p class="node-fact-check__empty">まだファクトチェックは実行されていません。</p>';
			}
			?>
		</div>

		<p>
			<label>
				<input type="checkbox" name="node_ai_fact_check_approved" value="1" <?php checked( $approved ); ?> />
				編集者が結果を確認済み（フロント公開）
			</label>
		</p>
        
        <?php
        $fc_provider = node_ai_core()->get_fact_check_provider_id();
        echo '<p><label for="node-ai-fc-runtime">今回の接続先</label><br><select id="node-ai-fc-runtime" style="max-width:100%">';
        foreach ( Node_AI_Core::FACT_CHECK_PROVIDERS as $id => $label ) {
            echo '<option value="' . esc_attr( $id ) . '" ' . selected( $fc_provider, $id, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';
        echo '<p id="node-ai-fc-runtime-description" class="description">' . esc_html( Node_AI_Core::fact_check_provider_description( $fc_provider ) ) . '</p>';
        echo '<p class="description">GeminiのモデルはLuna AIの無料枠ポリシーで選択します。Ollama / OpenAI互換は各接続設定を使います。</p>';

        ?>

		<p>
			<button type="button" id="node_fact_check_btn" class="button button-secondary" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
				ファクトチェックを実行
			</button>
			<span id="node_fact_check_status" style="margin-left:10px;font-weight:bold;"></span>
		</p>
	</div>

	<style>
		.node-fact-check-meta-box .node-fact-check__claims,
		.node-fact-check-meta-box .node-fact-check__sources-list {
			margin: 12px 0 0;
			padding: 0;
			list-style: none;
		}
		.node-fact-check-meta-box .node-fact-check__sources-list { list-style: disc; padding-left: 1.2rem; }
		.node-fact-check-meta-box .node-fact-check__claim {
			margin: 0 0 10px;
			padding: 12px;
			border: 1px solid #dcdcde;
			border-radius: 8px;
			background: #fff;
		}
		.node-fact-check-meta-box .node-fact-check__claim--correct { border-left: 4px solid #007017; }
		.node-fact-check-meta-box .node-fact-check__claim--likely_correct { border-left: 4px solid #00a32a; }
		.node-fact-check-meta-box .node-fact-check__claim--uncertain { border-left: 4px solid #dba617; }
		.node-fact-check-meta-box .node-fact-check__claim--likely_incorrect { border-left: 4px solid #d63638; }
		.node-fact-check-meta-box .node-fact-check__claim--unverifiable { border-left: 4px solid #787c82; }
		.node-fact-check-meta-box .node-fact-check__claim-text { margin: 0 0 6px; font-weight: 600; }
		.node-fact-check-meta-box .node-fact-check__claim-meta { margin: 0; font-size: 12px; color: #50575e; }
		.node-fact-check-meta-box .node-fact-check__claim-note { margin: 6px 0 0; font-size: 13px; }
		.node-fact-check-meta-box .node-fact-check__risk-badge {
			display: inline-block;
			padding: 2px 8px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 700;
		}
		.node-fact-check-meta-box .node-fact-check__risk-badge--low { background: #d5f5e3; color: #1e4620; }
		.node-fact-check-meta-box .node-fact-check__risk-badge--medium { background: #fcf0cd; color: #614200; }
		.node-fact-check-meta-box .node-fact-check__risk-badge--high { background: #fce4e4; color: #611418; }
		.node-fact-check-meta-box .node-fact-check__grounded { color: #2271b1; }
	</style>

	<script>
	jQuery(function($) {
		var statusLabels = <?php echo wp_json_encode( node_ai_fact_check_status_labels(), JSON_UNESCAPED_UNICODE ); ?>;
		var riskLabels = <?php echo wp_json_encode( node_ai_fact_check_risk_labels(), JSON_UNESCAPED_UNICODE ); ?>;

		function renderSources(sources) {
			if (!sources || !sources.length) return '';
			var html = '<div class="node-fact-check__sources"><p><strong>参照元</strong></p><ul class="node-fact-check__sources-list">';
			sources.forEach(function(source) {
				var title = source.title || source.url;
				html += '<li><a href="' + $('<div>').text(source.url).html() + '" target="_blank" rel="noopener noreferrer">' + $('<div>').text(title).html() + '</a>'
					+ (source.official ? ' <span class="node-fact-check__sources-official">（公式）</span>' : '')
					+ '</li>';
			});
			html += '</ul></div>';
			return html;
		}

		function renderResults(data) {
			var html = '<div class="node-fact-check__results">';
			html += '<p class="node-fact-check__summary"><strong>全体所見:</strong> ' + $('<div>').text(data.summary || '').html() + '</p>';
			var risk = data.overall_risk || 'medium';
			html += '<p class="node-fact-check__risk"><strong>リスク:</strong> <span class="node-fact-check__risk-badge node-fact-check__risk-badge--' + risk + '">' + (riskLabels[risk] || risk) + '</span></p>';
			if (data.grounded) {
				html += '<p class="node-fact-check__grounded"><small>Google Search Grounding 有効</small></p>';
			}
			if (!data.grounded) { html += '<p><small>Google検索: なし</small></p>'; }
			if (data.provider) { html += '<p><small>接続先: ' + $('<div>').text(data.provider).html() + '</small></p>'; }
			if (data.model) { html += '<p><small>モデル: ' + $('<div>').text(data.model).html() + '</small></p>'; }
			(data.notices || []).forEach(function(notice) { html += '<p><small>' + $('<div>').text(notice).html() + '</small></p>'; });
			if (data.guidelines_used) {
				html += '<p class="node-fact-check__grounded"><small>Luminous Core ガイドライン参照済み</small></p>';
			}
			if (data.checked_at) {
				html += '<p class="node-fact-check__meta"><small>最終チェック: ' + $('<div>').text(data.checked_at).html() + '</small></p>';
			}
			html += '<ul class="node-fact-check__claims">';
			(data.claims || []).forEach(function(claim) {
				var status = claim.status || 'uncertain';
				html += '<li class="node-fact-check__claim node-fact-check__claim--' + status + '">';
				html += '<p class="node-fact-check__claim-text">' + $('<div>').text(claim.claim || '').html() + '</p>';
				html += '<p class="node-fact-check__claim-meta"><span class="node-fact-check__status">' + (statusLabels[status] || status) + '</span> / 確信度: ' + (claim.confidence || '') + '</p>';
				if (claim.note) {
					html += '<p class="node-fact-check__claim-note">' + $('<div>').text(claim.note).html() + '</p>';
				}
				html += '</li>';
			});
			html += '</ul>';
			html += renderSources(data.sources || []);
			html += '</div>';
			$('#node_fact_check_results').html(html);
			$('input[name="node_ai_fact_check_approved"]').prop('checked', false);
		}

		var providerDescriptions = <?php echo wp_json_encode( array_map( array( 'Node_AI_Core', 'fact_check_provider_description' ), array_combine( array_keys( Node_AI_Core::FACT_CHECK_PROVIDERS ), array_keys( Node_AI_Core::FACT_CHECK_PROVIDERS ) ) ), JSON_UNESCAPED_UNICODE ); ?>;
		$('#node-ai-fc-runtime').on('change', function() { $('#node-ai-fc-runtime-description').text(providerDescriptions[this.value] || ''); });

		$('#node_fact_check_btn').on('click', function(e) {
			e.preventDefault();
			var btn = $(this);
			var status = $('#node_fact_check_status');
			btn.prop('disabled', true);
			status.text('検証中... (しばらくお待ちください)').css('color', '#FF9900');


			$.post(ajaxurl, {
				action: 'node_ai_fact_check',
				post_id: btn.data('post-id'),
				fact_check_provider: $('#node-ai-fc-runtime').val(),
				nonce: $('#node_ai_fact_check_nonce').val()
			}, function(response) {
				btn.prop('disabled', false);
				if (response.success) {
					renderResults(response.data);
					status.text('完了').css('color', 'green');
				} else {
					status.text('エラー: ' + (response.data && response.data.message ? response.data.message : '不明')).css('color', 'red');
				}
			}).fail(function() {
				btn.prop('disabled', false);
				status.text('通信エラーが発生しました。').css('color', 'red');
			});
		});
	});
	</script>
	<?php
}
