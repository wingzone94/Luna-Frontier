const initSeriesChartToggle = () => {
    document.querySelectorAll('.m3-series-toc__toggle').forEach((toggle) => {
        const panel = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!panel) return;

        panel.style.maxHeight = '0px';
        panel.inert = true;

        toggle.addEventListener('click', () => {
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';
            const nextOpen = !isOpen;

            toggle.setAttribute('aria-expanded', String(nextOpen));
            panel.classList.toggle('is-open', nextOpen);
            panel.inert = !nextOpen;
            panel.style.maxHeight = nextOpen ? `${panel.scrollHeight}px` : '0px';
        });
    });
};

const initSeriesInfoToggle = () => {
    document.querySelectorAll('[data-series-info-toggle]').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!isOpen));
        });
    });
};

/**
 * シリーズ名ラベルの自動フィット。
 * 名前がカード幅に収まらない場合、字詰め（letter-spacing）→縮小（font-size）の順に
 * 段階的に詰めて1行へ収める。それでも溢れる場合はCSSのellipsisが最終保険。
 */
const initSeriesNameFit = () => {
    // 初段''はテーマ既定の字間（継承値）のまま測る。詰めるのは溢れた場合のみ。
    const KERNING_STEPS = ['', '-0.02em', '-0.035em', '-0.05em'];
    const MIN_FONT_SIZE = 0.85; // rem（展開時説明文と同等の下限）
    const FONT_STEP = 0.05;

    const fit = (el) => {
        el.style.letterSpacing = '';
        el.style.fontSize = '';

        if (el.clientWidth === 0) return; // 非表示（アコーディオン展開中など）は測定不能

        const overflows = () => el.scrollWidth > el.clientWidth;

        for (const spacing of KERNING_STEPS) {
            el.style.letterSpacing = spacing;
            if (!overflows()) return;
        }

        const baseSize = parseFloat(window.getComputedStyle(el).fontSize)
            / parseFloat(window.getComputedStyle(document.documentElement).fontSize);

        // 0.05刻みの浮動小数点誤差で最小値がスキップされないよう、ステップ数で回す
        const steps = Math.round((baseSize - MIN_FONT_SIZE) / FONT_STEP);
        for (let i = 1; i <= steps; i++) {
            const size = Math.max(baseSize - FONT_STEP * i, MIN_FONT_SIZE);
            el.style.fontSize = `${size}rem`;
            if (!overflows()) return;
        }
        // ここまで縮めても溢れる場合はellipsisに委ねる
    };

    const targets = document.querySelectorAll('[data-series-name-fit]');

    if (targets.length === 0) return;

    // Webフォント適用で字幅が変わるため、読み込み完了後に再フィットする
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(() => targets.forEach(fit));
    }

    targets.forEach((el) => {
        fit(el);

        if (typeof ResizeObserver === 'undefined') return;

        let lastWidth = el.clientWidth;
        const observer = new ResizeObserver(() => {
            // fit自身のスタイル変更による再通知でループしないよう、幅変化時のみ再実行
            if (el.clientWidth !== lastWidth) {
                lastWidth = el.clientWidth;
                fit(el);
            }
        });
        observer.observe(el.parentElement || el);
    });
};

export function initSeriesToc() {
    initSeriesChartToggle();
    initSeriesInfoToggle();
    initSeriesNameFit();
}
