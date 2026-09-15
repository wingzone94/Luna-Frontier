<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — Article Header（Phase 4）
 *
 * 親テーマ Node 1.3 の template-parts/single/hero.php を子テーマ側で置き換える。
 *
 * 指示書 §30 / §31:
 * - 情報を同じ視覚強度で並べない。優先順位は
 *     Category → Title → Published / Updated → 最小限のメタ → Intelligence Summary。
 * - Intelligence Summary は独立ブロックにせず、この Header の Paper に統合する。
 * - Summary が無いときは空の Surface / 罫 / 不自然な gap を作らない。
 * - Multipage では 1 ページ目だけに出す（親 single.php と同じ条件）。
 *
 * 既存メタ（_node_ai_summary / _node_ai_tone_color / _node_ai_keywords /
 * _node_linked_library_id 等）はそのまま読む。rename しない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lf_post_id   = get_the_ID();
$lf_has_thumb = has_post_thumbnail( $lf_post_id );

// 親 single.php と同じ Multipage 判定。
$lf_is_primary_page = ( 1 === max( 1, (int) get_query_var( 'page' ) ) );

$lf_summary = (string) get_post_meta( $lf_post_id, '_node_ai_summary', true );
$lf_show_summary = ( '' !== trim( $lf_summary ) ) && $lf_is_primary_page;

// Header 内で描画したことを、子の template-parts/ai-summary.php へ伝える（二重表示防止）。
if ( $lf_show_summary ) {
	$GLOBALS['luna_frontier_summary_rendered'] = true;
}

$lf_reading = function_exists( 'node_get_article_ranking_info' )
	? node_get_article_ranking_info( $lf_post_id )
	: array();

$lf_published = get_the_date( 'c', $lf_post_id );
$lf_modified  = get_the_modified_date( 'c', $lf_post_id );
$lf_has_update = ( get_the_date( 'Ymd', $lf_post_id ) !== get_the_modified_date( 'Ymd', $lf_post_id ) );

// Node Library への導線（既存メタをそのまま利用）。
$lf_library_id = absint( get_post_meta( $lf_post_id, '_node_linked_library_id', true ) );
if ( ! $lf_library_id ) {
	$lf_refs       = get_post_meta( $lf_post_id, '_node_library_card_reference', false );
	$lf_library_id = absint( $lf_refs[0] ?? 0 );
}
$lf_library = $lf_library_id ? get_post( $lf_library_id ) : null;
if ( ! $lf_library instanceof WP_Post || 'node_library' !== $lf_library->post_type || 'publish' !== $lf_library->post_status ) {
	$lf_library = null;
}
?>
<header class="lf-article-header<?php echo $lf_has_thumb ? ' lf-article-header--has-media' : ''; ?>">

	<?php if ( $lf_has_thumb ) : ?>
		<div class="lf-article-header__media">
			<?php
			the_post_thumbnail(
				'large',
				array(
					'class'         => 'lf-article-header__image',
					'decoding'      => 'async',
					'fetchpriority' => 'high',
				)
			);
			?>
		</div>
	<?php endif; ?>

	<div class="lf-article-header__inner">

		<?php // 1. Category（Wayfinding。カテゴリ固有色は Dynamic Color で上書きしない。§39） ?>
		<div class="lf-article-header__eyebrow">
			<?php node_the_category_labels(); ?>
		</div>

		<?php // 2. Title ?>
		<h1 class="lf-article-header__title" id="m3-hero-title"><?php the_title(); ?></h1>

		<?php // 3. Published / Updated ＋ 4. 最小限のメタ ?>
		<div class="lf-article-header__meta">
			<time class="lf-meta__item" datetime="<?php echo esc_attr( $lf_published ); ?>">
				<span class="material-symbols-outlined lf-meta__icon" aria-hidden="true">calendar_today</span>
				<span class="screen-reader-text">公開日</span>
				<?php echo esc_html( get_the_date( '', $lf_post_id ) ); ?>
			</time>

			<?php if ( $lf_has_update ) : ?>
				<time class="lf-meta__item" datetime="<?php echo esc_attr( $lf_modified ); ?>">
					<span class="material-symbols-outlined lf-meta__icon" aria-hidden="true">update</span>
					<span class="screen-reader-text">更新日</span>
					<?php echo esc_html( get_the_modified_date( '', $lf_post_id ) ); ?>
				</time>
			<?php endif; ?>

			<?php
			$lf_author_id  = (int) get_the_author_meta( 'ID' );
			$lf_author     = trim( (string) get_the_author() );
			$lf_author_url = $lf_author_id ? get_author_posts_url( $lf_author_id ) : '';
			?>
			<?php if ( '' !== $lf_author && '' !== $lf_author_url ) : ?>
				<a class="lf-meta__item lf-meta__item--author lf-meta__item--link" href="<?php echo esc_url( $lf_author_url ); ?>" rel="author" aria-label="<?php echo esc_attr( sprintf( '%sの記事一覧へ', $lf_author ) ); ?>">
					<span class="material-symbols-outlined lf-meta__icon" aria-hidden="true">person</span>
					<span class="screen-reader-text">執筆者</span>
					<?php echo esc_html( $lf_author ); ?>
				</a>
			<?php elseif ( '' !== $lf_author ) : ?>
				<span class="lf-meta__item lf-meta__item--author">
					<span class="material-symbols-outlined lf-meta__icon" aria-hidden="true">person</span>
					<span class="screen-reader-text">執筆者</span>
					<?php echo esc_html( $lf_author ); ?>
				</span>
			<?php endif; ?>

			<?php if ( $lf_library ) : ?>
				<a class="lf-meta__chip lf-meta__chip--link" href="<?php echo esc_url( get_permalink( $lf_library ) ); ?>">
					<span class="lf-system-label">Library</span>
					<?php echo esc_html( get_the_title( $lf_library ) ); ?>
				</a>
			<?php endif; ?>
		</div>

		<?php
		/*
		 * 読了時間・文字数バッジは Node 1.3 の実装をそのまま使う。
		 * 円ゲージ・判定カラー・判定チップは 1.2.5 で作り込まれた資産なので、
		 * Luna Frontier 側で簡略化しない（配置だけ Article Header 内へ移す）。
		 */
		?>
		<?php if ( ! empty( $lf_reading['chars'] ) && (int) $lf_reading['chars'] > 200 ) : ?>
			<?php
			$lf_total_seconds = isset( $lf_reading['reading_seconds'] )
				? max( 30, (int) $lf_reading['reading_seconds'] )
				: max( 30, (int) round( ( $lf_reading['chars'] / 550 ) * 60 ) );
			$lf_minutes       = (int) ceil( $lf_total_seconds / 60 );
			$lf_time_display  = $lf_total_seconds < 60
				? '1分未満で読めます'
				: sprintf( '約%d分で読めます', $lf_minutes );
			$lf_progress      = isset( $lf_reading['progress'] )
				? min( 100, max( 0, (float) $lf_reading['progress'] ) )
				: 0;
			$lf_angle         = round( $lf_progress * 3.6, 2 );
			?>
			<div class="m3-article__meta-reading lf-article-header__reading">
				<div class="m3-article__reading-badge-expressive m3-ripple-host"
					id="m3-hero-reading-badge"
					style="--reading-color: <?php echo esc_attr( (string) $lf_reading['color'] ); ?>; --reading-bg: <?php echo esc_attr( (string) $lf_reading['container_color'] ); ?>; --reading-rank-color: <?php echo esc_attr( (string) $lf_reading['badge_color'] ); ?>; --reading-rank-bg: <?php echo esc_attr( (string) $lf_reading['badge_bg'] ); ?>;">
					<div class="m3-reading-badge__gauge">
						<svg viewBox="0 0 36 36"
							class="m3-reading-circle__svg"
							style="--target-progress: <?php echo esc_attr( (string) $lf_progress ); ?>; --target-angle: <?php echo esc_attr( (string) $lf_angle ); ?>deg;"
							aria-hidden="true"
							focusable="false">
							<path class="m3-reading-circle__bg" pathLength="100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
							<path class="m3-reading-circle__progress" pathLength="100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
							<circle class="m3-reading-circle__head" cx="18" cy="2.0845" r="2.15" />
						</svg>
						<span class="material-symbols-outlined" aria-hidden="true">timer</span>
					</div>
					<div class="m3-reading-badge-content">
						<span class="m3-reading-badge-text m3-reading-badge-text--main"><?php echo esc_html( $lf_time_display ); ?></span>
						<span class="m3-reading-badge-label">
							<span class="m3-badge-label-main"><?php echo esc_html( sprintf( '約%s文字', number_format_i18n( (int) $lf_reading['chars'] ) ) ); ?></span>
						</span>
						<span class="m3-reading-rank-chip" data-rank="<?php echo esc_attr( (string) $lf_reading['rank'] ); ?>">
							<span class="m3-reading-rank-chip__dot" aria-hidden="true"></span>
							<span class="m3-reading-rank-chip__label"><?php echo esc_html( (string) $lf_reading['label'] ); ?></span>
						</span>
						<span id="m3-reading-badge-desc" class="m3-reading-badge-text m3-reading-badge-text--desc">
							本文の文字数から550字/分で換算した読了目安です。
						</span>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php // Disclosure（AI / スポンサー）はメタより更に弱い強度で置く ?>
		<?php if ( function_exists( 'node_the_post_badges' ) ) : ?>
			<div class="lf-article-header__disclosure">
				<?php
				// 'compact' はアイコンだけの丸バッジになる。
				// 「生成されたメディア・テキストを含む」等の文言まで見せる広いピルにするため
				// 'full' を使う（2026-08-09 ユーザー指示）。
				node_the_post_badges( $lf_post_id, 'full', array( 'ai', 'sponsor' ) );
				?>
			</div>
		<?php endif; ?>

		<?php // 5. Intelligence Summary（Header の Paper へ統合。Title より目立たせない） ?>
		<?php if ( $lf_show_summary ) : ?>
			<details class="lf-intelligence-summary" id="m3-ai-summary" open>
				<summary class="lf-intelligence-summary__head">
					<span class="material-symbols-outlined lf-intelligence-summary__icon" aria-hidden="true">auto_awesome</span>
					<span class="lf-system-label">Intelligence Summary</span>
				</summary>
				<div class="lf-intelligence-summary__body">
					<p class="lf-intelligence-summary__text"><?php echo esc_html( wp_strip_all_tags( $lf_summary ) ); ?></p>
					<p class="lf-intelligence-summary__credit">by Gemini</p>
				</div>
			</details>
		<?php endif; ?>
	</div>
</header>
