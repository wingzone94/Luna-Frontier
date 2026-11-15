<?php
/**
 * Real WordPress integration checks (no database writes).
 *
 * PRODUCT_CARD_TEST_MODE=embedded|standalone|missing wp --skip-plugins --skip-themes eval-file scripts/product-card-regression.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through wp eval-file with --skip-plugins --skip-themes.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );
$mode = getenv( 'PRODUCT_CARD_TEST_MODE' ) ?: 'embedded';
$GLOBALS['product_card_failures'] = 0;
function product_card_check( bool $condition, string $description ): void {

	echo ( $condition ? 'ok ' : 'FAIL ' ) . $description . "\n";
	$GLOBALS['product_card_failures'] += $condition ? 0 : 1;
}

// Emulate standalone plugins loading before theme utilities, and embedded plugins after them.
if ( 'standalone' === $mode ) {
	require $root . '/plugins-embedded/luminous-nexus/luminous-nexus.php';
	luminous_nexus_init();
}
require $root . '/inc/utilities.php';
require $root . '/plugins-embedded/node-library/node-library.php';
$library = Node_Library::instance();
$library->register_gutenberg_block();
$block = WP_Block_Type_Registry::get_instance()->get_registered( 'node-library/product-card' );
product_card_check( null !== $block, 'Existing product block name is registered' );
product_card_check( true === $block->attributes['showAmazonDisclosure']['default'], 'Block disclosure default remains true' );
product_card_check( WP_Block_Type_Registry::get_instance()->is_registered( 'node-library/item-card' ), 'Other Library blocks remain available' );

if ( 'missing' === $mode ) {
	product_card_check( '' === $library->render_product_card_block( array( 'asin' => 'B012345678' ) ), 'Missing Nexus returns empty output without a fatal error' );
	product_card_check( ! shortcode_exists( 'm3_product' ) && ! shortcode_exists( 'product_card' ), 'No dangling theme shortcode callbacks without Nexus' );
	$library->enqueue_block_assets();
	$data = implode( '', wp_scripts()->get_data( 'node-library-block-editor', 'before' ) );
	product_card_check( str_contains( $data, '"productCardAvailable":false' ), 'Editor receives missing-dependency state' );
	exit( $GLOBALS['product_card_failures'] ? 1 : 0 );
}
if ( 'embedded' === $mode ) {
	require $root . '/plugins-embedded/luminous-nexus/luminous-nexus.php';
	luminous_nexus_init();
}
// Repeated initialization must not duplicate hooks or change registrations.
luminous_nexus_init();
product_card_check( $GLOBALS['shortcode_tags']['product_card'] === $GLOBALS['shortcode_tags']['m3_product'], 'Both aliases retain the same callback after bootstrap' );
product_card_check( is_callable( $GLOBALS['shortcode_tags']['m3_product'] ), 'Legacy alias callback is callable' );

add_filter( 'pre_option_luminous_nexus_amazon_id', static function () { return 'fixture-22'; } );
add_filter( 'pre_option_luminous_nexus_disclosure_amazon', static function () { return 'Amazon fixture <disclosure>'; } );
add_filter( 'pre_option_luminous_nexus_disclosure_rakuten', static function () { return 'Rakuten fixture disclosure'; } );
$short = array(
	'title' => '商品 <script>alert(1)</script> & title',
	'price' => '￥2,980',
	'image_url' => 'https://example.com/product.png',
	'amazon_url' => 'https://www.amazon.co.jp/dp/B012345678?ref=a&language=ja#reviews',
	'rakuten_url' => 'https://item.rakuten.co.jp/shop/item/?a=1&b=2',
	'show_amazon_disclosure' => '1',
	'show_rakuten_disclosure' => '1',
);
$block_attributes = array(
	'title' => $short['title'], 'price' => $short['price'], 'imageUrl' => $short['image_url'],
	'amazonUrl' => $short['amazon_url'], 'rakutenUrl' => $short['rakuten_url'],
	'showAmazonDisclosure' => true, 'showRakutenDisclosure' => true,
);
$html = luminous_nexus_product_card_shortcode( $short );
product_card_check( $html === $library->render_product_card_block( $block_attributes ), 'Shortcode and block render identical HTML for equivalent inputs' );
product_card_check( ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ), 'Product text is escaped' );
product_card_check( str_contains( $html, '&lt;disclosure&gt;' ), 'Disclosure text is escaped' );
product_card_check( ! str_contains( $html, 'm3-reveal' ), 'Rendering has no reveal-script dependency' );
product_card_check( 2 === substr_count( $html, 'aria-hidden="true"' ), 'Disclosure icons are decorative' );
product_card_check( str_contains( $html, 'noopener noreferrer sponsored' ), 'Affiliate links retain safe sponsored attributes' );

$alias = '[%s title="Test" asin="B012345678"]';
product_card_check( do_shortcode( sprintf( $alias, 'm3_product' ) ) === do_shortcode( sprintf( $alias, 'product_card' ) ), 'Actual shortcode parser resolves both aliases identically' );
$plain_short = luminous_nexus_product_card_shortcode( array( 'asin' => 'B012345678' ) );
$plain_block = $library->render_product_card_block( array( 'asin' => 'B012345678' ) );
product_card_check( ! str_contains( $plain_short, 'fixture disclosure' ) && ! str_contains( $plain_short, 'fixture &lt;' ), 'Shortcode disclosures stay opt-in' );
product_card_check( str_contains( $plain_block, 'Amazon fixture' ) && str_contains( $plain_block, 'Rakuten fixture' ), 'Block omitted disclosure attributes stay enabled' );
product_card_check( ! str_contains( $library->render_product_card_block( array( 'asin' => 'B012345678', 'showAmazonDisclosure' => false, 'showRakutenDisclosure' => false ) ), 'Amazon fixture' ), 'Explicit false disclosure is preserved' );

function product_test_links( array $attributes ): array {
	return luminous_nexus_product_card_links( luminous_nexus_normalize_product_card( $attributes ) );
}
$links = product_test_links( $short );
product_card_check( 'https://www.amazon.co.jp/dp/B012345678?ref=a&language=ja&tag=fixture-22#reviews' === $links['amazon_url'], 'Multiple query arguments and fragment survive tag insertion' );
foreach ( array( 'kept-22', '' ) as $existing_tag ) {
	$url = 'https://www.amazon.co.jp/dp/B012345678?tag=' . $existing_tag . '&ref=a#reviews';
	product_card_check( $url === product_test_links( array( 'amazon_url' => $url ) )['amazon_url'], 'Existing tag is never overwritten: ' . $existing_tag );
}
product_card_check( 'https://www.amazon.co.jp/dp/B098765432?tag=fixture-22' === product_test_links( array( 'asin' => 'B098765432', 'amazon_url' => $short['amazon_url'] ) )['amazon_url'], 'Valid ASIN takes precedence' );
product_card_check( '' === product_test_links( array( 'asin' => '../bad?tag=evil' ) )['amazon_url'], 'Invalid ASIN cannot generate a malformed purchase URL' );
foreach ( array( 'javascript:alert(1)', 'data:text/html,bad', '/relative', 'example.com', 'https://', 'https://user:pass@example.com/a' ) as $invalid ) {
	product_card_check( '' === luminous_nexus_render_product_card( array( 'amazon_url' => $invalid, 'rakuten_url' => $invalid, 'nintendo_url' => $invalid ) ), 'Unsafe/invalid links are not rendered: ' . $invalid );
}
product_card_check( '' === luminous_nexus_render_product_card( array() ), 'No links produces no empty card' );
$rakuten = luminous_nexus_render_product_card( array( 'rakuten_url' => $short['rakuten_url'], 'image_url' => 'javascript:bad' ) );
product_card_check( 1 === substr_count( $rakuten, 'rel="noopener noreferrer sponsored"' ) && ! str_contains( $rakuten, 'm3-product-card__image' ), 'Single shop and invalid/missing image work without empty elements' );
product_card_check( $short['rakuten_url'] === $links['rakuten_url'], 'Rakuten URL is not rewritten' );

// Task 1: My Nintendo Store single-store and multi-store rendering.
$nintendo_url   = 'https://store-jp.nintendo.com/item/software/D70010000130791';
$nintendo_short = luminous_nexus_product_card_shortcode(
	array(
		'title'        => 'ゼルダの伝説 時のオカリナ',
		'price'        => '￥7,980',
		'nintendo_url' => $nintendo_url,
	)
);
$nintendo_block = $library->render_product_card_block(
	array(
		'title'       => 'ゼルダの伝説 時のオカリナ',
		'price'       => '￥7,980',
		'nintendoUrl' => $nintendo_url,
	)
);
product_card_check( '' !== $nintendo_short && $nintendo_short === $nintendo_block, 'Nintendo-only card renders identically via shortcode and block' );
product_card_check( str_contains( $nintendo_short, 'My Nintendo Storeで見る' ) && str_contains( $nintendo_short, 'm3-product-btn--nintendo' ), 'Nintendo button text and class are rendered' );
product_card_check( str_contains( $nintendo_short, 'https://www.google.com/s2/favicons?domain=store-jp.nintendo.com&amp;sz=32' ) || str_contains( $nintendo_short, 'https://www.google.com/s2/favicons?domain=store-jp.nintendo.com&sz=32' ), 'Nintendo button uses Google favicon for store-jp.nintendo.com' );
product_card_check( ! str_contains( $nintendo_short, 'sponsored' ) && ! str_contains( $nintendo_short, 'm3-product-card__disclosures' ), 'Nintendo-only card omits sponsored rel and affiliate disclosures' );

$nintendo_amazon = luminous_nexus_render_product_card(
	array(
		'title'                  => 'ゼルダの伝説 時のオカリナ',
		'asin'                   => 'B012345678',
		'nintendo_url'           => $nintendo_url,
		'show_amazon_disclosure' => true,
	)
);
product_card_check( str_contains( $nintendo_amazon, 'Amazonで見る' ) && str_contains( $nintendo_amazon, 'My Nintendo Storeで見る' ), 'Nintendo + Amazon card renders both buttons' );
product_card_check( 1 === substr_count( $nintendo_amazon, 'rel="noopener noreferrer sponsored"' ) && 1 === substr_count( $nintendo_amazon, 'rel="noopener noreferrer"' ), 'Amazon keeps sponsored rel while Nintendo uses non-sponsored rel' );
$library->enqueue_block_assets();
product_card_check( str_contains( implode( '', wp_scripts()->get_data( 'node-library-block-editor', 'before' ) ), '"productCardAvailable":true' ), 'Editor receives available renderer state' );
echo "Mode: {$mode}; failures: {$GLOBALS['product_card_failures']}\n";
exit( $GLOBALS['product_card_failures'] ? 1 : 0 );
