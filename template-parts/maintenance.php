<?php

declare(strict_types=1);
/**
 * メンテナンス画面。
 *
 * テーマのヘッダー・フッターやプラグイン由来のウィジェットに依存させず、
 * この1ファイルで完結させる（メンテナンス中＝どこかが不調な可能性がある場面のため、
 * 依存を増やすほど「メンテナンス画面自体が壊れる」危険が上がる）。
 * そのためスタイルもビルド済みCSSではなくインラインで持つ。
 *
 * @package Luminous_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$node_mt_message             = node_maintenance_get_message();
$node_mt_eta                 = node_maintenance_get_eta();
$node_mt_started             = node_maintenance_get_effective_start();
$node_mt_has_scheduled_start = null !== node_maintenance_get_scheduled_start();
$node_mt_progress            = node_maintenance_get_progress();
$node_mt_is_admin            = current_user_can( 'manage_options' );
$node_mt_timezone            = new DateTimeZone( 'Asia/Tokyo' );
$node_mt_contact_form_url    = 'https://docs.google.com/forms/d/e/1FAIpQLSdpjC3STEpQaEzO5GSVZoLt-Kr3qLyBaWmuv0fU9XnuuGCCoA/viewform?usp=dialog';
$node_mt_contact_email       = 'luminouscore@gmail.com';

// ヘッダーと同じブランド表示にする。カスタムロゴが設定されていればそれを、
// 無ければテーマ同梱の node-logo.svg を使う（header.php と同じフォールバック）。
$node_mt_custom_logo = get_theme_mod( 'custom_logo' ) ? wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' ) : '';
$node_mt_logo        = '' !== $node_mt_custom_logo ? $node_mt_custom_logo : get_theme_file_uri( 'node-logo.svg' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( 'メンテナンス中 – ' . get_bloginfo( 'name' ) ); ?></title>
	<?php // ブランドフォント DIN 2014（header.php と同じ Adobe Fonts のキット）。読み込めなくても sans-serif へフォールバックする。 ?>
	<link rel="preconnect" href="https://use.typekit.net" crossorigin>
	<link rel="stylesheet" href="https://use.typekit.net/xzl0lmg.css">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400&amp;family=Noto+Sans+JP:wght@400..900&amp;display=swap">
	<style>
		@font-face {
			font-family: "Node Hiragino W6";
			font-style: normal;
			font-weight: 100 900;
			src: local("Hiragino Sans W6"), local("Hiragino Kaku Gothic ProN W6");
			unicode-range: U+3000-30FF, U+31F0-31FF, U+3400-4DBF, U+4E00-9FFF, U+F900-FAFF, U+FF00-FFEF;
		}
		:root {
			--mt-bg: #FFF8F0;
			--mt-surface: #FFFFFF;
			--mt-text: #3D2E1F;
			--mt-muted: #7A6A5A;
			--mt-primary: #FF9900;
			--mt-track: #F0E4D6;
			--mt-border: rgba(61, 46, 31, 0.10);
		}
		@media (prefers-color-scheme: dark) {
			:root {
				--mt-bg: #1A1512;
				--mt-surface: #241D18;
				--mt-text: #F5EDE4;
				--mt-muted: #B7A695;
				--mt-primary: #FFAA33;
				--mt-track: #3A2F26;
				--mt-border: rgba(245, 237, 228, 0.12);
			}
		}
		* { box-sizing: border-box; }
		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
			background: var(--mt-bg);
			color: var(--mt-text);
			font-family: "Node Hiragino W6", -apple-system, BlinkMacSystemFont, "Inter", "Noto Sans JP", sans-serif;
			line-height: 1.8;
			-webkit-font-smoothing: antialiased;
		}
		.mt-card {
			width: 100%;
			max-width: 560px;
			background: var(--mt-surface);
			border: 1px solid var(--mt-border);
			border-radius: 28px;
			padding: 48px 40px;
			text-align: center;
			box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
		}
		/* ブランド表示（ヘッダーと同じ構成: ロゴマーク + DIN 2014 のロゴタイプ） */
		.mt-brand {
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 12px;
			margin: 0 0 28px;
		}
		.mt-brand__mark { width: 36px; height: 36px; flex-shrink: 0; }
		.mt-brand__text {
			font-family: "din-2014", "Inter", sans-serif;
			font-size: 1.4rem;
			font-weight: 600;
			letter-spacing: 0.05em;
			text-transform: uppercase;
			line-height: 1.2;
			color: var(--mt-text);
		}
		/* 歯車アイコン（状態を静かに伝える低速回転） */
		.mt-icon {
			width: 64px;
			height: 64px;
			margin: 0 auto 10px;
			display: block;
			color: var(--mt-primary);
			transform-origin: center;
			animation: mt-gear-turn 18s linear infinite;
		}
		@keyframes mt-gear-turn {
			to { transform: rotate(360deg); }
		}
		.mt-construction-mark {
			display: inline-flex;
			width: fit-content;
			align-items: center;
			gap: 6px;
			margin: 0 auto 18px;
			color: var(--mt-muted);
			font-size: 0.64rem;
			font-weight: 800;
			letter-spacing: 0.12em;
			line-height: 1;
		}
		.mt-construction-mark__pictogram {
			display: block;
			width: auto;
			height: 22px;
			color: var(--mt-primary);
			flex: none;
		}
		h1 { margin: 0 0 16px; font-size: 1.6rem; font-weight: 900; letter-spacing: 0.02em; }
		.mt-message { margin: 0 0 32px; font-size: 1rem; color: var(--mt-muted); white-space: pre-wrap; }
		.mt-progress-wrap {
			--mt-endpoint-inset: clamp(48px, 12vw, 56px);
			position: relative;
			padding-top: 78px;
		}
		.mt-progress__status {
			position: absolute;
			top: 0;
			right: var(--mt-endpoint-inset);
			bottom: auto;
			display: block;
			min-height: 62px;
			width: 110px;
			max-width: calc(100% - var(--mt-endpoint-inset));
			padding: 0;
			border-radius: 0;
			border: 0;
			background: var(--mt-primary);
			color: #FFFFFF;
			line-height: 1.2;
			box-shadow: none;
			overflow: hidden;
			transition: width 0.3s cubic-bezier(0.2, 0, 0, 1);
		}
		.mt-progress__status.is-details {
			width: min(166px, calc(100% - var(--mt-endpoint-inset)));
		}
		.mt-progress__status.is-details.is-long-countdown {
			width: min(188px, calc(100% - var(--mt-endpoint-inset)));
		}
		.mt-progress__status-view {
			position: absolute;
			inset: 0;
			padding: 6px 8px;
			opacity: 0;
			transform: translateX(-6px);
			transition: opacity 0.2s ease, transform 0.3s cubic-bezier(0.2, 0, 0, 1);
		}
		.mt-progress__status-view--progress {
			display: flex;
			align-items: center;
			justify-content: center;
			opacity: 1;
			transform: none;
		}
		.mt-progress__status-view--details {
			display: grid;
			grid-template-columns: auto 1fr;
			align-content: center;
			align-items: baseline;
			gap: 3px 8px;
		}
		.mt-progress__status.is-details .mt-progress__status-view--progress {
			opacity: 0;
			transform: translateX(6px);
		}
		.mt-progress__status.is-details .mt-progress__status-view--details {
			opacity: 1;
			transform: none;
		}
		.mt-progress__status-value {
			font-size: 0.82rem;
			font-weight: 900;
			font-variant-numeric: tabular-nums;
			letter-spacing: 0.02em;
			white-space: nowrap;
		}
		.mt-progress__remaining-label {
			font-size: 0.67rem;
			font-weight: 700;
			letter-spacing: 0.06em;
			white-space: nowrap;
		}
		.mt-countdown {
			justify-self: end;
			font-size: 0.9rem;
			font-weight: 900;
			font-variant-numeric: tabular-nums;
			letter-spacing: 0.02em;
			white-space: nowrap;
		}
		.mt-progress__status.is-restoring .mt-progress__remaining-label {
			display: none;
		}
		.mt-progress__status.is-restoring .mt-countdown {
			grid-column: 1 / -1;
			justify-self: center;
			font-size: 0.78rem;
		}
		.mt-progress__end-time {
			grid-column: 1 / -1;
			justify-self: start;
			font-size: 0.65rem;
			font-weight: 700;
			letter-spacing: 0.02em;
			white-space: nowrap;
		}
		.mt-progress {
			width: 100%;
			height: 10px;
			border-radius: 999px;
			background: var(--mt-track);
			overflow: hidden;
		}
		.mt-progress__track-shell {
			position: relative;
			margin-inline: var(--mt-endpoint-inset);
		}
		.mt-progress__marker {
			position: absolute;
			top: 50%;
			z-index: 2;
			width: 18px;
			height: 18px;
			border: 3px solid var(--mt-surface);
			border-radius: 50%;
			box-shadow: 0 0 0 1px rgba(255, 153, 0, 0.28);
			transform: translateY(-50%);
		}
		.mt-progress__marker--start {
			left: 0;
			background: #FFDDB0;
			transform: translate(-50%, -50%);
		}
		.mt-progress__marker--end {
			right: 0;
			background: var(--mt-primary);
			transform: translate(50%, -50%);
		}
		.mt-progress__bar {
			position: relative;
			height: 100%;
			border-radius: 999px;
			background: var(--mt-primary);
			transition: none;
		}
		.mt-progress__bar.is-animating {
			background: linear-gradient(90deg, #ff8a00, #ffc266, #ff8a00);
			background-size: 200% 100%;
			animation: mt-progress-flow 1.6s linear infinite;
			transition: width var(--mt-progress-duration, 1400ms) cubic-bezier(0.2, 0, 0, 1);
			will-change: width;
		}
		.mt-progress__bar::after {
			content: "";
			position: absolute;
			top: 50%;
			right: 0;
			width: 10px;
			height: 10px;
			border-radius: 50%;
			background: #fff;
			box-shadow: 0 0 10px rgba(255, 255, 255, 0.9);
			transform: translate(35%, -50%);
			opacity: 0;
		}
		.mt-progress__bar.is-animating::after {
			animation: mt-progress-pulse 1s ease-in-out infinite alternate;
		}
		@keyframes mt-progress-flow {
			to { background-position: -200% 0; }
		}
		@keyframes mt-progress-pulse {
			from { opacity: 0.45; transform: translate(35%, -50%) scale(0.75); }
			to { opacity: 1; transform: translate(35%, -50%) scale(1); }
		}
		@media (prefers-reduced-motion: reduce) {
			.mt-icon { animation: none; }
			.mt-progress__bar.is-animating { animation: none; transition: none; }
			.mt-progress__bar.is-animating::after { animation: none; opacity: 0; }
			.mt-progress__status {
				width: min(166px, calc(100% - var(--mt-endpoint-inset)));
				transition: none;
			}
			.mt-progress__status-view { transition: none; }
			.mt-progress__status-view--progress { display: none; }
			.mt-progress__status-view--details { opacity: 1; transform: none; }
		}
		.mt-progress__labels {
			position: relative;
			min-height: 34px;
			margin: 8px var(--mt-endpoint-inset) 0;
			color: var(--mt-muted);
		}
		.mt-progress__endpoint {
			position: absolute;
			top: 0;
			display: grid;
			gap: 2px;
			width: max-content;
			font-size: clamp(0.64rem, 2.2vw, 0.72rem);
			font-variant-numeric: tabular-nums;
			line-height: 1.35;
			text-align: center;
		}
		.mt-progress__endpoint:first-child {
			left: 0;
			transform: translateX(-50%);
		}
		.mt-progress__endpoint--end {
			right: 0;
			transform: translateX(50%);
		}
		.mt-progress__endpoint-label {
			font-weight: 700;
			letter-spacing: 0.04em;
		}
		.mt-progress__endpoint time {
			font-weight: 600;
			white-space: nowrap;
		}
		.mt-progress__timezone-note {
			margin: 8px 0 0;
			font-size: 0.72rem;
			font-weight: 700;
			line-height: 1.4;
			text-align: right;
			color: var(--mt-muted);
		}
		.mt-admin {
			margin-top: 36px;
			padding-top: 24px;
			border-top: 1px solid var(--mt-border);
		}
		.mt-admin__note { margin: 0 0 12px; font-size: 0.8rem; color: var(--mt-muted); }
		.mt-admin__link {
			display: inline-block;
			padding: 10px 24px;
			border-radius: 999px;
			background: var(--mt-primary);
			color: #FFFFFF;
			font-size: 0.9rem;
			font-weight: 700;
			text-decoration: none;
		}
		.mt-admin__link:hover { opacity: 0.85; }
		/* 公式SNS（フッターと同じ導線。メンテナンス中の告知はここで追える） */
		.mt-social {
			display: flex;
			justify-content: center;
			align-items: flex-start;
			gap: 40px;
			margin-top: 32px;
			padding-top: 24px;
			border-top: 1px solid var(--mt-border);
		}
		.mt-social__group { min-width: 108px; }
		.mt-social__label {
			margin: 0 0 12px;
			font-family: "din-2014", "Inter", sans-serif;
			font-size: 0.72rem;
			font-weight: 600;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			color: var(--mt-muted);
		}
		.mt-social__links { display: flex; justify-content: center; gap: 12px; }
		.mt-social__link {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 42px;
			height: 42px;
			border-radius: 50%;
			background: var(--mt-track);
			color: var(--mt-muted);
			transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
		}
		.mt-social__link:hover { color: #FFFFFF; transform: translateY(-2px); }
		.mt-social__link--x:hover { background: #000000; }
		.mt-social__link--discord:hover { background: #5865F2; }
		.mt-social__link--contact:hover,
		.mt-social__link--mail:hover { background: var(--mt-primary); }
		@media (max-width: 480px) {
			.mt-card { padding: 36px 24px; border-radius: 22px; }
			h1 { font-size: 1.35rem; }
			.mt-brand__text { font-size: 1.2rem; }
			.mt-social { gap: 20px; }
			.mt-social__group { min-width: 0; }
		}
	</style>
</head>
<body>
	<main class="mt-card">
		<div class="mt-brand">
			<img class="mt-brand__mark" src="<?php echo esc_url( $node_mt_logo ); ?>" alt="" width="36" height="36">
			<span class="mt-brand__text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
		</div>

		<svg class="mt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<circle cx="12" cy="12" r="3"></circle>
			<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
		</svg>
		<div class="mt-construction-mark" aria-label="工事中">
			<svg class="mt-construction-mark__pictogram" viewBox="0 0 28 22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<rect x="1" y="2" width="26" height="11" rx="1"></rect>
				<path d="m5 2-4 6m12-6L5.5 13M21 2l-7.5 11M27 5l-5 8"></path>
				<path d="m7 13-2 8m16-8 2 8M2 21h6m12 0h6"></path>
			</svg>
			<span>UNDER CONSTRUCTION</span>
		</div>

		<h1>メンテナンス中です</h1>
		<p class="mt-message"><?php echo esc_html( $node_mt_message ); ?></p>

		<?php if ( null !== $node_mt_eta ) : ?>
			<?php if ( null !== $node_mt_progress ) : ?>
				<div class="mt-progress-wrap">
					<div class="mt-progress__status" aria-live="polite">
						<div class="mt-progress__status-view mt-progress__status-view--progress" aria-hidden="false">
							<strong class="mt-progress__status-value" id="mt-progress-readout" data-eta="<?php echo esc_attr( (string) $node_mt_eta ); ?>" data-now="<?php echo esc_attr( (string) time() ); ?>" data-redirect-url="<?php echo esc_url( NODE_MAINTENANCE_REDIRECT_URL ); ?>">⏱️<?php echo esc_html( (string) $node_mt_progress ); ?>/100%</strong>
						</div>
						<div class="mt-progress__status-view mt-progress__status-view--details" aria-hidden="true">
							<span class="mt-progress__remaining-label">残り時間</span>
							<strong class="mt-countdown" id="mt-countdown">--:--</strong>
							<time class="mt-progress__end-time" datetime="<?php echo esc_attr( wp_date( 'c', $node_mt_eta, $node_mt_timezone ) ); ?>">終了時刻 <?php echo esc_html( wp_date( 'Y/m/d H:i', $node_mt_eta, $node_mt_timezone ) ); ?></time>
						</div>
					</div>
					<div class="mt-progress__track-shell">
						<span class="mt-progress__marker mt-progress__marker--start" aria-hidden="true"></span>
						<div
							class="mt-progress"
							role="progressbar"
							aria-valuemin="0"
							aria-valuemax="100"
							aria-valuenow="<?php echo esc_attr( (string) $node_mt_progress ); ?>"
							aria-label="復旧予定までの進捗"
						>
							<div class="mt-progress__bar" id="mt-progress-bar" data-start="<?php echo esc_attr( (string) $node_mt_started ); ?>" style="width: <?php echo esc_attr( (string) $node_mt_progress ); ?>%;"></div>
						</div>
						<span class="mt-progress__marker mt-progress__marker--end" aria-hidden="true"></span>
					</div>
					<div class="mt-progress__labels">
						<div class="mt-progress__endpoint">
							<span class="mt-progress__endpoint-label"><?php echo esc_html( $node_mt_has_scheduled_start ? '開始予定時刻' : '開始時刻' ); ?></span>
							<time datetime="<?php echo esc_attr( wp_date( 'c', $node_mt_started, $node_mt_timezone ) ); ?>"><?php echo esc_html( wp_date( 'Y/m/d H:i', $node_mt_started, $node_mt_timezone ) ); ?></time>
						</div>
						<div class="mt-progress__endpoint mt-progress__endpoint--end">
							<span class="mt-progress__endpoint-label">終了時刻</span>
							<time datetime="<?php echo esc_attr( wp_date( 'c', $node_mt_eta, $node_mt_timezone ) ); ?>"><?php echo esc_html( wp_date( 'Y/m/d H:i', $node_mt_eta, $node_mt_timezone ) ); ?></time>
						</div>
					</div>
				</div>
				<p class="mt-progress__timezone-note">⚠️終了時刻は日本時間基準です</p>
			<?php endif; ?>
		<?php endif; ?>

		<div class="mt-social">
			<div class="mt-social__group">
				<p class="mt-social__label">Official SNS</p>
				<div class="mt-social__links">
				<a class="mt-social__link mt-social__link--x" href="https://x.com/Luminous_Core_" target="_blank" rel="noopener noreferrer" aria-label="公式X（Twitter）">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="currentColor">
						<path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932Zm-1.291 19.491h2.039L6.486 3.24H4.298Z"/>
					</svg>
				</a>
				<a class="mt-social__link mt-social__link--discord" href="https://discord.gg/QPr4RPxfAA" target="_blank" rel="noopener noreferrer" aria-label="公式Discord">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="currentColor">
						<path d="M20.317 4.37a19.8 19.8 0 0 0-4.885-1.515.07.07 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.3 18.3 0 0 0-5.487 0 12.6 12.6 0 0 0-.617-1.25.08.08 0 0 0-.079-.037A19.7 19.7 0 0 0 3.677 4.37a.06.06 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.08.08 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.08.08 0 0 0 .084-.028 14.1 14.1 0 0 0 1.226-1.994.08.08 0 0 0-.041-.106 13.1 13.1 0 0 1-1.872-.892.08.08 0 0 1-.008-.128c.126-.094.251-.194.372-.292a.07.07 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.07.07 0 0 1 .078.01c.12.098.246.198.373.292a.08.08 0 0 1-.006.127 12.3 12.3 0 0 1-1.873.892.08.08 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.08.08 0 0 0 .084.028 19.8 19.8 0 0 0 6.002-3.03.08.08 0 0 0 .032-.054c.5-5.177-.838-9.674-3.548-13.66a.06.06 0 0 0-.031-.03ZM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419s.956-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418Zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419s.955-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418Z"/>
					</svg>
				</a>
				</div>
			</div>
			<div class="mt-social__group">
				<p class="mt-social__label">Contact</p>
				<div class="mt-social__links">
					<a class="mt-social__link mt-social__link--contact" href="<?php echo esc_url( $node_mt_contact_form_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="Googleお問い合わせフォームを開く">
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
							<path d="M4 4h16v13H8l-4 3V4Z"></path>
							<path d="M8 8h8M8 12h5"></path>
						</svg>
					</a>
					<a class="mt-social__link mt-social__link--mail" href="<?php echo esc_url( 'mailto:' . $node_mt_contact_email ); ?>" aria-label="メールを作成">
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
							<rect x="3" y="5" width="18" height="14" rx="2"></rect>
							<path d="m4 7 8 6 8-6"></path>
						</svg>
					</a>
				</div>
			</div>
		</div>

		<?php if ( $node_mt_is_admin ) : ?>
			<div class="mt-admin">
				<p class="mt-admin__note">管理者としてログイン中です。この画面は訪問者にも表示されています。</p>
				<a class="mt-admin__link" href="<?php echo esc_url( admin_url( 'options-general.php?page=luminous-settings#node-maintenance' ) ); ?>">管理画面へ移動してメンテナンスを解除</a>
			</div>
		<?php endif; ?>
	</main>

	<?php if ( null !== $node_mt_eta ) : ?>
	<script>
	( function () {
		var el = document.getElementById( 'mt-progress-readout' );
		var countdown = document.getElementById( 'mt-countdown' );
		if ( ! el || ! countdown ) { return; }

		var eta = parseInt( el.getAttribute( 'data-eta' ), 10 );
		var redirectUrl = el.getAttribute( 'data-redirect-url' );
		// サーバー時刻とクライアント時刻のずれを補正する（端末の時計は当てにしない）。
		var offset = ( parseInt( el.getAttribute( 'data-now' ), 10 ) * 1000 ) - Date.now();
		var bar = document.getElementById( 'mt-progress-bar' );
		var startedAt = bar ? parseInt( bar.getAttribute( 'data-start' ), 10 ) : null;
		var status = el.closest( '.mt-progress__status' );
		var progressView = status ? status.querySelector( '.mt-progress__status-view--progress' ) : null;
		var detailsView = status ? status.querySelector( '.mt-progress__status-view--details' ) : null;
		var redirectStarted = false;

		function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }

		function animateProgressOnce() {
			if ( ! bar || ! startedAt || eta <= startedAt ) { return; }

			var now = ( Date.now() + offset ) / 1000;
			var pct = Math.max( 0, Math.min( 100, ( now - startedAt ) / ( eta - startedAt ) * 100 ) );
			var ariaValue = Math.round( pct );
			var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			var duration = Math.round( 800 + pct * 12 );

			el.textContent = '⏱️' + ariaValue + '/100%';
			bar.parentElement.setAttribute( 'aria-valuenow', ariaValue );
			bar.style.setProperty( '--mt-progress-duration', duration + 'ms' );
			bar.style.width = '0%';

			if ( reduceMotion || ! window.requestAnimationFrame ) {
				bar.style.width = pct + '%';
				return;
			}

			bar.classList.add( 'is-animating' );
			window.requestAnimationFrame( function () {
				window.requestAnimationFrame( function () {
					bar.style.width = pct + '%';
				} );
			} );

			setTimeout( function () {
				bar.classList.remove( 'is-animating' );
			}, duration + 100 );
		}

		function tick() {
			var now = ( Date.now() + offset ) / 1000;
			var remaining = eta - now;
			var left = Math.max( 0, Math.ceil( remaining ) );
			var d = Math.floor( left / 86400 );
			var h = Math.floor( ( left % 86400 ) / 3600 );
			var m = Math.floor( ( left % 3600 ) / 60 );
			var s = left % 60;

			if ( status ) { status.classList.toggle( 'is-long-countdown', d > 0 ); }
			countdown.textContent = left <= 0 ? 'まもなく復旧します' : ( d > 0 ? d + '日 ' + pad( h ) : ( h > 0 ? h : m ) ) + ':' + pad( d > 0 || h > 0 ? m : s ) + ( d > 0 || h > 0 ? ':' + pad( s ) : '' );

			if ( remaining <= 0 ) {
				el.textContent = '⏱️100/100%';
				if ( bar ) { bar.parentElement.setAttribute( 'aria-valuenow', '100' ); }
				if ( bar ) { bar.style.width = '100%'; }
				if ( status ) { status.classList.add( 'is-restoring' ); }
				if ( ! redirectStarted && redirectUrl ) {
					redirectStarted = true;
					window.location.replace( redirectUrl );
				}
				return;
			}

			setTimeout( tick, 1000 );
		}

		function setStatusDetails( showDetails ) {
			if ( ! status || ! progressView || ! detailsView ) { return; }

			status.classList.toggle( 'is-details', showDetails );
			progressView.setAttribute( 'aria-hidden', showDetails ? 'true' : 'false' );
			detailsView.setAttribute( 'aria-hidden', showDetails ? 'false' : 'true' );
		}

		function revealStatusDetails() {
			var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			if ( reduceMotion ) {
				setStatusDetails( true );
				return;
			}

			setTimeout( function () {
				setStatusDetails( true );
			}, 3000 );
		}

		animateProgressOnce();
		tick();
		revealStatusDetails();
	} )();
	</script>
	<?php endif; ?>
</body>
</html>
