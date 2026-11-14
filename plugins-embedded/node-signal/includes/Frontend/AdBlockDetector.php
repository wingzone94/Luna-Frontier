<?php
namespace Node\Signal\Frontend;

class AdBlockDetector {
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // スクリプトのエンキュー
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        // wp_footerでのモーダル出力
        add_action( 'wp_footer', [ $this, 'output_modal_html' ] );
    }

    /**
     * 広告ブロック検知用のスクリプトを読み込み
     */
    public function enqueue_scripts() {
        // 管理画面では不要なため除外
        if ( is_admin() ) {
            return;
        }

        wp_enqueue_script(
            'node-signal-adblock-detector',
            NODE_SIGNAL_PLUGIN_URL . 'assets/js/adblock-detector.js',
            [],
            NODE_SIGNAL_VERSION,
            true
        );
    }

    /**
     * wp_footer でやさしいナッジUI（モーダル）のHTMLを出力
     */
    public function output_modal_html() {
        if ( is_admin() ) {
            return;
        }
        ?>
        <!-- Node Signal: AdBlock Detector Modal -->
        <div id="ns-adblock-modal" class="ns-modal-overlay" style="display: none;" aria-hidden="true">
            <div class="ns-adblock-card" role="dialog" aria-modal="true" aria-labelledby="ns-adblock-title">
                
                <div class="ns-adblock-header">
                    <div class="ns-adblock-icon-wrapper">
                        <span class="material-symbols-outlined ns-adblock-icon" data-icon="info"></span>
                    </div>
                    <h2 id="ns-adblock-title" class="ns-adblock-title">広告ブロックが検出されました</h2>
                </div>
                
                <div class="ns-adblock-desc">
                    <p>このサイトは広告収益で運営されています。<br>広告は最小限に抑えています。</p>
                </div>

                <div class="ns-adblock-timer-wrapper">
                    <svg class="ns-adblock-progress-ring" width="100" height="100">
                        <circle class="ns-adblock-progress-ring__circle-bg" stroke="#FFF3E0" stroke-width="6" fill="transparent" r="44" cx="50" cy="50"/>
                        <circle class="ns-adblock-progress-ring__circle" stroke="#FF9900" stroke-width="6" fill="transparent" r="44" cx="50" cy="50"/>
                    </svg>
                    <div class="ns-adblock-timer-text">
                        <span id="ns-countdown-timer">15</span>
                        <span class="ns-adblock-timer-unit">秒</span>
                    </div>
                </div>

                <p class="ns-adblock-hint">操作がない場合、おすすめの選択が適用されます</p>

                <div class="ns-adblock-actions">
                    <button id="ns-btn-allow-ads" class="ns-btn ns-btn--primary">
                        <span class="material-symbols-outlined" data-icon="web_asset"></span>
                        広告を表示して読み進める
                    </button>
                    <button id="ns-btn-continue-without-ads" class="ns-btn ns-btn--outline" disabled>
                        <span class="material-symbols-outlined" data-icon="health_and_safety"></span>
                        広告なしで続ける
                    </button>
                </div>

                <div class="ns-adblock-footer-msg">
                    <span class="material-symbols-outlined" data-icon="favorite"></span>
                    <span>みなさまのご協力が、良質なコンテンツの継続につながります。</span>
                </div>

            </div>
        </div>
        <?php
    }
}
