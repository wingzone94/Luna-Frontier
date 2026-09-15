<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — 検索結果（Phase 9）
 *
 * 指示書 §43。
 * - 2 段階 UI。Basic（キーワード / 大分類 / 検索）は常時表示、
 *   Advanced（文字数・期間・メディア・タグ・並び）は任意展開。
 * - 展開は <details> なので JS 無しで開閉でき、Esc / Tab / aria-expanded の
 *   面倒を自前実装せずにブラウザ標準へ委ねられる。
 * - フォームは素の GET。JS が動かなくても検索できる。
 * - 親テーマ header.php の詳細検索ダイアログ（Expressive の見せ場）は
 *   そのまま残す。ここはその「JS 無しでも届く」正面入口。
 *
 * GET パラメータ名は既存実装（Luminous Core Engine → Luna Engine）に合わせる。
 * 名称変更を理由に s / m3_* を rename しない（§58）。
 *
 * @package LunaFrontier
 */

get_header();

$lf_q          = get_search_query();
$lf_cat        = isset( $_GET['m3_cat'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['m3_cat'] ) ) : '';
$lf_min        = isset( $_GET['m3_min'] ) ? (int) $_GET['m3_min'] : 0;
$lf_max        = isset( $_GET['m3_max'] ) ? (int) $_GET['m3_max'] : 0;
$lf_start      = isset( $_GET['m3_start_date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['m3_start_date'] ) ) : '';
$lf_end        = isset( $_GET['m3_end_date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['m3_end_date'] ) ) : '';
$lf_tag        = isset( $_GET['m3_tag'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['m3_tag'] ) ) : '';
$lf_sort       = isset( $_GET['m3_sort'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['m3_sort'] ) ) : 'newest';
$lf_media      = isset( $_GET['m3_media_type'] ) ? (array) wp_unslash( $_GET['m3_media_type'] ) : array();
$lf_media      = array_map( 'sanitize_text_field', $lf_media );

// Advanced 側に何か入っていれば最初から開いておく（現在の条件を隠さない）。
$lf_advanced_open = ( $lf_min || $lf_max || '' !== $lf_start || '' !== $lf_end || '' !== $lf_tag || ! empty( $lf_media ) || ( '' !== $lf_sort && 'newest' !== $lf_sort ) );

global $wp_query;
?>

<main id="primary" class="site-main m3-archive-layout m3-search-layout lf-search">

	<header class="lf-search__header">
		<h1 class="lf-search__title">
			<?php
			if ( $lf_q ) {
				printf( esc_html__( '「%s」の検索結果', 'node' ), esc_html( $lf_q ) );
			} else {
				esc_html_e( '検索', 'node' );
			}
			?>
		</h1>
		<p class="lf-search__count">
			<?php printf( esc_html__( '%d 件', 'node' ), (int) $wp_query->found_posts ); ?>
		</p>
	</header>

	<form class="lf-search-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">

		<?php // ---- Basic: キーワード / 大分類 / 検索 ---- ?>
		<div class="lf-search-form__basic">
			<div class="lf-field lf-field--grow">
				<label class="lf-field__label" for="lf-search-keyword"><?php esc_html_e( 'キーワード', 'node' ); ?></label>
				<input class="lf-field__control" type="search" id="lf-search-keyword" name="s" value="<?php echo esc_attr( $lf_q ); ?>" placeholder="<?php esc_attr_e( '記事を検索', 'node' ); ?>">
			</div>

			<div class="lf-field">
				<label class="lf-field__label" for="lf-search-cat"><?php esc_html_e( 'カテゴリ', 'node' ); ?></label>
				<select class="lf-field__control" id="lf-search-cat" name="m3_cat">
					<option value=""><?php esc_html_e( 'すべて', 'node' ); ?></option>
					<?php
					foreach ( get_categories( array( 'parent' => 0, 'hide_empty' => true ) ) as $lf_term ) {
						printf(
							'<option value="%1$s"%2$s>%3$s</option>',
							esc_attr( (string) $lf_term->term_id ),
							selected( $lf_cat, (string) $lf_term->term_id, false ),
							esc_html( $lf_term->name )
						);
					}
					?>
				</select>
			</div>

			<button class="lf-search-form__submit" type="submit">
				<span class="material-symbols-outlined" aria-hidden="true">search</span>
				<?php esc_html_e( '検索', 'node' ); ?>
			</button>
		</div>

		<?php // ---- Advanced: 任意展開。JS 無しで開閉できる ---- ?>
		<details class="lf-search-form__advanced"<?php echo $lf_advanced_open ? ' open' : ''; ?>>
			<summary class="lf-search-form__advanced-head">
				<span class="material-symbols-outlined" aria-hidden="true">tune</span>
				<span class="lf-system-label"><?php esc_html_e( '詳細条件', 'node' ); ?></span>
			</summary>

			<div class="lf-search-form__advanced-body">
				<fieldset class="lf-fieldset">
					<legend class="lf-fieldset__legend"><?php esc_html_e( '文字数', 'node' ); ?></legend>
					<div class="lf-field-row">
						<div class="lf-field">
							<label class="lf-field__label" for="lf-min"><?php esc_html_e( '下限', 'node' ); ?></label>
							<input class="lf-field__control" type="number" id="lf-min" name="m3_min" min="0" step="100" value="<?php echo $lf_min ? esc_attr( (string) $lf_min ) : ''; ?>">
						</div>
						<div class="lf-field">
							<label class="lf-field__label" for="lf-max"><?php esc_html_e( '上限', 'node' ); ?></label>
							<input class="lf-field__control" type="number" id="lf-max" name="m3_max" min="0" step="100" value="<?php echo $lf_max ? esc_attr( (string) $lf_max ) : ''; ?>">
						</div>
					</div>
				</fieldset>

				<fieldset class="lf-fieldset">
					<legend class="lf-fieldset__legend"><?php esc_html_e( '公開期間', 'node' ); ?></legend>
					<div class="lf-field-row">
						<div class="lf-field">
							<label class="lf-field__label" for="lf-start"><?php esc_html_e( '開始', 'node' ); ?></label>
							<input class="lf-field__control" type="date" id="lf-start" name="m3_start_date" value="<?php echo esc_attr( $lf_start ); ?>">
						</div>
						<div class="lf-field">
							<label class="lf-field__label" for="lf-end"><?php esc_html_e( '終了', 'node' ); ?></label>
							<input class="lf-field__control" type="date" id="lf-end" name="m3_end_date" value="<?php echo esc_attr( $lf_end ); ?>">
						</div>
					</div>
				</fieldset>

				<fieldset class="lf-fieldset">
					<legend class="lf-fieldset__legend"><?php esc_html_e( 'メディア', 'node' ); ?></legend>
					<div class="lf-choice-row">
						<?php
						$lf_media_options = array(
							'image' => __( '画像あり', 'node' ),
							'video' => __( '動画あり', 'node' ),
						);
						foreach ( $lf_media_options as $lf_value => $lf_label ) :
							?>
							<label class="lf-choice">
								<input type="checkbox" name="m3_media_type[]" value="<?php echo esc_attr( $lf_value ); ?>" <?php checked( in_array( $lf_value, $lf_media, true ) ); ?>>
								<span><?php echo esc_html( $lf_label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<div class="lf-field-row">
					<div class="lf-field lf-field--grow">
						<label class="lf-field__label" for="lf-tag"><?php esc_html_e( 'タグ', 'node' ); ?></label>
						<input class="lf-field__control" type="text" id="lf-tag" name="m3_tag" value="<?php echo esc_attr( $lf_tag ); ?>">
					</div>
					<div class="lf-field">
						<label class="lf-field__label" for="lf-sort"><?php esc_html_e( '並び順', 'node' ); ?></label>
						<select class="lf-field__control" id="lf-sort" name="m3_sort">
							<?php
							foreach ( array(
								'newest' => __( '新しい順', 'node' ),
								'oldest' => __( '古い順', 'node' ),
							) as $lf_value => $lf_label ) {
								printf(
									'<option value="%1$s"%2$s>%3$s</option>',
									esc_attr( $lf_value ),
									selected( $lf_sort, $lf_value, false ),
									esc_html( $lf_label )
								);
							}
							?>
						</select>
					</div>
				</div>
			</div>
		</details>
	</form>

	<div class="l-card-grid m3-post-grid m3-search-results-grid">
		<?php if ( have_posts() ) : ?>
			<div class="l-card-grid__items m3-post-grid__container">
				<?php
				while ( have_posts() ) :
					the_post();
					get_template_part( 'template-parts/article-card', null, array( 'card_class' => 'card-standard' ) );
				endwhile;
				?>
			</div>
		<?php else : ?>
			<?php
			/*
			 * 0 件は「行き止まりに来た人」を扱う画面。
			 * 404 は中央揃えで検索欄と行動ボタン 3 つを持っているのに、こちらは
			 * 見出しと 1 文だけでリンクもボタンも 0 個、下に 496px の空白が続いて
			 * いた（実測）。同じ状態を 2 つの基準で作らないよう、404 と同じ部品で
			 * 次の行き先を用意する。
			 *
			 * 候補カテゴリは「実際に記事が付いているもの」を投稿数順で出す。
			 * 空のカテゴリを勧めると二重の行き止まりになるため hide_empty を使う。
			 */
			$lf_suggested = get_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 8,
				)
			);

			/*
			 * 同名のカテゴリが複数ある（「Nintendo Switch」が id 25 と 28 に
			 * 分かれている等）ため、名前で重複を落としてから 4 件に絞る。
			 * 同じ名前のチップが並ぶと、行き先が違うことが読み手に伝わらない。
			 */
			if ( ! is_wp_error( $lf_suggested ) && $lf_suggested ) {
				$lf_seen  = array();
				$lf_uniq  = array();

				foreach ( $lf_suggested as $lf_term ) {
					$lf_key = mb_strtolower( $lf_term->name );

					if ( isset( $lf_seen[ $lf_key ] ) ) {
						continue;
					}

					$lf_seen[ $lf_key ] = true;
					$lf_uniq[]          = $lf_term;

					if ( count( $lf_uniq ) >= 4 ) {
						break;
					}
				}

				$lf_suggested = $lf_uniq;
			}
			?>
			<div class="lf-no-results">
				<h2 class="lf-no-results__title"><?php esc_html_e( '見つかりませんでした', 'node' ); ?></h2>
				<p class="lf-no-results__text"><?php esc_html_e( '条件に一致する記事がありません。キーワードを短くするか、詳細条件を外して試してください。', 'node' ); ?></p>

				<?php if ( ! is_wp_error( $lf_suggested ) && $lf_suggested ) : ?>
					<div class="lf-no-results__suggest">
						<span class="lf-no-results__suggest-label"><?php esc_html_e( 'よく読まれているカテゴリ', 'node' ); ?></span>
						<div class="lf-no-results__chips">
							<?php foreach ( $lf_suggested as $lf_term ) : ?>
								<a class="lf-no-results__chip" href="<?php echo esc_url( get_category_link( $lf_term->term_id ) ); ?>">
									<?php echo esc_html( $lf_term->name ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( function_exists( 'node_get_all_articles_url' ) ) : ?>
					<a class="m3-button m3-button--filled lf-no-results__action" href="<?php echo esc_url( node_get_all_articles_url() ); ?>">
						<span class="material-symbols-outlined" aria-hidden="true">article</span>
						<?php esc_html_e( '記事一覧を見る', 'node' ); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="m3-navigation">
		<?php
		the_posts_pagination(
			array(
				'mid_size'  => 2,
				'prev_text' => '<span class="material-symbols-outlined">chevron_left</span>',
				'next_text' => '<span class="material-symbols-outlined">chevron_right</span>',
			)
		);
		?>
	</div>

</main>

<?php get_footer(); ?>
