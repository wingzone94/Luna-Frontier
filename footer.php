<?php

declare( strict_types=1 );
/**
 * Luna Frontier 2.0 / SkyAlow — フッター
 *
 * 親 footer.php からの変更点:
 * - Paper Design（四角・面と罫で階層をつくる）へ
 * - Official SNS は公式アカウントだけにする
 *   （RSS は 2026-08-09 のユーザー指示でフッターから削除。ヘッダーには残っている）
 * - バージョン表記を Luna Frontier 2.0 の表示用バージョンにする。
 *   親の node_get_theme_version() は Node（親）のバージョンを返すため使わない
 * - ブランドクロームなので Dynamic Color は流し込まない（§23 / §38）
 *
 * @package LunaFrontier
 */

node_the_ad_template( 'article' );
?>
	<footer class="m3-footer lf-footer">
		<div class="m3-footer__main lf-footer__main">
			<div class="lf-footer__grid">

				<div class="lf-footer__col lf-footer__col--brand">
					<div class="site-branding">
						<span class="m3-logo-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
					</div>
					<p class="lf-footer__tagline">AI、ガジェット、ゲームの最新情報をあなたに。</p>
					<?php get_template_part( 'template-parts/preferred-source', null, array( 'context' => 'footer' ) ); ?>

					<h2 class="lf-footer__label lf-system-label">Official SNS</h2>
					<div class="lf-footer__social">
						<a href="https://x.com/Luminous_Core_" target="_blank" rel="noopener noreferrer"
							class="lf-footer__social-link" aria-label="公式X（Twitter）">
							<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor">
								<path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932Zm-1.291 19.491h2.039L6.486 3.24H4.298Z"/>
							</svg>
						</a>
						<a href="https://discord.gg/QPr4RPxfAA" target="_blank" rel="noopener noreferrer"
							class="lf-footer__social-link" aria-label="公式Discord">
							<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor">
								<path d="M20.317 4.37a19.8 19.8 0 0 0-4.885-1.515.07.07 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.3 18.3 0 0 0-5.487 0 12.6 12.6 0 0 0-.617-1.25.08.08 0 0 0-.079-.037A19.7 19.7 0 0 0 3.677 4.37a.06.06 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.08.08 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.08.08 0 0 0 .084-.028 14.1 14.1 0 0 0 1.226-1.994.08.08 0 0 0-.041-.106 13.1 13.1 0 0 1-1.872-.892.08.08 0 0 1-.008-.128c.126-.094.251-.194.372-.292a.07.07 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.07.07 0 0 1 .078.01c.12.098.246.198.373.292a.08.08 0 0 1-.006.127 12.3 12.3 0 0 1-1.873.892.08.08 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.08.08 0 0 0 .084.028 19.8 19.8 0 0 0 6.002-3.03.08.08 0 0 0 .032-.054c.5-5.177-.838-9.674-3.548-13.66a.06.06 0 0 0-.031-.03ZM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419s.956-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418Zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419s.955-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418Z"/>
							</svg>
						</a>
					</div>
				</div>

				<div class="lf-footer__col lf-footer__col--nav">
					<h2 class="lf-footer__label lf-system-label">Navigation</h2>
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'footer',
							'menu_class'     => 'lf-footer__links',
							'container'      => false,
							'fallback_cb'    => 'node_footer_menu_fallback',
						)
					);
					?>
				</div>
			</div>
		</div>

		<div class="lf-footer__bottom">
			<div class="lf-footer__bottom-inner">
				<p class="lf-footer__copyright">
					&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Luminous Core Team.
					<span class="lf-footer__version">Luna Frontier v<?php echo esc_html( luna_frontier_display_version() ); ?></span>
				</p>

			</div>
		</div>
	</footer>
</div><!-- .m3-page-container -->

<?php get_template_part( 'template-parts/components/floating-actions' ); ?>

<div id="m3-ogp-tooltip" class="m3-dynamic-tooltip"></div>
<?php wp_footer(); ?>
</body>
</html>
