<?php
declare(strict_types=1);

$node_native_app = function_exists( 'node_is_native_app' ) && node_is_native_app();

if ( ! $node_native_app ) {
	node_the_ad_template( 'article' );
}
?>
<?php if ( ! $node_native_app ) : ?>
    <footer class="m3-footer">
        <div class="m3-footer__main">
            <div class="m3-footer__grid">
                <!-- Brand Column -->
                <div class="m3-footer__col m3-footer__col--brand">
                    <div class="site-branding">
                        <span class="m3-logo-text"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
                    </div>
                    <p class="m3-footer__tagline">AI、ガジェット、ゲームの最新情報をあなたに。</p>
                    <?php get_template_part( 'template-parts/preferred-source', null, array( 'context' => 'footer' ) ); ?>
                    <p class="m3-footer__social-label">Official SNS</p>
                    <div class="m3-footer__social">
                        <a href="https://x.com/Luminous_Core_" target="_blank" rel="noopener noreferrer"
                           class="m3-footer__social-link m3-footer__social-link--x" aria-label="公式X（Twitter）">
                            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor">
                                <path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932Zm-1.291 19.491h2.039L6.486 3.24H4.298Z"/>
                            </svg>
                        </a>
                        <a href="https://discord.gg/QPr4RPxfAA" target="_blank" rel="noopener noreferrer"
                           class="m3-footer__social-link m3-footer__social-link--discord" aria-label="公式Discord">
                            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor">
                                <path d="M20.317 4.37a19.8 19.8 0 0 0-4.885-1.515.07.07 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.3 18.3 0 0 0-5.487 0 12.6 12.6 0 0 0-.617-1.25.08.08 0 0 0-.079-.037A19.7 19.7 0 0 0 3.677 4.37a.06.06 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.08.08 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.08.08 0 0 0 .084-.028 14.1 14.1 0 0 0 1.226-1.994.08.08 0 0 0-.041-.106 13.1 13.1 0 0 1-1.872-.892.08.08 0 0 1-.008-.128c.126-.094.251-.194.372-.292a.07.07 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.07.07 0 0 1 .078.01c.12.098.246.198.373.292a.08.08 0 0 1-.006.127 12.3 12.3 0 0 1-1.873.892.08.08 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.08.08 0 0 0 .084.028 19.8 19.8 0 0 0 6.002-3.03.08.08 0 0 0 .032-.054c.5-5.177-.838-9.674-3.548-13.66a.06.06 0 0 0-.031-.03ZM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419s.956-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418Zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419s.955-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418Z"/>
                            </svg>
                        </a>
                        <?php
                        /*
                         * RSS はヘッダーにもあるが、モバイル（≤600px）ではヘッダーの
                         * RSS・X・Discord がまとめて非表示になるため、そのままだと
                         * RSS だけサイト内のどこからも辿れなかった（2026-08-08）。
                         * 外部サービスではないので target="_blank" は付けない。
                         */
                        ?>
                        <a href="<?php bloginfo( 'rss2_url' ); ?>"
                           class="m3-footer__social-link m3-footer__social-link--rss" aria-label="RSSフィード">
                            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor">
                                <path d="M6.18 17.82a2.18 2.18 0 1 1-4.36 0 2.18 2.18 0 0 1 4.36 0ZM2 10.87v3.02c2.36 0 4.62.94 6.29 2.6A8.9 8.9 0 0 1 10.89 22h3.03c0-6.58-5.35-11.13-11.92-11.13ZM2 4v3.02c8.26 0 14.98 6.72 14.98 14.98H20C20 12.07 11.93 4 2 4Z"/>
                            </svg>
                        </a>
                    </div>
                </div>

                <?php
                /*
                 * カテゴリ列（1.3.1 ユーザー指示）。
                 * トップ以外（記事・アーカイブ・検索結果）にはカテゴリで探す導線が
                 * 一本も無く、記事を読み終わった読者の行き先がフッターの
                 * 「このブログについて / プライバシーポリシー / お問い合わせ」しか
                 * 無かった。全ページの末尾にカテゴリを置いて回遊先を作る。
                 */
                $node_footer_categories = function_exists( 'node_get_navigation_categories' )
                    ? node_get_navigation_categories( NODE_CATEGORY_NAV_FOOTER_LIMIT )
                    : array();
                ?>
                <?php if ( ! empty( $node_footer_categories ) ) : ?>
                    <!-- Category Column -->
                    <div class="m3-footer__col m3-footer__col--categories">
                        <h3 class="m3-footer__title">カテゴリ</h3>
                        <ul class="m3-footer__links">
                            <?php foreach ( $node_footer_categories as $node_footer_category ) : ?>
                                <li>
                                    <a href="<?php echo esc_url( (string) get_category_link( $node_footer_category ) ); ?>">
                                        <?php echo esc_html( $node_footer_category->name ); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Nav Column -->
                <div class="m3-footer__col m3-footer__col--nav">
                    <h3 class="m3-footer__title">ナビゲーション</h3>
                    <?php
                    wp_nav_menu(
                        array(
                            'theme_location' => 'footer',
                            'menu_class'     => 'm3-footer__links',
                            'container'      => false,
                            'fallback_cb'    => 'node_footer_menu_fallback',
                        )
                    );
                    ?>
                    <?php if ( current_user_can( 'edit_posts' ) ) : ?>
                        <ul class="m3-footer__links">

                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="m3-footer__bottom">
            <div class="m3-footer__bottom-inner">
                <p class="m3-footer__copyright">&copy; <?php echo date('Y'); ?> Luminous Core Team. <span class="m3-footer__version">v<?php echo esc_html( node_get_theme_version() ); ?></span></p>
            </div>
        </div>
    </footer>
<?php endif; ?>
</div><!-- .m3-page-container -->

<?php if ( ! $node_native_app ) : ?>
<?php get_template_part('template-parts/components/floating-actions'); ?>
<?php endif; ?>

<div id="m3-ogp-tooltip" class="m3-dynamic-tooltip"></div>
<?php wp_footer(); ?>
</body>
</html>
