<?php
/**
 * Material Symbols のサブセット読み込み。
 *
 * Google Fonts の Material Symbols は、アイコンを指定せずに要求すると
 * 全アイコン（約3,700種）を可変フォント1本で返す。本番（luminous-core.net）の
 * モバイル実測で **3,874KB** あり、ページ転送量 7.4MB の過半を占めていた。
 * `display=block` なのでこのフォントが届くまでアイコンは空白のままで、
 * 初期表示にも直撃する。
 *
 * 実際に使っているのは下の一覧だけなので `icon_names=` で絞る。
 * 実測 3,874KB → 83.9KB（-97.8%）。
 *
 * 可変軸（FILL / wght / GRAD / opsz）は落とせない。`_material-symbols.css` ほか
 * 多数の CSS が `font-variation-settings` を指定し、遷移まで掛けているため。
 * 軸を固定すれば 9.2KB まで下がるが、見た目が変わる。
 *
 * **一覧に無いアイコンはリガチャの文字がそのまま出る**（例: "settings" と表示される）。
 * アイコンを増やしたら必ずここへ追加すること。取りこぼしは
 * `bun run verify:icons`（静的抽出との突き合わせ）と
 * `bun run verify:icon-subset`（実ページの描画結果との突き合わせ）が検出する。
 *
 * @package Node
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'node_get_icon_font_names' ) ) {
	/**
	 * テーマと同梱プラグインが使う Material Symbols の一覧。
	 *
	 * @return string[] アイコン名（昇順）。
	 */
	function node_get_icon_font_names(): array {
		$names = array(
			'analytics',
			'apps',
			'arrow_back',
			'arrow_downward',
			'arrow_forward',
			'arrow_upward',
			'article',
			'auto_awesome',
			'auto_stories',
			'bolt',
			'broken_image',
			'calendar_month',
			'calendar_today',
			'campaign',
			'category',
			'chat_bubble',
			'check',
			'check_circle',
			'chevron_left',
			'chevron_right',
			'clear_all',
			'close',
			'compress',
			'computer',
			'content_copy',
			'dark_mode',
			'delete_forever',
			'desktop_windows',
			'devices',
			'edit',
			'error',
			'expand_less',
			'expand_more',
			'favorite',
			'filter_alt',
			'first_page',
			'folder',
			'format_quote',
			'format_size',
			'grid_view',
			'health_and_safety',
			'home',
			'image',
			'inbox',
			'info',
			'inventory_2',
			'key',
			'laptop_mac',
			'last_page',
			'light_mode',
			'link',
			'list',
			'local_fire_department',
			'media_output',
			'menu_book',
			'more_horiz',
			'newspaper',
			'open_in_new',
			'pause_circle',
			'perm_media',
			'person',
			'print',
			'psychology',
			'qr_code_2',
			'reorder',
			'report_problem',
			'rss_feed',
			'save',
			'schedule',
			'search',
			'search_off',
			'sell',
			'send',
			'sentiment_dissatisfied',
			'share',
			'shopping_bag',
			'shopping_cart',
			'smartphone',
			'sort',
			'sort_by_alpha',
			'sports_esports',
			'stop_circle',
			'straighten',
			'sync',
			'thumb_down',
			'thumb_up',
			'timer',
			'toc',
			'travel_explore',
			'tune',
			'undo',
			'unfold_less',
			'unfold_more',
			'update',
			'verified',
			'videogame_asset',
			'view_list',
			'web_asset',
			'whatshot',
		);

		/**
		 * 子テーマやプラグインが独自のアイコンを足すためのフック。
		 *
		 * 足し忘れるとリガチャの文字が出るので、追加する側で必ず通すこと。
		 *
		 * @param string[] $names アイコン名の一覧。
		 */
		$names = (array) apply_filters( 'node_icon_font_names', $names );

		$names = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $names ),
					static function ( string $name ): bool {
						// Google Fonts の icon_names はスネークケースの英数字のみ。
						return 1 === preg_match( '/\A[a-z0-9_]{2,40}\z/', $name );
					}
				)
			)
		);

		sort( $names );

		return $names;
	}
}

if ( ! function_exists( 'node_get_icon_font_url' ) ) {
	/**
	 * Material Symbols の CSS URL を組み立てる。
	 *
	 * 一覧が空になった場合は絞り込みを諦めて全アイコンを返す。
	 * 巨大にはなるがアイコンが文字化けするよりはましなため。
	 *
	 * @return string
	 */
	function node_get_icon_font_url(): string {
		/*
		 * クエリは手で組み立てる。
		 *
		 * add_query_arg() や rawurlencode() を通すと `:` `,` `@` まで
		 * パーセントエンコードされ、Google Fonts が family / icon_names を
		 * 解釈できずに「全アイコンのフォールバック」を返す（実測 3,874KB のまま）。
		 * 空白だけ `+` にして、他はそのまま渡すのが正しい形。
		 */
		$parts = array(
			// 可変軸は CSS 側が使っているので維持する。
			'family=' . str_replace( ' ', '+', 'Material Symbols Outlined' )
				. ':opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200',
		);

		$names = node_get_icon_font_names();
		if ( array() !== $names ) {
			$parts[] = 'icon_names=' . implode( ',', $names );
		}

		// グリフ到着前にリガチャ文字を出さない。
		$parts[] = 'display=block';

		return 'https://fonts.googleapis.com/css2?' . implode( '&', $parts );
	}
}
