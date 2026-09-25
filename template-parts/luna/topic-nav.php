<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — Topic Nav
 *
 * ヘッダーの真下に置く、主要トピックと特集への導線。
 *
 * SPOTLIGHT は最大4特集と過去特集リンク、おすすめトピックと同じ行・同じ書式（アイコン＋ラベル）で表示。
 *
 * SPOTLIGHT はここへ統合したので、ホームの独立セクションは表示しない
 * （同じリンクを 1 ページに二度出さない。CSS 側で非表示にしている）。
 *
 * トピックの項目は管理画面（外観 → メニュー）の「トピック（Luna Frontier）」で編集できる。
 * 未設定のあいだは記事数の多い上位カテゴリで自動的に埋める。
 *
 * ブランドクロームの一部なので Dynamic Color は流し込まない（§23 / §38）。
 * リンクの羅列なので JS は使わない。
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * トピック名から Material Symbols のアイコン名を決める。
 *
 * スラッグは日本語がパーセントエンコードされていて鍵に使えないため、
 * 表示名のキーワードで判定する。`luna_frontier_topic_icon` で差し替え可能。
 */
function luna_frontier_topic_icon( string $label ): string {
	$map = array(
		'AI'         => 'auto_awesome', // Gemini 風の 4 方向スパークル
		'ゲーム'     => 'sports_esports',
		'ガジェット' => 'devices',
		'ニュース'   => 'newspaper',
		'スマート'   => 'smartphone',
		'PC'         => 'computer',
		'音楽'       => 'music_note',
		'動画'       => 'movie',
		'小説'       => 'menu_book',
		'フード'     => 'restaurant',
		'雑記'       => 'edit_note',
	);

	$icon = 'label';

	foreach ( $map as $needle => $candidate ) {
		if ( false !== mb_stripos( $label, $needle ) ) {
			$icon = $candidate;
			break;
		}
	}

	return (string) apply_filters( 'luna_frontier_topic_icon', $icon, $label );
}

$lf_topics     = array();
$lf_gadget     = get_term_by( 'name', 'ガジェット', 'category' );
$lf_gadget_id  = $lf_gadget instanceof WP_Term ? (int) $lf_gadget->term_id : 0;
$lf_gadget_url = $lf_gadget_id ? get_category_link( $lf_gadget_id ) : '';

if ( has_nav_menu( 'luna_topics' ) ) {
	$lf_menu_id = (int) ( get_nav_menu_locations()['luna_topics'] ?? 0 );

	foreach ( (array) wp_get_nav_menu_items( $lf_menu_id ) as $lf_item ) {
		if ( ! $lf_item instanceof WP_Post || (int) $lf_item->menu_item_parent ) {
			continue;
		}

		// This menu is shared with other locations; exclude only in this navigation.
		if ( 'ガジェット' === trim( (string) $lf_item->title )
			|| ( 'taxonomy' === $lf_item->type && 'category' === $lf_item->object && $lf_gadget_id && $lf_gadget_id === (int) $lf_item->object_id )
			|| ( is_string( $lf_gadget_url ) && '' !== $lf_gadget_url && untrailingslashit( $lf_gadget_url ) === untrailingslashit( $lf_item->url ) ) ) {
			continue;
		}

		$lf_topics[] = array(
			'label'   => (string) $lf_item->title,
			'url'     => (string) $lf_item->url,
			'current' => ( 'taxonomy' === $lf_item->type && is_category( (int) $lf_item->object_id ) ),
		);
	}
} else {
	$lf_terms = get_categories(
		array(
			'parent'     => 0,
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 6,
			'exclude'    => array_filter( array( (int) get_option( 'default_category' ), $lf_gadget_id ) ),
		)
	);

	foreach ( $lf_terms as $lf_term ) {
		$lf_topics[] = array(
			'label'   => $lf_term->name,
			'url'     => (string) get_category_link( $lf_term ),
			'current' => is_category( $lf_term->term_id ),
		);
	}
}

$lf_spotlight = function_exists( 'node_get_spotlight_categories' ) ? node_get_spotlight_categories() : array();
$lf_spotlight_url = function_exists( 'node_get_spotlight_url' ) ? node_get_spotlight_url() : '';

if ( empty( $lf_topics ) && empty( $lf_spotlight ) && '' === $lf_spotlight_url ) {
	return;
}
?>
<nav class="lf-topic-nav" aria-label="主要トピックと特集">
	<div class="lf-topic-nav__inner">

		<?php if ( ! empty( $lf_spotlight ) || '' !== $lf_spotlight_url ) : ?>
			<div class="lf-topic-nav__group lf-topic-nav__group--spotlight">
				<span class="lf-topic-nav__pick">SPOTLIGHT</span>

				<?php if ( ! empty( $lf_spotlight ) ) : ?>
				<ul class="lf-topic-nav__features">
					<?php foreach ( array_slice( $lf_spotlight, 0, 4 ) as $lf_feature ) : ?>
						<?php $lf_feature_current = '' !== (string) ( $lf_feature['slug'] ?? '' ) && is_category( (string) $lf_feature['slug'] ); ?>
						<li class="lf-topic-nav__item<?php echo $lf_feature_current ? ' is-current' : ''; ?>">
							<a href="<?php echo esc_url( (string) $lf_feature['url'] ); ?>"<?php echo $lf_feature_current ? ' aria-current="page"' : ''; ?>>
								<span class="material-symbols-outlined lf-topic-nav__icon" aria-hidden="true">local_fire_department</span>
								<span class="lf-topic-nav__label"><?php echo esc_html( (string) $lf_feature['name'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<?php if ( '' !== $lf_spotlight_url ) : ?>
					<a class="lf-topic-nav__past" href="<?php echo esc_url( $lf_spotlight_url ); ?>" aria-label="<?php esc_attr_e( 'スポットライトアーカイブ', 'node' ); ?>" title="<?php esc_attr_e( 'スポットライトアーカイブ', 'node' ); ?>">
						<span aria-hidden="true">…</span>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $lf_topics ) ) : ?>
			<div class="lf-topic-nav__group lf-topic-nav__group--topics">
				<span class="lf-topic-nav__pick lf-topic-nav__pick--editors">おすすめ</span>
			<ul class="lf-topic-nav__list">
				<?php foreach ( $lf_topics as $lf_topic ) : ?>
					<li class="lf-topic-nav__item<?php echo $lf_topic['current'] ? ' is-current' : ''; ?>">
						<a href="<?php echo esc_url( $lf_topic['url'] ); ?>"<?php echo $lf_topic['current'] ? ' aria-current="page"' : ''; ?>>
							<span class="material-symbols-outlined lf-topic-nav__icon" aria-hidden="true"><?php echo esc_html( luna_frontier_topic_icon( $lf_topic['label'] ) ); ?></span>
							<span class="lf-topic-nav__label"><?php echo esc_html( $lf_topic['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			</div>
		<?php endif; ?>
	</div>
</nav>
