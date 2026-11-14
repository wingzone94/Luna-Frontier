<?php

declare(strict_types=1);
/**
 * Template part: シリーズ目次チャート（前後ナビを統合、初期は最小化）
 * 現在の投稿がどの node_series にも属さない場合は何も出力しない。
 */

if ( ! function_exists( 'node_series_get_toc_data' ) ) {
	return;
}

$node_series_post_id = get_the_ID();
$node_series_toc      = node_series_get_toc_data( $node_series_post_id );

if ( null === $node_series_toc ) {
	return;
}

$node_series_position = function_exists( 'node_series_get_position' ) ? node_series_get_position( $node_series_post_id ) : null;
$node_series_panel_id  = 'm3-series-toc-panel-' . $node_series_post_id;
$node_series_color     = function_exists( 'node_series_get_color' ) ? node_series_get_color( $node_series_post_id ) : null;
$node_series_style     = $node_series_color ? ' style="--node-series-color: ' . esc_attr( $node_series_color ) . ';"' : '';
?>
<section class="m3-article__footer-section m3-series-section" aria-label="シリーズ">
	<div class="m3-toc-card m3-series-toc" data-node-series-info<?php echo $node_series_style; ?>>
		<h3 class="m3-toc-card__title m3-series-toc__heading">
			<button
				type="button"
				class="m3-series-toc__toggle"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $node_series_panel_id ); ?>"
				aria-label="シリーズ「<?php echo esc_attr( $node_series_toc['term']->name ); ?>」の記事一覧"
			>
				<span class="m3-series-toc__toggle-icon">
					<span class="material-symbols-outlined" data-icon="auto_stories"></span>
				</span>
				<span class="m3-series-toc__toggle-label">
					<span class="m3-series-toc__series-name" data-series-name-fit><?php echo esc_html( $node_series_toc['term']->name ); ?></span>
					<?php if ( $node_series_position ) : ?>
					<span class="m3-series-toc__count"><?php echo esc_html( $node_series_position['index'] . ' / ' . $node_series_position['total'] ); ?></span>
					<?php endif; ?>
				</span>
				<?php if ( $node_series_position ) : ?>
				<span class="m3-series-toc__toggle-expanded-label">
					この記事はシリーズ第<?php echo esc_html( $node_series_position['index'] ); ?>回です。この記事に含まれているシリーズは「<?php echo esc_html( $node_series_toc['term']->name ); ?>」（全<?php echo esc_html( $node_series_position['total'] ); ?>回）です。
				</span>
				<?php endif; ?>
				<span class="material-symbols-outlined m3-series-toc__chevron" data-icon="expand_more"></span>
			</button>
		</h3>
		<div class="m3-series-toc__panel" id="<?php echo esc_attr( $node_series_panel_id ); ?>">
			<ol class="m3-series-chart">
				<?php foreach ( $node_series_toc['items'] as $node_series_index => $node_series_item ) : ?>
				<li class="m3-series-chart__step<?php echo $node_series_item['is_current'] ? ' is-current' : ''; ?>">
					<?php if ( $node_series_item['is_current'] ) : ?>
						<span class="m3-series-chart__node" aria-current="page"><?php echo esc_html( $node_series_index + 1 ); ?></span>
						<span class="m3-series-chart__label"><?php echo esc_html( $node_series_item['title'] ); ?></span>
					<?php else : ?>
						<a class="m3-series-chart__link" href="<?php echo esc_url( $node_series_item['url'] ); ?>">
							<span class="m3-series-chart__node"><?php echo esc_html( $node_series_index + 1 ); ?></span>
							<span class="m3-series-chart__label"><?php echo esc_html( $node_series_item['title'] ); ?></span>
						</a>
					<?php endif; ?>
				</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<div class="m3-series-toc__footer">
			<button
				type="button"
				class="m3-series-toc__info-toggle"
				aria-expanded="false"
				data-series-info-toggle
			>
				<span class="material-symbols-outlined m3-series-toc__info-toggle-icon" data-icon="info" aria-hidden="true"></span>
				<span class="m3-series-toc__info-toggle-label">シリーズ機能について</span>
				<span class="m3-series-toc__info-toggle-expanded-label">
					この記事はシリーズ「<?php echo esc_html( $node_series_toc['term']->name ); ?>」の一部です。番号をタップすると各回に移動できます。色のついた丸が現在表示中の回です。
				</span>
			</button>
		</div>
	</div>
</section>
