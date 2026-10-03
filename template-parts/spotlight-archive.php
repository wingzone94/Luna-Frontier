<?php
/**
 * Editorial spotlight archive at /spotlight/.
 *
 * @package LunaFrontier
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lf_features = function_exists( 'node_get_spotlight_categories' ) ? node_get_spotlight_categories() : array();
$lf_features = array_values( $lf_features );

get_header();
?>
<main id="primary" class="site-main lf-spotlight-archive" aria-labelledby="lf-spotlight-title">
	<nav class="lf-spotlight-archive__breadcrumbs" aria-label="<?php esc_attr_e( 'パンくずリスト', 'node' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'ホーム', 'node' ); ?></a>
		<span aria-hidden="true">/</span>
		<span aria-current="page">SPOTLIGHT</span>
	</nav>
	<header class="lf-spotlight-archive__header">
		<p class="lf-spotlight-archive__eyebrow">SPOTLIGHT ARCHIVE</p>
		<h1 id="lf-spotlight-title"><?php esc_html_e( 'スポットライトアーカイブ', 'node' ); ?></h1>
		<p class="lf-spotlight-archive__intro"><?php esc_html_e( '気になるテーマを、もっと深く。', 'node' ); ?></p>
	</header>

	<?php if ( $lf_features ) : ?>
			<section class="lf-spotlight-archive__collection" aria-labelledby="lf-spotlight-collection-title">
				<div class="lf-spotlight-archive__section-header">
					<h2 id="lf-spotlight-collection-title"><?php esc_html_e( 'これまでの特集', 'node' ); ?></h2>
					<p><?php esc_html_e( 'テーマから探す', 'node' ); ?></p>
				</div>
				<div class="lf-spotlight-archive__grid">
		<?php foreach ( $lf_features as $lf_index => $lf_feature ) : ?>
			<?php
			$lf_first        = 0 === $lf_index;
			$lf_image_id    = luna_frontier_spotlight_image_id( $lf_feature );
			$lf_image       = $lf_image_id ? wp_get_attachment_image(
				$lf_image_id,
				'large',
				false,
				array(
					'alt'           => '',
					'loading'       => $lf_first ? 'eager' : 'lazy',
					'fetchpriority' => $lf_first ? 'high' : 'auto',
					'sizes'         => '(max-width: 760px) 100vw, 700px',
				)
			) : '';
			$lf_description = trim( wp_strip_all_tags( (string) ( $lf_feature['description'] ?? '' ) ) );
			?>


			<article class="lf-spotlight-archive__feature">
				<a class="lf-spotlight-archive__link" href="<?php echo esc_url( $lf_feature['url'] ); ?>" aria-labelledby="lf-spotlight-feature-<?php echo esc_attr( (string) $lf_index ); ?>">
					<div class="lf-spotlight-archive__media<?php echo $lf_image ? '' : ' lf-spotlight-archive__media--cover'; ?>">
						<?php if ( $lf_image ) : ?>
							<?php echo $lf_image; // WordPress generates escaped attachment markup. ?>
						<?php else : ?>
							<div class="lf-spotlight-archive__cover" aria-hidden="true">
								<span class="lf-spotlight-archive__cover-index"><?php echo esc_html( sprintf( '%02d', $lf_index + 1 ) ); ?></span>
								<span class="lf-spotlight-archive__cover-name"><?php echo esc_html( $lf_feature['name'] ); ?></span>
								<span class="lf-spotlight-archive__cover-label">LUMINOUS CORE / SPOTLIGHT</span>
							</div>
						<?php endif; ?>
					</div>
					<div class="lf-spotlight-archive__text">
						<p class="lf-spotlight-archive__kicker"><?php esc_html_e( '特集', 'node' ); ?></p>
						<h3 id="lf-spotlight-feature-<?php echo esc_attr( (string) $lf_index ); ?>"><?php echo esc_html( $lf_feature['name'] ); ?></h3>
						<?php if ( '' !== $lf_description ) : ?>
							<p class="lf-spotlight-archive__description"><?php echo esc_html( $lf_description ); ?></p>
						<?php endif; ?>
						<div class="lf-spotlight-archive__meta">
							<span><?php
								/* translators: %s: number of posts in the feature. */
								printf( esc_html__( '%s件の記事', 'node' ), esc_html( number_format_i18n( (int) ( $lf_feature['count'] ?? 0 ) ) ) );
							?></span>
							<span class="lf-spotlight-archive__action"><?php esc_html_e( '特集を読む', 'node' ); ?><span aria-hidden="true"> →</span></span>
						</div>
					</div>
				</a>
			</article>
		<?php endforeach; ?>
				</div>
			</section>
	<?php else : ?>
		<div class="lf-spotlight-archive__empty">
			<p><?php esc_html_e( '現在、掲載中の特集はありません。', 'node' ); ?></p>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'ホームへ戻る', 'node' ); ?></a>
		</div>
	<?php endif; ?>
</main>
<?php get_footer(); ?>
