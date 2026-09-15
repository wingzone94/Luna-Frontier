<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — Reading Aside / 副紙面（Phase 5）
 *
 * 指示書 §26。
 * 通常の WordPress Sidebar ではなく「記事を読むための副紙面」。
 * 優先順位: TOC → Series → Article context。
 * カテゴリは Article Header に出ているため Aside では繰り返さない。
 * Sticky にするのは原則 TOC だけ。
 *
 * DOM への差し込みは親テーマの luminous_after_article_header アクションを使う
 * （親 single.php を子でコピーせずに済ませるため）。配置は CSS Grid が担当する。
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 記事本文の直前に Reading Aside を出力する。
 */
function luna_frontier_render_reading_aside( int $post_id ): void {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$toc_items = function_exists( 'node_get_article_toc_items' )
		? node_get_article_toc_items( $post_id )
		: array();

	$series = function_exists( 'node_series_get_toc_data' )
		? node_series_get_toc_data( $post_id )
		: null;

	// カテゴリは Article Header の eyebrow に出しているので、Aside には置かない
	// （同じ情報を 1 ページに二度出さない）。

	/*
	 * 脚注は the_content フィルタ（親テーマ・優先度 999）で本文末に生成されるため、
	 * この時点ではまだ HTML が存在しない。移動先の受け皿が必要なので、
	 * 「脚注を持つ記事か」だけを投稿データから判定して Aside を出す。
	 * 実際の移動は src/luna.js が描画後に行う。
	 */
	$content     = (string) get_post_field( 'post_content', $post_id );
	$meta        = get_post_meta( $post_id, 'footnotes', true );
	$has_footnotes = ( false !== strpos( $content, 'data-fn=' ) )
		|| ( is_string( $meta ) && '' !== trim( $meta ) && '[]' !== trim( $meta ) );

	// 中身が何も無いなら空の紙を作らない（§31 の「空 Surface 禁止」と同じ方針）。
	if ( empty( $toc_items ) && empty( $series['items'] ) && ! $has_footnotes ) {
		return;
	}

	$current_page = function_exists( 'node_get_current_multipage_number' )
		? (int) node_get_current_multipage_number()
		: 1;
	?>
	<aside class="lf-reading-aside" aria-label="この記事の読み進め情報">

		<?php if ( ! empty( $toc_items ) ) : ?>
			<nav class="lf-aside-block lf-aside-block--toc" aria-labelledby="lf-aside-toc-title">
				<h2 class="lf-aside-block__title lf-system-label" id="lf-aside-toc-title">Contents</h2>
				<ol class="lf-toc">
					<?php foreach ( $toc_items as $item ) : ?>
						<?php
						$item_page = (int) ( $item['page'] ?? 1 );
						$anchor    = '#' . ltrim( (string) $item['id'], '#' );
						$href      = ( $item_page === $current_page || ! function_exists( 'node_get_multipage_url' ) )
							? $anchor
							: node_get_multipage_url( $item_page, $post_id ) . $anchor;
						?>
						<li class="lf-toc__item" data-level="<?php echo esc_attr( (string) $item['level'] ); ?>">
							<a class="lf-toc__link" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( (string) $item['text'] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ol>
			</nav>
		<?php endif; ?>

		<?php if ( ! empty( $series['items'] ) && is_array( $series['items'] ) ) : ?>
			<section class="lf-aside-block" aria-labelledby="lf-aside-series-title">
				<h2 class="lf-aside-block__title lf-system-label" id="lf-aside-series-title">Series</h2>
				<?php if ( ! empty( $series['term'] ) && $series['term'] instanceof WP_Term ) : ?>
					<p class="lf-aside-block__lead"><?php echo esc_html( $series['term']->name ); ?></p>
				<?php endif; ?>
				<ol class="lf-aside-list">
					<?php foreach ( $series['items'] as $series_item ) : ?>
						<li class="lf-aside-list__item<?php echo ! empty( $series_item['is_current'] ) ? ' is-current' : ''; ?>">
							<?php if ( ! empty( $series_item['is_current'] ) ) : ?>
								<span aria-current="true"><?php echo esc_html( (string) $series_item['title'] ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( (string) $series_item['url'] ); ?>"><?php echo esc_html( (string) $series_item['title'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>

	</aside>
	<?php
}
add_action( 'luminous_after_article_header', 'luna_frontier_render_reading_aside', 30 );
