<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — AI Summary の二重表示ガード（Phase 4）
 *
 * single モードの Intelligence Summary は Article Header の Paper へ統合済み
 * （template-parts/single/hero.php）。親 single.php はヒーローの後に改めて
 * この template part を呼ぶため、ここで single モードだけを止める。
 *
 * card モード等は親テンプレートへそのまま委譲する（挙動を変えない）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lf_mode = $args['mode'] ?? 'card';

if ( 'single' === $lf_mode ) {
	return;
}

require get_template_directory() . '/template-parts/ai-summary.php';
