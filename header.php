<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — ヘッダー
 *
 * 親テーマ Node 1.3 の header.php を「コピーせずに」拡張する。
 * header.php には <head>・検索ダイアログ・テーマ初期化スクリプトが同居しており、
 * 丸ごと子へ複製すると親の更新から取り残される（§77: Node Theme 全体コピー禁止）。
 *
 * 親をそのまま実行し、ヘッダーの真下にトピックナビを追加する。
 * アイコンの構成は本番 Node 1.3.2 と同じ親テンプレートを使う。
 *
 * @package LunaFrontier
 */

require get_template_directory() . '/header.php';

get_template_part( 'template-parts/luna/topic-nav' );
