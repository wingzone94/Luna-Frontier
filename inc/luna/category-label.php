<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — カテゴリラベルの on-color
 *
 * ■ 何が壊れていたか
 * 親テーマの node_get_category_label_props()（inc/utilities.php）は文字色を
 * '#ffffff' にハードコードし、色が未設定のカテゴリでは面を '#FF9900' へ
 * フォールバックする。実測（cybernode.local, 60 カテゴリ）:
 *
 *   色未設定 52 件 → #FF9900 に白文字 = 2.14:1   （記事数上位 10 件は全部これ）
 *   設定済み  8 件 → うち 5 件が白文字で AA 未達（最悪 #eeee22 の 1.24:1）
 *
 * さらに親の src/styles/_cards.css に
 *   .m3-label--category, :visited, :hover, :focus-visible { color: #ffffff !important }
 * があり、PHP が style 属性で出す --category-on-color は既に死んでいる。
 * つまり「変数を直す」だけでは文字色は動かない。
 *
 * ■ 直し方
 * 親には該当箇所にフィルタが無く pluggable でもないので PHP からは介入できない。
 * 出力バッファ置換は style 属性という壊れやすい足場を舐めることになるので採らない。
 * そこで **PHP でカテゴリ色を読んで CSS を生成し**、子スタイルシートの直後へ
 * wp_add_inline_style で載せる。出すルールは 3 種類だけ:
 *
 *   1. 変数を復活させる 1 行（親の白ベタ !important を無効化）
 *   2. 色未設定（data-color 属性が付かない）→ --lf-on-brand
 *   3. 設定済み → [data-color="..."] ごとに算出した on-color
 *
 * インライン style 属性のカスタムプロパティは通常宣言では上書きできないため、
 * ここだけは !important が要る。増やすのはこの 2 種類に限定する。
 *
 * ■ on-color の決め方
 * 「白が AA を満たすならそのまま。満たさないなら同色相の暗インクへ落とす」の
 * 1 規則だけ。既に AA を満たしている赤・青・紫系は見た目が変わらない。
 * 算出には子テーマが既に持つ luna_frontier_oklch_on_color() を使う。
 *
 * @package LunaFrontier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LUNA_FRONTIER_CATEGORY_CSS_TRANSIENT = 'luna_frontier_category_on_css_v2';

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

/**
 * 「押せる場所」の面に使う色を決める。
 *
 * 単一記事では主カテゴリの色（_node_primary_category メタ → 無ければ最初の
 * カテゴリ）。それ以外の画面と、色が未設定のカテゴリではブランドオレンジ。
 *
 * 親の node_get_category_label_props() を通すのは、term description に hex を
 * 書くレガシー経路も親が拾うため。チップと 1 ピクセルもずれないようにする。
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

	// data_color が空 = 親がデフォルト扱い = ブランドオレンジ。
	return '' === $props['data_color'] ? LUNA_FRONTIER_BRAND_FACE : $props['color'];
}

/**
 * カードのカテゴリチップを描画する（主カテゴリだけ塗り、以降は枠線）。
 *
 * 親の node_the_category_labels() は、シングルでは
 *   「先頭（＝主カテゴリ）のみ塗り、以降は .is-secondary で枠線」
 * という出し分けを既に持っているが、カード（$is_card）ではそれを無効にして
 * 全部を塗りにしている。その結果、色付きカテゴリを設定するとカード 1 枚に
 * 塗りチップが 2〜3 個並び、どれが主カテゴリか読めなくなった。
 *
 * NODE-2.0.md のガードレール「カードの二次的なタグチップは数を絞る／彩度を
 * 落とす。カテゴリ固有色を主役に」に沿って、カードでもシングルと同じ
 * 出し分けにする。親は触れないので、子のカードテンプレートから
 * こちらを呼ぶ（2026-08-25 ユーザー指示）。
 *
 * 並び順は親の node_get_post_categories_for_display() が主カテゴリを
 * 先頭へ寄せてくれるので、index 0 が主カテゴリになる。
 *
 * @param int|null $post_id 投稿 ID。
 */
function luna_frontier_the_card_category_labels( ?int $post_id = null ): void {
	if ( ! function_exists( 'node_get_post_categories_for_display' ) || ! function_exists( 'node_render_category_label' ) ) {
		// 親の関数が無い環境では、親の実装にそのまま任せる。
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

	// 親のカードと同じく 3 つまで。超過は +N バッジ。
	$limit   = 3;
	$count   = count( $categories );
	$display = array_slice( $categories, 0, $limit );

	echo '<div class="m3-article__category-group is-card">';

	foreach ( $display as $index => $category ) {
		$class = 'm3-label--category';

		if ( $index > 0 ) {
			$class .= ' is-secondary';
		}

		echo node_render_category_label( $category, array( 'class' => $class ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 親のレンダラがエスケープ済み。
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
 * 親と同じ結果を得るため、色の取得は必ず node_get_category_label_props() 経由に
 * する（term description 内の hex というレガシー経路も親側が拾うため）。
 */
function luna_frontier_build_category_css(): string {
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

		// data_color が空 = 親がデフォルト（#FF9900）と判定した = 属性が出ない。
		// その一群は :not([data-color]) 側でまとめて拾うのでここでは扱わない。
		if ( '' === $face ) {
			continue;
		}

		$by_color[ strtolower( $face ) ] = $face;
	}

	$rules = array();

	/*
	 * 1) 変数の復活。
	 * 親は .m3-label--category と :visited / :hover / :focus-visible を列挙して
	 * color:#ffffff!important を当てている（最大 (0,2,0)）。
	 * body.lf-theme を前置した :is() は (0,2,0) 同士で並ぶが、疑似クラス側も
	 * 明示して (0,3,0) にしておけば順序に頼らずに勝てる。
	 * 同型のバグが AI ラベル（_cards.css の .m3-label--ai 系、_article.css の
	 * ai-disclosure）にもあり、面が同じ #FF9900 なので一緒に直す。
	 */
	/*
	 * .is-secondary（記事ヘッダーの枠線タイプ）は除外する。
	 * こちらは面が白で、親が color-mix(--category-color 72%, black) で文字色を
	 * 導出している。ここに白を強制すると白地に白文字（1.00:1）になって消える。
	 * --category-color は下で深いオレンジに差し替えるので、親の導出結果も
	 * 自動的にそれに追従する（枠線・文字ともコントラストが上がる）。
	 */
	$labels = ':is(.m3-label--category,.m3-label--ai,.m3-label--ai-summary,.m3-article__ai-disclosure-expressive):not(.is-secondary)';
	$color  = 'color:var(--category-on-color,var(--lf-on-brand,#2b1700))!important';

	$rules[] = sprintf(
		'body.lf-theme %1$s,body.lf-theme %1$s:visited,body.lf-theme %1$s:hover,body.lf-theme %1$s:focus-visible{%2$s}',
		$labels,
		$color
	);

	/*
		 * 2) 色未設定。面はブランドオレンジ #FF9900 のまま、文字だけ
		 * --lf-on-brand 相当の暗色へ寄せて AA を満たす。
	 */
	$rules[] = sprintf(
		'body.lf-theme .m3-label--category:not([data-color]){--category-color:%1$s!important;--category-on-color:%2$s!important}',
		LUNA_FRONTIER_BRAND_FACE,
		LUNA_FRONTIER_BRAND_ON
	);

	/*
	 * AI ラベルも同じ面。
	 */
	$rules[] = sprintf(
		'body.lf-theme :is(.m3-label--ai,.m3-label--ai-summary,.m3-article__ai-disclosure-expressive){--category-color:%1$s!important;--category-on-color:%2$s!important;background-color:%1$s!important}',
		LUNA_FRONTIER_BRAND_FACE,
		LUNA_FRONTIER_BRAND_ON
	);

	/*
	 * 3) 設定済み。属性値は親が出力する文字列そのもの。大文字小文字の揺れに
	 * 備えて i フラグを付ける。
	 */
	foreach ( $by_color as $face ) {
		$on = luna_frontier_category_on_color( $face );

		$rules[] = sprintf(
			'body.lf-theme .m3-label--category[data-color="%1$s" i]{--category-on-color:%2$s!important}',
			esc_attr( $face ),
			esc_attr( $on )
		);
	}

	/*
	 * 3-b) 枠線タイプ（.is-secondary）の文字色。
	 *
	 * 親は color-mix(in srgb, var(--category-color) 72%, black) で導出しており、
	 * --category-color がブランドオレンジのときは #b86e00 = 白地に 3.99:1 で
	 * AA を割る（面を #bc5b00 にしていた間は 7.47 で足りていた）。
	 * こちらは塗りではなく白地の上の文字なので、既存の --lf-brand-text
	 * （#935400 / 白地 5.99:1。「オレンジに見えるが読める」ために用意された値）
	 * を使う。ブランドオレンジの面は変えていない。
	 */
	$rules[] = 'body.lf-theme .m3-label--category.is-secondary:not([data-color]){color:var(--lf-brand-text,#935400)!important}';

	/*
	 * 3-c) カードの副カテゴリを枠線チップにする。
	 *
	 * 親の枠線スタイルは .m3-article__category-group:not(.is-card) に限定されて
	 * いてカードには効かないので、同じレシピをカード側へも用意する
		 * （border は面色 54% + 黒、文字は 58% + 黒。明るいオレンジでも
		 * 白地で AA を満たす）。
	 *
	 * ホバーで塗りに変わるのは「押せる」ことの手がかり。@media (hover: hover) で
	 * 囲うのは、タッチ端末では :hover が「タップ後に貼り付く」挙動になるため。
	 * 幅ではなく入力方式で分けるので、タブレットの外付けマウスなども正しく拾える。
	 * 主カテゴリは平常時から塗りなので、ホバーしても主役の座は動かない。
	 */
	$outline_face = 'var(--category-color, ' . LUNA_FRONTIER_BRAND_FACE . ')';

		$rules[] = sprintf(
			'body.lf-theme .m3-article__category-group.is-card .m3-label--category.is-secondary{background-color:transparent!important;border:1.5px solid color-mix(in srgb, %1$s 54%%, black)!important;color:color-mix(in srgb, %1$s 58%%, black)!important}',
			$outline_face
		);

	// 色未設定の副チップは、ブランドオレンジを黒と混ぜた色ではなく既存の
	// --lf-brand-text（白地 5.99:1）を使う。3-b と同じ扱い。
	$rules[] = 'body.lf-theme .m3-article__category-group.is-card .m3-label--category.is-secondary:not([data-color]){border-color:var(--lf-brand-text,#935400)!important;color:var(--lf-brand-text,#935400)!important}';

	$rules[] = sprintf(
		'@media (hover: hover) and (pointer: fine){body.lf-theme .m3-article__category-group.is-card .m3-label--category.is-secondary:hover{background-color:%1$s!important;border-color:%1$s!important;color:var(--category-on-color,%2$s)!important}}',
		$outline_face,
		LUNA_FRONTIER_BRAND_ON
	);

	/*
	 * 4) 塗りボタンと FAB を「プライマリカテゴリラベルの色」へ同期させる。
	 *
	 * 親の _buttons.css は
	 *   background: color-mix(in srgb, var(--md-sys-color-primary) 74%, #ffd35c 26%)
	 * で塗っている。この --md-sys-color-primary は記事ページでは **アイキャッチ由来の
	 * seed 色**なので、実測すると「送信する」ボタンが #e7d9c4（ベージュ）になっていた。
	 * 記事ごとにボタンの色が変わり、しかもブランド色でもカテゴリ色でもない中間色に
	 * なるため、「押せる場所の色」がサイト内で一定しない。
	 *
		 * 2026-08-30 の設計ゲートでは、本文内の追従 FAB は neutral surface +
		 * article accent に戻す。ここでは通常の塗りボタンだけを同期する。
	 *
	 * 親と同じく !important + @layer reset が要る（親の宣言が
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
	 * 親テーマは @layer reset, base, components, utilities を使っており、
	 * 白ベタを出している _cards.css は components レイヤー。
	 * **important 宣言ではレイヤー順が反転し、非レイヤーが最弱になる**ため、
	 * 非レイヤーで出すと詳細度で勝っていても負ける（地色の canvas で実際に
	 * 起きた。_surface.css の冒頭コメント参照）。
	 * reset は 1 番目なので important では最強になる。レイヤー順は親の
	 * style.css が先に宣言済みなので、ここは既存レイヤーへの合流であって
	 * 新しい順序は作らない。
	 *
	 * 親の _print.css は文字を黒へ強制するので、画面表示にだけ効かせる。
	 */
	return "@layer reset{\n@media screen{\n" . implode( "\n", $rules ) . "\n}\n}";
}

/**
 * 生成した CSS を子スタイルシートの直後へ載せる。
 *
 * 優先度 21 = luna_frontier_enqueue_assets()（20）の直後。
 * manifest 欠損時は handle 自体が無いので wp_style_is で守る。
 */
function luna_frontier_print_category_css(): void {
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
