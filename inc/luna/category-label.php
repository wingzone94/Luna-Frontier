<?php

declare( strict_types=1 );
/**
 * Luna Frontier のカテゴリバッジとアクション配色。
 *
 * 主カテゴリはカテゴリ色を使い、白文字とのコントラストが足りない面は
 * 暗いトーンに寄せる。カテゴリ色が未設定のときは濃いブランドオレンジを
 * 使い、副カテゴリはテーマ共通の中立色で表示する。
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LUNA_FRONTIER_CATEGORY_CSS_TRANSIENT = 'luna_frontier_category_on_css_v6';

/**
 * 「押せる場所」の面と文字。
 *
 * カテゴリチップ・塗りボタン・FAB を同じ値で塗るための 1 箇所。
 * 設計ゲートでは WCAG AA を優先するため、ブランドオレンジ面では暗い
 * on-color を使う。
 */
const LUNA_FRONTIER_BRAND_FACE = '#FF9900';
const LUNA_FRONTIER_BRAND_ON   = '#2b1700';

/**
 * 面の色に対して AA を満たす文字色を返す。
 *
 * 白で足りるならそのまま返す（見た目を変えない）。足りないときだけ、
 * 面と同じ色相の暗いインクへ落とす。
 *
 * @param string $face 面の色 hex。
 */
function luna_frontier_category_on_color( string $face ): string {
	if ( luna_frontier_contrast_ratio( '#ffffff', $face ) >= 4.5 ) {
		return '#ffffff';
	}

	$rgb = luna_frontier_hex_to_rgb( $face );

	if ( null === $rgb ) {
		return '#ffffff';
	}

	$oklch = luna_frontier_rgb_to_oklch( $rgb );

	return luna_frontier_oklch_on_color( $face, $oklch['h'], $oklch['c'] );
}

/** Keep the specified ink color and bring the background up to AA contrast. */
function luna_frontier_hero_category_face( string $face, string $ink ): string {
	if ( luna_frontier_contrast_ratio( $ink, $face ) >= 4.5 ) {
		return $face;
	}

	$target = '#ffffff' === strtolower( $ink ) ? '#000000' : '#ffffff';
	$low    = 0.0;
	$high   = 1.0;
	for ( $step = 0; $step < 20; $step++ ) {
		$ratio = ( $low + $high ) / 2;
		$tone  = node_mix_hex_color( $face, $target, $ratio );
		if ( luna_frontier_contrast_ratio( $ink, $tone ) >= 4.5 ) {
			$high = $ratio;
		} else {
			$low = $ratio;
		}
	}
	return node_mix_hex_color( $face, $target, $high );
}

/**
 * 「押せる場所」の面に使う色を決める。
 *
 * 単一記事では主カテゴリの色（_node_primary_category メタ → 無ければ最初の
 * カテゴリ）。それ以外の画面と、色が未設定のカテゴリではブランドオレンジ。
 *
 * 既存の node_get_category_label_props() を通すのは、term description に hex を
 * 書くレガシー経路も既存関数が拾うため。チップと 1 ピクセルもずれないようにする。
 */
function luna_frontier_primary_category_face(): string {
	if ( ! is_singular( array( 'post' ) ) || ! function_exists( 'node_get_category_label_props' ) ) {
		return LUNA_FRONTIER_BRAND_FACE;
	}

	$post_id = get_the_ID();

	if ( ! $post_id ) {
		return LUNA_FRONTIER_BRAND_FACE;
	}

	$term_id = absint( get_post_meta( $post_id, '_node_primary_category', true ) );
	$term    = $term_id ? get_term( $term_id, 'category' ) : null;

	if ( ! $term instanceof WP_Term ) {
		$terms = get_the_category( $post_id );
		$term  = $terms && $terms[0] instanceof WP_Term ? $terms[0] : null;
	}

	if ( ! $term instanceof WP_Term ) {
		return LUNA_FRONTIER_BRAND_FACE;
	}

	$props = node_get_category_label_props( $term );

	// data_color が空 = 既存関数がデフォルト扱い = ブランドオレンジ。
	return '' === $props['data_color'] ? LUNA_FRONTIER_BRAND_FACE : $props['color'];
}

/**
 * カードのカテゴリチップを描画する（主カテゴリは塗り、以降は淡色）。
 *
 * 既存の node_the_category_labels() は、シングルでは
 *   「先頭（＝主カテゴリ）のみ塗り、以降は .is-secondary の淡色」
 * という出し分けを既に持っているが、カード（$is_card）ではそれを無効にして
 * 全部を塗りにしている。その結果、色付きカテゴリを設定するとカード 1 枚に
 * 塗りチップが 2〜3 個並び、どれが主カテゴリか読めなくなった。
 *
 * カードの二次的なタグチップは数を絞り彩度を落とす、という既存のガードレールに
 * 沿って、カードでもシングルと同じ出し分けにする。カード描画は
 * Luna のカードテンプレートからこちらを呼ぶ（2026-08-25 ユーザー指示）。
 *
 * 並び順は既存の node_get_post_categories_for_display() が主カテゴリを
 * 先頭へ寄せてくれるので、index 0 が主カテゴリになる。
 *
 * @param int|null $post_id 投稿 ID。
 */
function luna_frontier_the_card_category_labels( ?int $post_id = null ): void {
	if ( ! function_exists( 'node_get_post_categories_for_display' ) || ! function_exists( 'node_render_category_label' ) ) {
		// 表示用の Node 由来関数が欠けている場合は、既存のラベル出力へ委譲する。
		if ( function_exists( 'node_the_category_labels' ) ) {
			node_the_category_labels( $post_id );
		}
		return;
	}

	$post_id    = $post_id ? (int) $post_id : (int) get_the_ID();
	$categories = node_get_post_categories_for_display( $post_id );

	if ( empty( $categories ) ) {
		return;
	}

	// 既存のカードと同じく 3 つまで。超過は +N バッジ。
	$limit   = 3;
	$count   = count( $categories );
	$display = array_slice( $categories, 0, $limit );

	echo '<div class="m3-article__category-group is-card">';

	foreach ( $display as $index => $category ) {
		$class = 'm3-label--category';

		if ( $index > 0 ) {
			$class .= ' is-secondary';
		}

		echo node_render_category_label( $category, array( 'class' => $class ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 既存レンダラがエスケープ済み。
	}

	if ( $count > $limit ) {
		$remaining = $count - $limit;
		printf(
			'<span class="m3-label--category-more" title="さらに %1$d 件のカテゴリがあります">+%1$d</span>',
			(int) $remaining
		);
	}

	echo '</div>';
}

/**
 * カテゴリラベル用の CSS を組み立てる。
 *
 * node_get_category_label_props() は term description 内の旧形式の色も読む。
 *
 * @param bool $badges_only 既存アーカイブでは共通のバッジ色だけを適用する。
 */
function luna_frontier_build_category_css( bool $badges_only = false ): string {
	if ( ! function_exists( 'node_get_category_label_props' ) ) {
		return '';
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $terms ) || ! $terms ) {
		return '';
	}

	// 同じ hex を持つカテゴリが複数あってもルールは 1 本にまとめる。
	$by_color = array();

	foreach ( $terms as $term ) {
		$props = node_get_category_label_props( $term );
		$face  = $props['data_color'];

		// data_color が空 = 既存関数がデフォルト（#FF9900）と判定した = 属性が出ない。
		// その一群は :not([data-color]) 側でまとめて拾うのでここでは扱わない。
		if ( '' === $face ) {
			continue;
		}

		$by_color[ strtolower( $face ) ] = $face;
	}

	$rules = array();

	$primary = ':is(.m3-label--category:not(.is-secondary),.lf-hero-slide__category,.lf-home-story__category)';
	$rules[] = 'body.lf-theme ' . $primary . '{--category-on-color:#fff!important;color:#fff!important}';
	$rules[] = 'body.lf-theme ' . $primary . ':not([data-color]){--category-color:#b84b00!important}';

	foreach ( $by_color as $face ) {
		$rules[] = sprintf(
			'body.lf-theme %1$s[data-color="%2$s" i]{--category-color:%3$s!important}',
			$primary,
			esc_attr( $face ),
			esc_attr( luna_frontier_hero_category_face( $face, '#ffffff' ) )
		);
	}

	$secondary = 'body.lf-theme .m3-article__category-group .m3-label--category.is-secondary';
	$rules[] = $secondary . '{background-color:var(--md-sys-color-surface-container-high)!important;border:none!important;box-shadow:none!important;color:var(--md-sys-color-on-surface)!important}';
	$rules[] = $secondary . ':focus-visible{outline:2px solid var(--md-sys-color-accent-text)!important;outline-offset:2px}';
	$rules[] = '@media (hover:hover) and (pointer:fine){' . $secondary . ':hover{background-color:var(--md-sys-color-surface-container-highest)!important}}';

	if ( $badges_only ) {
		return "@layer reset{\n@media screen{\n" . implode( "\n", $rules ) . "\n} }";
	}

	$rules[] = sprintf(
		'body.lf-theme :is(.m3-label--ai,.m3-label--ai-summary,.m3-article__ai-disclosure-expressive){--category-color:%1$s!important;--category-on-color:%2$s!important;background-color:%1$s!important;color:%2$s!important}',
		LUNA_FRONTIER_BRAND_FACE,
		LUNA_FRONTIER_BRAND_ON
	);

	/*
	 * 4) 塗りボタンと FAB を「プライマリカテゴリラベルの色」へ同期させる。
	 *
	 * 既存の _buttons.css は
	 *   background: color-mix(in srgb, var(--md-sys-color-primary) 74%, #ffd35c 26%)
	 * で塗っている。この --md-sys-color-primary は記事ページでは **アイキャッチ由来の
	 * seed 色**なので、実測すると「送信する」ボタンが #e7d9c4（ベージュ）になっていた。
	 * 記事ごとにボタンの色が変わり、しかもブランド色でもカテゴリ色でもない中間色に
	 * なるため、「押せる場所の色」がサイト内で一定しない。
	 *
		 * 2026-08-30 の設計ゲートでは、本文内の追従 FAB は neutral surface +
		 * article accent に戻す。ここでは通常の塗りボタンだけを同期する。
	 *
	 * 既存 CSS と同じく !important + @layer reset が要る（その宣言が
	 * @layer components の !important なので、非レイヤーでは詳細度に関係なく負ける）。
	 */
		$face = luna_frontier_primary_category_face();
		$on   = luna_frontier_category_on_color( $face );

		$rules[] = sprintf(
			'body.lf-theme :is(.m3-button--filled,.m3-button.m3-button--filled,.m3-comment-form .m3-button,.wp-block-button__link,.m3-archive-pill-button){background:%1$s!important;background-color:%1$s!important;color:%2$s!important}',
			esc_attr( $face ),
			esc_attr( $on )
		);

	/*
	 * 4) 第 2 の出口: カテゴリ／タームアーカイブのヘッダー。
	 * inc/archive-helpers.php が .m3-archive-header の style 属性へ同じ 2 変数を
	 * 出し、__icon と __count がそれを読む。こちらは白ベタの !important が
	 * 無いので、変数を直すだけで両方が直る。1 ページに 1 ターム分なので
	 * 属性セレクタは要らない。
	 */
	if ( is_category() || is_tax() ) {
		$queried = get_queried_object();

		if ( $queried instanceof WP_Term ) {
			$props = node_get_category_label_props( $queried );
			$on    = luna_frontier_category_on_color( $props['color'] );

			// 色未設定ならブランド面（チップと同じ扱い）。
			$face = '' === $props['data_color'] ? LUNA_FRONTIER_BRAND_FACE : $props['color'];
			$on   = '' === $props['data_color'] ? LUNA_FRONTIER_BRAND_ON : $on;

			$rules[] = sprintf(
				'body.lf-theme .m3-archive-header{--category-color:%1$s!important;--category-on-color:%2$s!important}',
				esc_attr( $face ),
				esc_attr( $on )
			);
		}
	}

	/*
	 * @layer reset に入れる必要がある。
	 *
	 * ベース CSS は @layer reset, base, components, utilities を使っており、
	 * 白ベタを出している _cards.css は components レイヤー。
	 * **important 宣言ではレイヤー順が反転し、非レイヤーが最弱になる**ため、
	 * 非レイヤーで出すと詳細度で勝っていても負ける（地色の canvas で実際に
	 * 起きた。_surface.css の冒頭コメント参照）。
	 * reset は 1 番目なので important では最強になる。レイヤー順はベースの
	 * style.css が先に宣言済みなので、ここは既存レイヤーへの合流であって
	 * 新しい順序は作らない。
	 *
	 * 既存の _print.css は文字を黒へ強制するので、画面表示にだけ効かせる。
	 */
	return "@layer reset{\n@media screen{\n" . implode( "\n", $rules ) . "\n}\n}";
}

/**
 * 生成した CSS を Luna のスタイルシートの直後へ載せる。
 *
 * 優先度 21 = luna_frontier_enqueue_assets()（20）の直後。
 * manifest 欠損時は handle 自体が無いので wp_style_is で守る。
 */
function luna_frontier_print_category_css(): void {
	if ( wp_style_is( 'luna-frontier-archive', 'enqueued' ) ) {
		wp_add_inline_style( 'luna-frontier-archive', luna_frontier_build_category_css( true ) );
		return;
	}

	if ( ! wp_style_is( 'luna-frontier', 'enqueued' ) ) {
		return;
	}

	/*
	 * 文脈に依存する行が 2 つある（アーカイブヘッダーと、記事ごとのボタン面）。
	 * どちらも 1 ページ 1 値なので、それらが出る画面ではキャッシュしない。
	 */
	$is_contextual = is_category() || is_tax() || is_singular( array( 'post' ) );
	$css           = $is_contextual ? '' : get_transient( LUNA_FRONTIER_CATEGORY_CSS_TRANSIENT );

	if ( ! is_string( $css ) || '' === $css ) {
		$css = luna_frontier_build_category_css();

		if ( ! $is_contextual ) {
			set_transient( LUNA_FRONTIER_CATEGORY_CSS_TRANSIENT, $css, DAY_IN_SECONDS );
		}
	}

	if ( '' !== $css ) {
		wp_add_inline_style( 'luna-frontier', $css );
	}
}
add_action( 'wp_enqueue_scripts', 'luna_frontier_print_category_css', 21 );

/**
 * カテゴリの色を変えたらキャッシュを捨てる。
 */
function luna_frontier_flush_category_css(): void {
	delete_transient( LUNA_FRONTIER_CATEGORY_CSS_TRANSIENT );
}
add_action( 'created_term', 'luna_frontier_flush_category_css' );
add_action( 'edited_term', 'luna_frontier_flush_category_css' );
add_action( 'delete_term', 'luna_frontier_flush_category_css' );
add_action( 'updated_term_meta', 'luna_frontier_flush_category_css' );
add_action( 'added_term_meta', 'luna_frontier_flush_category_css' );
add_action( 'switch_theme', 'luna_frontier_flush_category_css' );
