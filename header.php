<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 — ヘッダー
 *
 * ヘッダー本体は template-parts/legacy/header.php（Node 1.x から引き継いだこのテーマのテンプレート）。
 * ここではそれを読み、ヘッダーの真下にトピックナビを足す。別テーマのファイルは参照しない。
 *
 * @package LunaFrontier
 */

require get_stylesheet_directory() . '/template-parts/legacy/header.php';

get_template_part( 'template-parts/luna/topic-nav' );
