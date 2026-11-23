<?php
/**
 * ファクトチェック表示ヘルパー（管理画面・フロント共通）
 *
 * @package Node_AI_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_ai_is_production' ) ) {
	/**
	 * 本番環境か（cybernode.local などの検証環境では false）。
	 */
	function node_ai_is_production(): bool {
		return (bool) apply_filters( 'node_ai_is_production', 'production' === wp_get_environment_type() );
	}
}

if ( ! function_exists( 'node_ai_fact_check_status_labels' ) ) {
	/**
	 * 判定ラベル。
	 *
	 * 検証環境（local / staging）では、ネタ記事・二次創作の検証で
	 * 「不正確」とだけ出ても判断材料にならないため、
	 * 「本番に出したらどうなるか」という公開リスクの言い回しに切り替える。
	 *
	 * @return array<string, string>
	 */
	function node_ai_fact_check_status_labels(): array {
		if ( node_ai_is_production() ) {
			return array(
				'correct'          => '正確',
				'likely_correct'   => 'おそらく正確',
				'uncertain'        => '要確認',
				'likely_incorrect' => 'おそらく不正確',
				'unverifiable'     => '検証困難',
			);
		}

		return array(
			'correct'          => '正確（本番OK）',
			'likely_correct'   => 'おそらく正確（本番OK）',
			'uncertain'        => '本番では要注意',
			'likely_incorrect' => '本番ではアウト',
			'unverifiable'     => '検証困難（本番では要確認）',
		);
	}
}

if ( ! function_exists( 'node_ai_fact_check_risk_labels' ) ) {
	/**
	 * @return array<string, string>
	 */
	function node_ai_fact_check_risk_labels(): array {
		return array(
			'low'    => '低',
			'medium' => '中',
			'high'   => '高',
		);
	}
}

if ( ! function_exists( 'node_ai_get_fact_check_data' ) ) {
	/**
	 * 投稿のファクトチェックデータを取得
	 *
	 * @param int $post_id 投稿ID。
	 * @return array<string, mixed>|null
	 */
	function node_ai_get_fact_check_data( int $post_id ): ?array {
		$stored = get_post_meta( $post_id, '_node_ai_fact_check', true );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$data = json_decode( $stored, true );
		if ( ! is_array( $data ) || empty( $data['claims'] ) ) {
			return null;
		}

		return $data;
	}
}

if ( ! function_exists( 'node_ai_is_fact_check_public' ) ) {
	/**
	 * フロント公開可能か
	 *
	 * @param int $post_id 投稿ID。
	 */
	function node_ai_is_fact_check_public( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, '_node_ai_fact_check_approved', true )
			&& null !== node_ai_get_fact_check_data( $post_id );
	}
}

if ( ! function_exists( 'node_ai_extract_grounding_sources' ) ) {
	/**
	 * Gemini Grounding メタデータから参照元を抽出
	 *
	 * @param array<string, mixed> $grounding Grounding メタデータ。
	 * @return array<int, array<string, string>>
	 */
	function node_ai_extract_grounding_sources( array $grounding ): array {
		$sources = array();
		$seen    = array();

		foreach ( (array) ( $grounding['groundingChunks'] ?? array() ) as $chunk ) {
			if ( ! is_array( $chunk ) || empty( $chunk['web']['uri'] ) ) {
				continue;
			}

			$url = esc_url_raw( (string) $chunk['web']['uri'] );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ]  = true;
			$sources[]     = array(
				'title' => sanitize_text_field( (string) ( $chunk['web']['title'] ?? '' ) ),
				'url'   => $url,
			);
		}

		return $sources;
	}
}

if ( ! function_exists( 'node_ai_render_fact_check_sources' ) ) {
	/**
	 * 参照元リストを出力
	 *
	 * @param array<int, array<string, string>> $sources 参照元。
	 * @param string                            $context admin|front。
	 */
	function node_ai_render_fact_check_sources( array $sources, string $context = 'admin' ): void {
		if ( empty( $sources ) ) {
			return;
		}

		$class = 'front' === $context ? 'm3-fact-check__sources' : 'node-fact-check__sources';
		echo '<div class="' . esc_attr( $class ) . '">';
		// 出典は Google 検索由来とは限らない（記事内リンク・自動発見した公式ページも入る）
		echo '<p class="' . esc_attr( $class ) . '-label"><strong>' . esc_html__( '参照元', 'node-ai-tools' ) . '</strong></p>';
		echo '<ul class="' . esc_attr( $class ) . '-list">';
		foreach ( $sources as $source ) {
			$title = ! empty( $source['title'] ) ? $source['title'] : $source['url'];
			echo '<li><a href="' . esc_url( $source['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
			if ( ! empty( $source['official'] ) ) {
				echo ' <span class="' . esc_attr( $class ) . '-official">' . esc_html__( '（公式）', 'node-ai-tools' ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}
}

if ( ! function_exists( 'node_ai_render_fact_check_results' ) ) {
	/**
	 * 管理画面向けファクトチェック結果
	 *
	 * @param array<string, mixed> $data チェック結果。
	 */
	function node_ai_render_fact_check_results( array $data ): void {
		$status_labels = node_ai_fact_check_status_labels();
		$risk_labels   = node_ai_fact_check_risk_labels();
		$risk          = (string) ( $data['overall_risk'] ?? 'medium' );
		$risk_label    = $risk_labels[ $risk ] ?? $risk;

		echo '<div class="node-fact-check__results">';
		echo '<p class="node-fact-check__summary"><strong>全体所見:</strong> ' . esc_html( (string) ( $data['summary'] ?? '' ) ) . '</p>';
		echo '<p class="node-fact-check__risk"><strong>リスク:</strong> <span class="node-fact-check__risk-badge node-fact-check__risk-badge--' . esc_attr( $risk ) . '">' . esc_html( $risk_label ) . '</span></p>';

		if ( ! empty( $data['grounded'] ) ) {
			echo '<p class="node-fact-check__grounded"><small>' . esc_html__( 'Google Search Grounding 有効', 'node-ai-tools' ) . '</small></p>';
		}

		if ( ! empty( $data['guidelines_used'] ) ) {
			echo '<p class="node-fact-check__grounded"><small>' . esc_html__( 'Luminous Core ガイドライン参照済み', 'node-ai-tools' ) . '</small></p>';
		}

		// 実行時刻と使用モデルは、どちらか片方しか無い保存データでも表示できるようにする
		$meta_parts = array();
		if ( isset( Node_AI_Core::FACT_CHECK_PROVIDERS[ $data['provider'] ?? '' ] ) ) {
			$meta_parts[] = '接続先: ' . Node_AI_Core::FACT_CHECK_PROVIDERS[ $data['provider'] ];
		}
		if ( ! empty( $data['checked_at'] ) ) {
			$meta_parts[] = '最終チェック: ' . (string) $data['checked_at'];
		}
		if ( ! empty( $data['model'] ) ) {
			$meta_parts[] = 'モデル: ' . (string) $data['model'];
		}
		if ( ! empty( $meta_parts ) ) {
			echo '<p class="node-fact-check__meta"><small>' . esc_html( implode( ' / ', $meta_parts ) ) . '</small></p>';
		}

		// 検索なしで実行した・利用枠に達した等の実行条件は、判定の読み方に影響するため必ず出す。
		foreach ( (array) ( $data['notices'] ?? array() ) as $notice ) {
			echo '<p class="node-fact-check__notice"><small>' . esc_html( (string) $notice ) . '</small></p>';
		}

		echo '<ul class="node-fact-check__claims">';
		foreach ( (array) ( $data['claims'] ?? array() ) as $claim ) {
			if ( ! is_array( $claim ) ) {
				continue;
			}
			$status      = (string) ( $claim['status'] ?? 'uncertain' );
			$status_text = $status_labels[ $status ] ?? $status;
			echo '<li class="node-fact-check__claim node-fact-check__claim--' . esc_attr( $status ) . '">';
			echo '<p class="node-fact-check__claim-text">' . esc_html( (string) ( $claim['claim'] ?? '' ) ) . '</p>';
			echo '<p class="node-fact-check__claim-meta">';
			echo '<span class="node-fact-check__status">' . esc_html( $status_text ) . '</span>';
			echo ' / 確信度: ' . esc_html( (string) ( $claim['confidence'] ?? '' ) );
			if ( 'opinion' === (string) ( $claim['claim_type'] ?? '' ) ) {
				echo ' / ' . esc_html__( '筆者の感想（事実判定の対象外）', 'node-ai-tools' );
			}
			echo '</p>';
			if ( ! empty( $claim['note'] ) ) {
				echo '<p class="node-fact-check__claim-note">' . esc_html( (string) $claim['note'] ) . '</p>';
			}
			echo '</li>';
		}
		echo '</ul>';

		node_ai_render_fact_check_sources( (array) ( $data['sources'] ?? array() ), 'admin' );
		echo '</div>';
	}
}

if ( ! function_exists( 'node_ai_render_fact_check_front' ) ) {
	/**
	 * フロント向け M3 Expressive ファクトチェック表示
	 *
	 * @param int $post_id 投稿ID。
	 */
	function node_ai_render_fact_check_front( int $post_id ): void {
		if ( ! node_ai_is_fact_check_public( $post_id ) ) {
			return;
		}

		$data          = node_ai_get_fact_check_data( $post_id );
		if ( null === $data ) {
			return;
		}

		$status_labels = node_ai_fact_check_status_labels();
		$risk_labels   = node_ai_fact_check_risk_labels();
		$risk          = (string) ( $data['overall_risk'] ?? 'medium' );
		$risk_label    = $risk_labels[ $risk ] ?? $risk;
		?>
		<details class="m3-fact-check m3-reveal">
			<summary class="m3-fact-check__toggle">
				<span class="m3-fact-check__toggle-inner">
					<span class="material-symbols-outlined m3-fact-check__icon" data-icon="verified" aria-hidden="true"></span>
					<span class="m3-fact-check__label"><?php esc_html_e( 'Fact Check', 'node-ai-tools' ); ?></span>
					<span class="m3-fact-check__risk m3-fact-check__risk--<?php echo esc_attr( $risk ); ?>">
						<?php echo esc_html( sprintf( __( 'リスク: %s', 'node-ai-tools' ), $risk_label ) ); ?>
					</span>
					<span class="material-symbols-outlined m3-fact-check__chevron m3-fact-check__chevron--more" data-icon="expand_more" aria-hidden="true"></span>
					<span class="material-symbols-outlined m3-fact-check__chevron m3-fact-check__chevron--less" data-icon="expand_less" aria-hidden="true"></span>
				</span>
			</summary>
			<div class="m3-fact-check__body">
				<p class="m3-fact-check__disclaimer">
					<?php esc_html_e( 'AIによる確認箇所の抽出支援です。編集者が確認済みの参考情報ですが、最終的な正確性は原文・公式情報をご確認ください。', 'node-ai-tools' ); ?>
				</p>
				<?php foreach ( (array) ( $data['notices'] ?? array() ) as $notice ) : ?>
					<p class="m3-fact-check__disclaimer"><?php echo esc_html( (string) $notice ); ?></p>
				<?php endforeach; ?>
				<?php if ( ! empty( $data['summary'] ) ) : ?>
					<p class="m3-fact-check__overview"><?php echo esc_html( (string) $data['summary'] ); ?></p>
				<?php endif; ?>
				<ul class="m3-fact-check__claims">
					<?php foreach ( (array) ( $data['claims'] ?? array() ) as $claim ) : ?>
						<?php
						if ( ! is_array( $claim ) ) {
							continue;
						}
						$status      = (string) ( $claim['status'] ?? 'uncertain' );
						$status_text = $status_labels[ $status ] ?? $status;
						?>
						<li class="m3-fact-check__claim m3-fact-check__claim--<?php echo esc_attr( $status ); ?>">
							<p class="m3-fact-check__claim-text"><?php echo esc_html( (string) ( $claim['claim'] ?? '' ) ); ?></p>
							<p class="m3-fact-check__claim-meta">
								<span class="m3-fact-check__status"><?php echo esc_html( $status_text ); ?></span>
								<span class="m3-fact-check__confidence"><?php echo esc_html( sprintf( __( '確信度: %s', 'node-ai-tools' ), (string) ( $claim['confidence'] ?? '' ) ) ); ?></span>
							</p>
							<?php if ( ! empty( $claim['note'] ) ) : ?>
								<p class="m3-fact-check__claim-note"><?php echo esc_html( (string) $claim['note'] ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php node_ai_render_fact_check_sources( (array) ( $data['sources'] ?? array() ), 'front' ); ?>
				<footer class="m3-fact-check__footer">
					<?php if ( ! empty( $data['grounded'] ) ) : ?>
						<span class="m3-fact-check__badge">
							<span class="material-symbols-outlined" data-icon="travel_explore" aria-hidden="true"></span>
							Google Search Grounding
						</span>
					<?php endif; ?>
					<?php if ( ! empty( $data['guidelines_used'] ) ) : ?>
						<span class="m3-fact-check__badge m3-fact-check__badge--guidelines">
							<span class="material-symbols-outlined" data-icon="menu_book" aria-hidden="true"></span>
							<?php esc_html_e( 'ガイドライン参照', 'node-ai-tools' ); ?>
						</span>
					<?php endif; ?>
					<span class="m3-fact-check__credit">by <?php echo esc_html( Node_AI_Core::FACT_CHECK_PROVIDERS[ $data['provider'] ?? 'gemini' ] ?? 'AI' ); ?></span>
					<?php if ( ! empty( $data['checked_at'] ) ) : ?>
						<time class="m3-fact-check__date" datetime="<?php echo esc_attr( (string) $data['checked_at'] ); ?>">
							<?php echo esc_html( (string) $data['checked_at'] ); ?>
						</time>
					<?php endif; ?>
				</footer>
			</div>
		</details>
		<?php
	}
}
