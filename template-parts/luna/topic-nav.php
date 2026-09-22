<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — Topic Nav
 *
 * ヘッダー直下に、編集部おすすめと常設カテゴリの2行を表示する。
 *
 * 編集部おすすめは SPOTLIGHT を最大4件まで優先し、空き枠をトピックで補完して
 * 合計最大6件。常設カテゴリは推薦件数に左右されず独立行で表示する。
 *
 * SPOTLIGHT は専用メニューを優先し、未設定なら親テーマの SPOTLIGHT を使う。
 * 同一URLは推薦棚の中で重複表示しない。
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * トピック名から Material Symbols のアイコン名を決める。
 */
function luna_frontier_topic_icon( string $label ): string {
	$map = array(
		'AI'         => 'auto_awesome',
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

$lf_limit         = max( 1, min( 6, (int) apply_filters( 'luna_frontier_recommendation_limit', 6 ) ) );
$lf_feature_limit = max( 0, min( 4, $lf_limit, (int) apply_filters( 'luna_frontier_spotlight_limit', 4 ) ) );
$lf_spotlight     = array();

if ( has_nav_menu( 'luna_spotlight' ) ) {
	$lf_menu_id = (int) ( get_nav_menu_locations()['luna_spotlight'] ?? 0 );

	foreach ( (array) wp_get_nav_menu_items( $lf_menu_id ) as $lf_item ) {
		if ( $lf_item instanceof WP_Post && ! (int) $lf_item->menu_item_parent ) {
			$lf_spotlight[] = array(
				'name' => (string) $lf_item->title,
				'url'  => (string) $lf_item->url,
			);
		}
	}
} elseif ( function_exists( 'node_get_spotlight_categories' ) ) {
	$lf_spotlight = node_get_spotlight_categories();
}

// SPOTLIGHT を優先し、残りをトピックで補完して最大6件。
$lf_recommendations = array();
$lf_seen            = array();
$lf_feature_count   = 0;

foreach ( $lf_spotlight as $lf_feature ) {
	if ( $lf_feature_count >= $lf_feature_limit || count( $lf_recommendations ) >= $lf_limit ) {
		break;
	}

	$lf_url = esc_url_raw( (string) ( $lf_feature['url'] ?? '' ) );
	$lf_key = untrailingslashit( $lf_url );
	if ( '' === $lf_url || isset( $lf_seen[ $lf_key ] ) ) {
		continue;
	}

	$lf_seen[ $lf_key ] = true;
	$lf_recommendations[] = array(
		'label'   => (string) ( $lf_feature['name'] ?? '' ),
		'url'     => $lf_url,
		'current' => false,
		'feature' => true,
	);
	++$lf_feature_count;
}

foreach ( $lf_topics as $lf_topic ) {
	if ( count( $lf_recommendations ) >= $lf_limit ) {
		break;
	}

	$lf_url = esc_url_raw( (string) $lf_topic['url'] );
	$lf_key = untrailingslashit( $lf_url );
	if ( '' === $lf_url || isset( $lf_seen[ $lf_key ] ) ) {
		continue;
	}

	$lf_seen[ $lf_key ] = true;
	$lf_recommendations[] = array(
		'label'   => (string) $lf_topic['label'],
		'url'     => $lf_url,
		'current' => (bool) $lf_topic['current'],
		'feature' => false,
	);
}

// 常設カテゴリは推薦棚とは別に全件表示する。
$lf_categories = array();
$lf_category_seen = array();
foreach ( $lf_topics as $lf_topic ) {
	$lf_url = esc_url_raw( (string) $lf_topic['url'] );
	$lf_key = untrailingslashit( $lf_url );
	if ( '' === $lf_url || isset( $lf_category_seen[ $lf_key ] ) ) {
		continue;
	}

	$lf_category_seen[ $lf_key ] = true;
	$lf_categories[] = array_merge( $lf_topic, array( 'url' => $lf_url ) );
}

if ( empty( $lf_recommendations ) && empty( $lf_categories ) ) {
	return;
}
?>
<nav class="lf-topic-nav" aria-label="特集とカテゴリ">
	<div class="lf-topic-nav__inner">
		<?php if ( ! empty( $lf_recommendations ) ) : ?>
		<div class="lf-topic-nav__group lf-topic-nav__group--recommendations<?php echo $lf_feature_count ? ' lf-topic-nav__group--spotlight' : ''; ?>">
			<span class="lf-topic-nav__pick lf-topic-nav__pick--editors">編集部おすすめ</span>
			<ul class="lf-topic-nav__list">
				<?php foreach ( $lf_recommendations as $lf_item ) : ?>
					<li class="lf-topic-nav__item<?php echo $lf_item['feature'] ? ' lf-topic-nav__item--feature' : ''; ?><?php echo $lf_item['current'] ? ' is-current' : ''; ?>">
						<a href="<?php echo esc_url( $lf_item['url'] ); ?>"<?php echo $lf_item['current'] ? ' aria-current="page"' : ''; ?>>
							<span class="material-symbols-outlined lf-topic-nav__icon" aria-hidden="true"><?php echo esc_html( $lf_item['feature'] ? 'local_fire_department' : luna_frontier_topic_icon( $lf_item['label'] ) ); ?></span>
							<span class="lf-topic-nav__label"><?php echo esc_html( $lf_item['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $lf_categories ) ) : ?>
		<div class="lf-topic-nav__categories">
			<span class="lf-topic-nav__category-heading">カテゴリ</span>
			<ul class="lf-topic-nav__list">
				<?php foreach ( $lf_categories as $lf_item ) : ?>
					<li class="lf-topic-nav__item<?php echo $lf_item['current'] ? ' is-current' : ''; ?>">
						<a href="<?php echo esc_url( $lf_item['url'] ); ?>"<?php echo $lf_item['current'] ? ' aria-current="page"' : ''; ?>>
							<span class="lf-topic-nav__label"><?php echo esc_html( $lf_item['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
	</div>
</nav>
