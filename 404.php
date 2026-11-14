<?php

declare(strict_types=1);
/**
 * 404 page template.
 *
 * @package Node
 */

get_header();

$node_404_links = array(
	array(
		'url'   => home_url( '/' ),
		'icon'  => 'home',
		'label' => 'ホームに戻る',
		'class' => 'm3-button--filled',
	),
	array(
		'url'   => node_get_all_articles_url(),
		'icon'  => 'article',
		'label' => '記事一覧',
		'class' => 'm3-button--text',
	),
	array(
		'url'   => node_get_headlines_url(),
		'icon'  => 'newspaper',
		'label' => 'ニュース',
		'class' => 'm3-button--text',
	),
);
?>
<main id="primary" class="site-main m3-home-layout">
	<?php node_the_breadcrumbs(); ?>

	<section class="m3-404-lite m3-surface m3-section-spacing" aria-labelledby="node-404-title">
		<p class="m3-404-lite__code">404</p>
		<h1 id="node-404-title" class="m3-404-lite__title">ページが見つかりませんでした</h1>
		<p class="m3-404-lite__message">URLが変更されたか、ページが削除された可能性があります。</p>

		<div class="m3-404-lite__search">
			<form role="search" method="get" class="m3-404-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<input type="search" class="m3-404-search__field" placeholder="キーワードで検索" value="" name="s" />
				<button type="submit" class="m3-404-search__submit" aria-label="検索">
					<span class="material-symbols-outlined" data-icon="search" aria-hidden="true"></span>
				</button>
			</form>
		</div>

		<nav class="m3-404-lite__links" aria-label="主要ページ">
			<?php foreach ( $node_404_links as $node_404_link ) : ?>
				<a class="m3-button <?php echo esc_attr( $node_404_link['class'] ); ?>" href="<?php echo esc_url( $node_404_link['url'] ); ?>">
					<span class="material-symbols-outlined" data-icon="<?php echo esc_attr( $node_404_link['icon'] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $node_404_link['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	</section>
</main>
<?php get_footer(); ?>
