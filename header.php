<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" id="m3-viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <?php
    /*
     * ブラウザUI（アドレスバー等）の色。以前はブランドオレンジ #FF9900 のベタで、
     * 読み込み中の画面上端がオレンジに塗られていた（2026-08-08 ユーザー指示で廃止）。
     * サイトの面の色に合わせ、ライト/ダークで出し分ける。
     */
    ?>
    <meta name="theme-color" content="#FFF4E5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1B1812" media="(prefers-color-scheme: dark)">
    <link rel="apple-touch-icon" href="<?php echo esc_url( get_template_directory_uri() . '/assets/pwa/apple-touch-icon-180.png' ); ?>">
    <link rel="manifest" href="<?php echo get_template_directory_uri(); ?>/manifest.json">
    <?php
    /*
     * iOS の起動スプラッシュ（apple-touch-startup-image・10解像度）は廃止した
     * （2026-08-08 ユーザー指示）。ロゴを載せた全画面の起動画面は、出るたびに
     * 一瞬挟まるだけで情報がなく、端末ごとに解像度を10枚用意する維持コストにも
     * 見合わない。指定がなければ iOS は manifest の background_color
     * （#1B1812 = サイトのダーク面）で塗るので、暗い無地から本体へ入る。
     */
    ?>
    <link rel="mask-icon" href="<?php echo get_template_directory_uri(); ?>/node-logo.svg" color="#FF9900">
    <link rel="icon" type="image/svg+xml" href="<?php echo get_template_directory_uri(); ?>/node-logo.svg">
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- High Performance Font Loading Pattern -->
    <!-- 本文フォント: 非同期ロード + swap (テキストのFOUTは許容) -->
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Inter:wght@400;500;700&family=Noto+Sans+JP:wght@400;500;700&display=swap">
    <!-- アイコンフォント: display=block + レンダーブロッキングで、グリフ到着前にリガチャ文字が出ないようにする。
         使うアイコンだけを icon_names= で要求する（全アイコンだと 3,874KB）。一覧は inc/icon-font.php。 -->
    <?php $node_icon_font_url = node_get_icon_font_url(); ?>
    <link rel="preload" as="style" href="<?php echo esc_url( $node_icon_font_url ); ?>">
    <link rel="stylesheet" href="<?php echo esc_url( $node_icon_font_url ); ?>">
    <!-- Adobe Fonts kit: edit at fonts.adobe.com to load DIN 2014 only -->
    <link rel="stylesheet" href="https://use.typekit.net/xzl0lmg.css">

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Inter:wght@400;500;700&family=Noto+Sans+JP:wght@400;500;700&display=swap" media="print" onload="this.media='all'">

    <noscript>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Inter:wght@400;500;700&family=Noto+Sans+JP:wght@400;500;700&display=swap">
        <style>
            body { opacity: 1 !important; visibility: visible !important; }
        </style>
    </noscript>
    <script>
    (function() {
        const THEME_KEY = 'node_theme';
        const NODE_DEBUG = false;
        const applyTheme = (theme) => {
            if (NODE_DEBUG) console.log('[Theme] Applying:', theme);
            document.documentElement.setAttribute('data-theme', theme);
            document.body.setAttribute('data-theme', theme);
            try {
                localStorage.setItem(THEME_KEY, theme);
            } catch (e) {}
        };

        // --- 1. Initial Load (FOUC対策: 保存済みテーマを早期適用) ---
        // クリックによる切り替えとアイコン更新は color-mode.js に一本化している。
        try {
            const saved = localStorage.getItem(THEME_KEY);
            if (saved === 'dark' || saved === 'light') {
                applyTheme(saved);
            }
        } catch (e) {}

        // タブレット表示モード（タブレットUAは初期モバイル、保存済み設定を優先）
        const VIEW_STORE_KEY = 'm3_store_view-mode';
        const IS_TABLET_UA = <?php echo node_is_tablet_ua() ? 'true' : 'false'; ?>;
        try {
            const viewRaw = localStorage.getItem(VIEW_STORE_KEY);
            const savedViewMode = viewRaw ? JSON.parse(viewRaw) : null;
            const viewMode = (savedViewMode === 'mobile' || savedViewMode === 'pc')
                ? savedViewMode
                : (IS_TABLET_UA ? 'mobile' : null);
            const viewport = document.getElementById('m3-viewport');

            if (IS_TABLET_UA) {
                document.documentElement.setAttribute('data-device-class', 'tablet');
            }

            if (viewport && viewMode === 'mobile') {
                viewport.setAttribute('content', 'width=390, initial-scale=1, viewport-fit=cover');
                document.documentElement.setAttribute('data-view-mode', 'mobile');
            } else if (viewport && viewMode === 'pc') {
                viewport.setAttribute('content', 'width=1280, initial-scale=1, viewport-fit=cover');
                document.documentElement.setAttribute('data-view-mode', 'pc');
            }
        } catch (e) {}

    })();
    </script>
<!-- node-build-id: 20260613-180500 -->
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="m3-page-container">

<!-- 1. Header Section -->
<header id="masthead" class="m3-header">
    <div class="m3-header__inner">
        <!-- Header Left: Branding Only -->
        <div class="m3-header__left">
            <div class="site-branding">
                <?php if (has_custom_logo()) : the_custom_logo(); else : ?>
                    <a href="<?php echo esc_url(home_url('/')); ?>" rel="home" class="m3-header__logo-link">
                        <img src="<?php echo esc_url(get_theme_file_uri('node-logo.svg')); ?>" alt="LUMINOUS CORE" class="m3-header__logo-img" width="32" height="32">
                        <span class="m3-logo-text">LUMINOUS CORE</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Header Right: Action Group -->
        <div class="m3-header__actions">
            <!-- Search Control -->
            <div class="m3-search-container">
                <form role="search" method="get" class="m3-search-bar" id="m3-main-search-form" action="<?php echo esc_url(home_url('/')); ?>">
                    <div class="m3-search-input-wrapper">
                        <button type="button" class="m3-icon-button m3-search-mobile-close" id="m3-search-mobile-close" aria-label="検索を閉じる">
                            <span class="material-symbols-outlined">arrow_back</span>
                        </button>
                        <input type="search" class="m3-search-bar__input" id="m3-search-input" placeholder="検索..." value="<?php echo esc_attr( get_search_query() ); ?>" name="s" autocomplete="off" enterkeyhint="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="m3-search-suggestions">
                        <div class="m3-search-actions-inline">
                            <button type="button" class="m3-icon-button m3-search-clear" id="m3-search-clear" aria-label="キーワードをクリア"<?php echo get_search_query() ? '' : ' hidden'; ?>>
                                <span class="material-symbols-outlined" aria-hidden="true">close</span>
                            </button>
                            <button type="submit" class="m3-icon-button m3-search-submit" id="m3-search-submit" aria-label="検索を実行">
                                <span class="material-symbols-outlined" aria-hidden="true">search</span>
                            </button>
                            <button type="button" class="m3-icon-button m3-search-advanced-trigger" id="m3-advanced-search-trigger" aria-label="詳細検索" aria-haspopup="dialog" aria-expanded="false" aria-controls="m3-advanced-search-modal">
                                <span class="material-symbols-outlined" aria-hidden="true">tune</span>
                            </button>
                        </div>
                        <?php
                        /*
                         * 検索キーワードのサジェスト（Node Library / カテゴリ）の受け皿。
                         * 中身は src/scripts/search-bar.js が REST（node/v1/search/suggest）から描画する。
                         * `.m3-search-input-wrapper` の内側に置くのは、検索起動時に position:relative へ
                         * 切り替わるこの要素が入力欄と同じ幅・同じ左端になるため。
                         */
                        ?>
                        <div id="m3-search-suggestions" class="m3-suggestions-list m3-search-suggestions" role="listbox" aria-label="検索キーワードの候補"></div>
                    </div>
                    <button type="button" class="m3-icon-button m3-search-bar__toggle m3-tooltip-target" id="search-toggle" aria-label="検索" data-tooltip="検索">
                        <span class="material-symbols-outlined">search</span>
                    </button>
                </form>
                <script>
                (function () {
                    var form = document.getElementById('m3-main-search-form');
                    var input = document.getElementById('m3-search-input');
                    var clearBtn = document.getElementById('m3-search-clear');
                    if (!form || !input || !clearBtn) return;

                    var clearing = false;
                    var DISSIPATE_MS = 200;

                    function updateClearBtn() {
                        var hasValue = Boolean(input.value && input.value.trim());
                        clearBtn.hidden = !hasValue;
                        clearBtn.setAttribute('aria-hidden', hasValue ? 'false' : 'true');
                    }

                    // 読了ゲージ破壊時（playBarShatterAnimation）と同じ破片演出
                    function spawnShards() {
                        var text = input.value;
                        if (!text || !input.animate) return;
                        var cs = window.getComputedStyle(input);
                        var probe = document.createElement('span');
                        probe.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;';
                        probe.style.font = cs.font;
                        probe.style.letterSpacing = cs.letterSpacing;
                        probe.textContent = text;
                        document.body.appendChild(probe);
                        var inputRect = input.getBoundingClientRect();
                        var textW = Math.min(probe.getBoundingClientRect().width, inputRect.width);
                        probe.remove();
                        // ダークモードは純白の光の粒、ライトモードは文字色（白だと明るい背景に溶けて見えないため）
                        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                        var color = isDark ? '#ffffff' : cs.color;
                        var baseX = inputRect.left + (parseFloat(cs.paddingLeft) || 0);
                        var midY = inputRect.top + inputRect.height / 2;
                        var count = Math.max(8, Math.min(20, Math.round(textW / 8)));
                        for (var i = 0; i < count; i++) {
                            var shard = document.createElement('span');
                            shard.className = 'm3-search-shard';
                            shard.style.backgroundColor = color;
                            shard.style.left = (baseX + Math.random() * Math.max(textW, 1)) + 'px';
                            shard.style.top = midY + 'px';
                            document.body.appendChild(shard);
                            var angle = Math.random() * Math.PI + Math.PI; // 上半円へ飛ばす
                            var dist = 30 + Math.random() * 90;
                            var anim = shard.animate([
                                { transform: 'translate(0,0) rotate(0deg) scale(1)', opacity: 1 },
                                { transform: 'translate(' + (Math.cos(angle) * dist).toFixed(1) + 'px,' + (Math.sin(angle) * dist).toFixed(1) + 'px) rotate(' + Math.round(Math.random() * 720 - 360) + 'deg) scale(0)', opacity: 0 }
                            ], { duration: 800 + Math.random() * 400, easing: 'cubic-bezier(0.165, 0.84, 0.44, 1)', fill: 'forwards' });
                            anim.onfinish = (function (el) { return function () { el.remove(); }; })(shard);
                        }
                    }

                    // 破片バースト + 文字の高速フェードで消す
                    function animateClear(done) {
                        if (clearing) return;
                        if (!input.value || !input.value.trim()) {
                            if (done) done();
                            return;
                        }
                        clearing = true;

                        spawnShards();
                        input.classList.add('is-dissipating');

                        window.setTimeout(function () {
                            input.value = '';
                            input.classList.remove('is-dissipating');
                            clearing = false;
                            updateClearBtn();
                            if (done) done();
                        }, DISSIPATE_MS);
                    }

                    clearBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        animateClear(function () {
                            input.focus();
                        });
                    });

                    input.addEventListener('input', updateClearBtn);
                    input.addEventListener('change', updateClearBtn);
                    input.addEventListener('search', updateClearBtn);

                    form.setAttribute('data-search-clear-ready', '1');
                    window.nodeUpdateSearchClear = updateClearBtn;
                    window.nodeAnimateSearchClear = animateClear;
                    updateClearBtn();
                })();
                </script>
            </div>

            <!-- RSS -->
            <a href="<?php bloginfo('rss2_url'); ?>" class="m3-icon-button m3-tooltip-target m3-rss-button" id="m3-rss-trigger" aria-label="RSS" data-tooltip="RSSフィード">
                <span class="material-symbols-outlined">rss_feed</span>
            </a>

            <!-- X (Twitter) -->
            <a href="https://x.com/Luminous_Core_" target="_blank" rel="noopener noreferrer" class="m3-icon-button m3-tooltip-target m3-social-button m3-x-button" aria-label="Official X" data-tooltip="公式X">
                <svg class="m3-social-button__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="currentColor">
                    <path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932Zm-1.291 19.491h2.039L6.486 3.24H4.298Z"/>
                </svg>
            </a>

            <!-- Discord -->
            <a href="https://discord.gg/QPr4RPxfAA" target="_blank" rel="noopener noreferrer" class="m3-icon-button m3-tooltip-target m3-social-button m3-discord-button" aria-label="Official Discord" data-tooltip="公式Discord">
                <svg class="m3-social-button__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="currentColor">
                    <path d="M20.317 4.37a19.8 19.8 0 0 0-4.885-1.515.07.07 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.3 18.3 0 0 0-5.487 0 12.6 12.6 0 0 0-.617-1.25.08.08 0 0 0-.079-.037A19.7 19.7 0 0 0 3.677 4.37a.06.06 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.08.08 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.08.08 0 0 0 .084-.028 14.1 14.1 0 0 0 1.226-1.994.08.08 0 0 0-.041-.106 13.1 13.1 0 0 1-1.872-.892.08.08 0 0 1-.008-.128c.126-.094.251-.194.372-.292a.07.07 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.07.07 0 0 1 .078.01c.12.098.246.198.373.292a.08.08 0 0 1-.006.127 12.3 12.3 0 0 1-1.873.892.08.08 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.08.08 0 0 0 .084.028 19.8 19.8 0 0 0 6.002-3.03.08.08 0 0 0 .032-.054c.5-5.177-.838-9.674-3.548-13.66a.06.06 0 0 0-.031-.03ZM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419s.956-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418Zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419s.955-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418Z"/>
                </svg>
            </a>
            
            <!-- Theme -->
            <div id="m3-theme-controls">
                <button id="theme-toggle" class="m3-icon-button m3-tooltip-target" aria-label="テーマ" data-tooltip="テーマ切り替え">
                    <span class="material-symbols-outlined" id="theme-toggle-icon">light_mode</span>
                </button>
            </div>

            <!-- View (Tablet UA のみ) -->
            <?php if ( node_is_tablet_ua() ) : ?>
            <button class="m3-icon-button m3-tooltip-target m3-view-toggle--tablet" id="m3-view-toggle" aria-label="モバイル表示モード" data-tooltip="モバイル表示モード（タップでPC表示）" data-view-mode="mobile">
                <span class="material-symbols-outlined" id="m3-view-toggle-icon" aria-hidden="true">smartphone</span>
            </button>
            <?php endif; ?>

        </div>
    </div>
</header>

<!-- 読了ゲージは header の transform の外に置く。中にあると退避時にノッチの裏へ消える。 -->
<div id="m3-reading-progress" class="m3-header__progress-container">
    <div class="m3-header__progress-bar"></div>
</div>

<!-- 3. Portal Components (Fixed/Overlay Elements) -->

<!-- Advanced Search Modal (Material 3 Expressive) -->
<dialog id="m3-advanced-search-modal" class="m3-modal m3-modal--wide" aria-labelledby="m3-advanced-search-title">
    <div class="m3-modal__content m3-advanced-search-card">
        
        <div class="m3-modal__header">
            <div class="m3-modal__title-group">
                <span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>
                <h2 class="m3-modal__title" id="m3-advanced-search-title">詳細検索</h2>
            </div>
            <button type="button" class="m3-icon-button m3-modal__close" id="m3-advanced-search-close" aria-label="詳細検索を閉じる">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </div>

        <div class="m3-modal__tabs" id="m3-search-tabs">
            <div class="m3-modal__tab-indicator"></div>
            <?php // 狭い画面では 3 タブを等分に並べるため、短いラベルへ差し替える（CSS で切替） ?>
            <button type="button" class="m3-modal__tab is-active" data-page="1" aria-label="絞り込み">
                <span class="material-symbols-outlined">filter_alt</span>
                <span class="m3-modal__tab-label m3-modal__tab-label--full">絞り込み</span>
                <span class="m3-modal__tab-label m3-modal__tab-label--compact">条件</span>
            </button>
            <button type="button" class="m3-modal__tab" data-page="2" aria-label="ボリューム">
                <span class="material-symbols-outlined">schedule</span>
                <span class="m3-modal__tab-label m3-modal__tab-label--full">ボリューム</span>
                <span class="m3-modal__tab-label m3-modal__tab-label--compact">分量</span>
            </button>
            <button type="button" class="m3-modal__tab" data-page="3" aria-label="プラットフォーム">
                <span class="material-symbols-outlined">devices</span>
                <span class="m3-modal__tab-label m3-modal__tab-label--full">プラットフォーム</span>
                <span class="m3-modal__tab-label m3-modal__tab-label--compact">機種</span>
            </button>
        </div>
        
        <div class="m3-modal__body">
            <div class="m3-modal__pages-container m3-modal__pages-container--3">
                <!-- Page 1: Basic Filters -->
                <div class="m3-modal__page is-active" data-page="1">
                    <div class="m3-advanced-search-grid-layout">
                        <div class="m3-advanced-search-column">
                            <div class="m3-search-section">
                                <label class="m3-search-section-label"><span class="material-symbols-outlined">category</span> カテゴリ</label>
                                <div class="m3-select-wrapper">
                                    <select name="m3_cat" class="m3-select">
                                        <option value="">すべてのカテゴリ</option>
                                        <?php
                                        $categories = get_categories(['hide_empty' => true]);
                                        foreach ($categories as $cat) : ?>
                                            <option value="<?php echo $cat->term_id; ?>"><?php echo esc_html($cat->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="material-symbols-outlined m3-select-icon">expand_more</span>
                                </div>
                            </div>
                            <div class="m3-search-section">
                                <label class="m3-search-section-label"><span class="material-symbols-outlined">sell</span> タグ</label>
                                <div class="m3-textfield-wrapper">
                                    <input type="text" name="m3_tag" id="m3-tag-input" class="m3-text-input" placeholder="タグ名を入力..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="m3-tag-suggestions">
                                    <div id="m3-tag-suggestions" class="m3-suggestions-list" role="listbox" aria-label="タグ候補"></div>
                                </div>
                            </div>
                            <!-- Mobile Exclusive: Sort Order -->
                            <div class="m3-search-section m3-desktop-hidden">
                                <label class="m3-search-section-label"><span class="material-symbols-outlined">sort</span> 並び順</label>
                                <div class="m3-radio-group">
                                    <label class="m3-radio-item"><input type="radio" name="m3_sort" value="newest" checked><span class="m3-radio-label">新着</span></label>
                                    <label class="m3-radio-item"><input type="radio" name="m3_sort" value="oldest"><span class="m3-radio-label">古い順</span></label>
                                    <?php // 「人気(views)」「コメント」ソートはバックエンド（inc/search.php）未実装のためUIから退避（STRUCTURAL-REVIEW-1.2 F-11。実装可否はv1.3のF-16で判断） ?>
                                </div>
                            </div>
                        </div>
                        <div class="m3-advanced-search-column m3-mobile-hidden">
                            <div class="m3-search-section">
                                <label class="m3-search-section-label"><span class="material-symbols-outlined">calendar_month</span> 期間</label>
                                <div class="m3-date-picker-grid">
                                    <input type="date" name="m3_start_date" class="m3-date-input">
                                    <span class="m3-date-range-sep">~</span>
                                    <input type="date" name="m3_end_date" class="m3-date-input">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Page 2: Volume & Media -->
                <div class="m3-modal__page" data-page="2">
                    <div class="m3-advanced-search-grid-layout">
                        <div class="m3-advanced-search-column">
                                <div class="m3-search-section">
                                    <!-- Range Slider -->
                                    <label class="m3-search-section-label"><span class="material-symbols-outlined">schedule</span> 読了目安・ボリューム</label>
                                    <?php // 読了目安（m3_reading_time）はバックエンド未実装のためUIから退避（F-11）。文字数範囲（m3_min/m3_max）は実装済みなので残す ?>

                                    <div class="m3-slider-container">
                                        <div class="m3-search-section-label m3-search-section-label--sub"><span class="material-symbols-outlined">straighten</span> 文字数範囲</div>
                                        <div class="m3-range-slider" id="m3-word-count-slider">
                                            <div class="m3-range-slider__track"></div>
                                            <div class="m3-range-slider__range" id="m3-slider-range"></div>
                                            <div class="m3-range-slider__handle m3-range-slider__handle--min" id="m3-slider-handle-min">
                                                <div class="m3-range-slider__value">0</div>
                                            </div>
                                            <div class="m3-range-slider__handle m3-range-slider__handle--max" id="m3-slider-handle-max">
                                                <div class="m3-range-slider__value">10000</div>
                                            </div>
                                        </div>
                                        <div class="m3-char-input-grid">
                                            <div class="m3-char-input-field">
                                                <span class="m3-char-input-label">最小</span>
                                                <input type="number" name="m3_min" id="m3-min-chars" value="0" class="m3-text-input m3-char-input">
                                            </div>
                                            <div class="m3-char-input-field">
                                                <span class="m3-char-input-label">最大</span>
                                                <input type="number" name="m3_max" id="m3-max-chars" value="10000" class="m3-text-input m3-char-input">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                        </div>
                        <div class="m3-advanced-search-column">
                            <div class="m3-search-section">
                                <label class="m3-search-section-label"><span class="material-symbols-outlined">media_output</span> メディア</label>
                                <div class="m3-platform-list">
                                    <label class="m3-platform-chip m3-platform-chip--image"><input type="checkbox" name="m3_media_type[]" value="image"><span>画像</span></label>
                                    <label class="m3-platform-chip m3-platform-chip--video"><input type="checkbox" name="m3_media_type[]" value="video"><span>動画</span></label>
                                    <label class="m3-platform-chip m3-platform-chip--youtube"><input type="checkbox" name="m3_media_type[]" value="youtube"><span>YouTube</span></label>
                                    <?php // 「AI生成」チップは m3_media_type[]=ai がバックエンド未対応（実装済みなのは別パラメータ m3_ai）のためUIから退避（F-11） ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Page 3: Platforms -->
                <div class="m3-modal__page" data-page="3">
                    <div class="m3-platform-layout" id="m3-platform-options" data-state="idle"></div>
                </div>
            </div>

            <section id="m3-search-rest-results" class="m3-search-rest-results" aria-labelledby="m3-search-rest-results-title" hidden>
                <div class="m3-search-rest-results__header">
                    <h3 id="m3-search-rest-results-title" class="m3-search-rest-results__title">検索結果</h3>
                    <p id="m3-search-rest-results-status" class="m3-search-rest-results__status" role="status" aria-live="polite"></p>
                </div>
                <ol id="m3-search-rest-results-list" class="m3-search-rest-results__list"></ol>
                <a id="m3-search-rest-results-all" class="m3-button m3-button--text" href="<?php echo esc_url( home_url( '/' ) ); ?>">すべての検索結果を見る</a>
            </section>
        </div>

        <div class="m3-modal__footer">
            <div class="m3-search-hits-display">
                <span class="m3-search-hits-text">
                    <strong id="m3-search-hit-count"><?php echo number_format_i18n(node_get_total_published_posts()); ?></strong> 件の記事
                </span>
            </div>
            <div class="m3-modal__footer-options">
                <label class="m3-checkbox-label">
                    <input type="checkbox" id="m3-save-search-settings">
                    <span class="m3-checkbox-custom"></span>
                    <span class="m3-checkbox-text">検索条件を保存する</span>
                </label>
            </div>
            <div class="m3-modal__footer-actions">
                <button type="button" class="m3-button m3-button--text" id="m3-advanced-search-reset">リセット</button>
                <button type="button" class="m3-button m3-button--filled" id="m3-advanced-search-apply">検索を実行</button>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div id="m3-search-loading" class="m3-loading-overlay">
            <div class="m3-loading-spinner"></div>
        </div>
    </div>
</dialog>
