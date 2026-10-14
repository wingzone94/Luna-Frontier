import { isSinglePostView } from './page-state';

/**
 * ヒーロー統合目次（モバイル中心）。
 *
 * - ヒーロー内のネイティブ <select>（hero.php がサーバーサイド出力）で見出しへジャンプ。
 *   現在ページの見出しはスムーズスクロール、別ページの見出しはそのページURLへ遷移。
 * - モバイルでは、ヒーロー（タイトルカード）を通過して "ある程度スクロール" したら、
 *   既存の目次FAB(#m3-toc-trigger)＋追従目次パネル(#m3-sticky-toc)を自動表示する
 *   （`body.is-hero-passed` を付与し CSS 側で FAB を出す）。FABのクリック挙動・
 *   パネル開閉・現在見出しハイライトは expressive-toc.js の既存実装をそのまま使う。
 * - PC は従来どおり（FABは常時表示、ヒーロー内プルダウンは非表示）。ここでは何もしない。
 */
export function initHeroTOC() {
    if (!isSinglePostView()) return;

    const heroSelect = document.querySelector('[data-hero-toc-select]');
    const heroCard = document.querySelector('.m3-article__header-card, .lf-article-header');
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const getHeaderHeight = () => {
        const header = document.querySelector('.m3-header');
        return header ? header.getBoundingClientRect().height : 0;
    };

    const jumpToHash = (value) => {
        const id = value.replace(/^#/, '');
        const target = document.getElementById(id);
        if (!target) return false;
        const top = target.getBoundingClientRect().top + window.scrollY - Math.max(88, getHeaderHeight() + 16);
        window.scrollTo({ top, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
        history.replaceState(null, '', `#${id}`);
        return true;
    };

    // ---- ヒーロー内プルダウンのジャンプ挙動 ----
    if (heroSelect) {
        heroSelect.addEventListener('change', () => {
            const value = heroSelect.value;
            if (!value) return;

            if (value.charAt(0) === '#') {
                jumpToHash(value);
                heroSelect.selectedIndex = 0; // プレースホルダーへ戻して再利用可能に
            } else {
                window.location.href = value; // 別ページの見出し
            }
        });
    }

    // ---- 著者情報クリック → 記事下部のライター情報カードへスムーズスクロール ----
    const authorJump = document.querySelector('[data-hero-author-jump]');
    if (authorJump) {
        authorJump.addEventListener('click', (event) => {
            if (jumpToHash(authorJump.getAttribute('href') || '#m3-writer-card')) {
                event.preventDefault();
            }
            // ライターカードが無いページでは通常のアンカー挙動にフォールバック
        });
    }

    // ---- モバイル: 読了バッジ（タイマーアイコンのみ）タップで時間・文字数を伸縮 ----
    const readingBadge = document.getElementById('m3-hero-reading-badge');
    if (readingBadge && readingBadge.closest('.m3-hero-col--aside')) {
        const mobileQuery = window.matchMedia('(max-width: 1000px)');
        const AUTO_OPEN_DELAY_MS = 1600;
        const DATE_READY_TIMEOUT_MS = 1200;
        let autoOpenTimer = 0;
        let userInteractedWithBadge = false;
        // 一度表示して閉じたら以後は再展開させない（ワンショット表示）
        let badgeLocked = false;

        const waitForHeroDateReady = (callback) => {
            const startedAt = Date.now();

            const check = () => {
                const dateEl = document.querySelector('.m3-article__date time');
                const isReady = dateEl
                    && dateEl.textContent.trim()
                    && dateEl.getBoundingClientRect().width > 0;

                if (isReady || Date.now() - startedAt >= DATE_READY_TIMEOUT_MS) {
                    window.requestAnimationFrame(() => callback());
                    return;
                }

                window.requestAnimationFrame(check);
            };

            check();
        };

        const clearAutoOpenTimer = () => {
            if (!autoOpenTimer) return;
            window.clearTimeout(autoOpenTimer);
            autoOpenTimer = 0;
        };

        const scheduleAutoOpen = () => {
            clearAutoOpenTimer();
            if (!mobileQuery.matches || userInteractedWithBadge || readingBadge.classList.contains('is-badge-open')) {
                return;
            }

            autoOpenTimer = window.setTimeout(() => {
                autoOpenTimer = 0;
                if (!mobileQuery.matches || userInteractedWithBadge) return;
                readingBadge.classList.add('is-badge-open');
                readingBadge.setAttribute('aria-expanded', 'true');
            }, AUTO_OPEN_DELAY_MS);
        };

        const lockBadge = () => {
            badgeLocked = true;
            readingBadge.classList.add('is-badge-locked');
            readingBadge.removeAttribute('role');
            readingBadge.removeAttribute('tabindex');
            readingBadge.removeAttribute('aria-expanded');
            readingBadge.removeAttribute('aria-controls');
            readingBadge.removeAttribute('aria-label');
        };

        const syncBadgeInteractive = () => {
            if (mobileQuery.matches) {
                if (badgeLocked) return; // 表示済みロック中は静的アイコンのまま
                readingBadge.setAttribute('role', 'button');
                readingBadge.setAttribute('tabindex', '0');
                readingBadge.setAttribute('aria-expanded', String(readingBadge.classList.contains('is-badge-open')));
                readingBadge.setAttribute('aria-controls', 'm3-reading-badge-desc');
                readingBadge.setAttribute('aria-label', '読了時間・文字数を表示');
                scheduleAutoOpen();
            } else {
                // PC は常時全文表示（クリック不可）なのでインタラクティブ属性を外す
                clearAutoOpenTimer();
                userInteractedWithBadge = false;
                badgeLocked = false;
                readingBadge.classList.remove('is-badge-open', 'is-badge-locked');
                readingBadge.removeAttribute('role');
                readingBadge.removeAttribute('tabindex');
                readingBadge.removeAttribute('aria-expanded');
                readingBadge.removeAttribute('aria-controls');
            }
        };

        const toggleBadge = () => {
            if (!mobileQuery.matches || badgeLocked) return;
            userInteractedWithBadge = true;
            clearAutoOpenTimer();
            if (readingBadge.classList.contains('is-badge-open')) {
                // 一度表示した内容を閉じたら、以後は再展開不可（静的アイコン化）
                readingBadge.classList.remove('is-badge-open');
                lockBadge();
            } else {
                readingBadge.classList.add('is-badge-open');
                readingBadge.setAttribute('aria-expanded', 'true');
            }
        };

        readingBadge.addEventListener('click', toggleBadge);
        readingBadge.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleBadge();
            }
        });

        // home-layout.js の initHeroInfoBubble が同要素の role/tabindex を除去するため、
        // 全イニシャライザ実行後に付与し直す（同一 DOMContentLoaded 内の後段で実行される）
        waitForHeroDateReady(() => window.setTimeout(syncBadgeInteractive, 0));
        if (typeof mobileQuery.addEventListener === 'function') {
            mobileQuery.addEventListener('change', syncBadgeInteractive);
        }
    }

    // ---- タイトル3行クランプ（PC）: あふれた時だけ「…」全文展開ボタンを出す ----
    const heroTitle = document.getElementById('m3-hero-title');
    const titleExpandBtn = document.querySelector('[data-hero-title-expand]');
    if (heroTitle && titleExpandBtn) {
        const pcQuery = window.matchMedia('(min-width: 1001px)');

        const syncTitleClamp = () => {
            // 展開後はボタンが DOM から消滅している（再読込まで復帰しない）
            if (!titleExpandBtn.isConnected) return;
            if (!pcQuery.matches || heroTitle.classList.contains('is-title-expanded')) {
                titleExpandBtn.hidden = true;
                return;
            }
            // クランプ（4行）で実際にあふれている時だけ表示（+2px は丸め誤差の余裕）
            titleExpandBtn.hidden = heroTitle.scrollHeight <= heroTitle.clientHeight + 2;
        };

        // 一度展開したら全文表示のまま固定し、ボタンは消滅させる
        titleExpandBtn.addEventListener('click', () => {
            heroTitle.classList.add('is-title-expanded');
            titleExpandBtn.remove();
        }, { once: true });

        let clampTimer = 0;
        window.addEventListener('resize', () => {
            window.clearTimeout(clampTimer);
            clampTimer = window.setTimeout(syncTitleClamp, 120);
        }, { passive: true });

        // フォント読み込みで行数が変わるケースに備えて再判定
        syncTitleClamp();
        window.setTimeout(syncTitleClamp, 400);
        if (document.fonts?.ready) {
            document.fonts.ready.then(syncTitleClamp).catch(() => {});
        }
    }

    // ---- ヒーロー通過検知 → 目次FABの自動表示（モバイルは CSS 側で出し分け） ----
    // 目次項目が無い記事では FAB 自体が toc-ready にならないため、ここでの付与は無害。
    if (heroCard) {
        const setPassed = (passed) => {
            document.body.classList.toggle('is-hero-passed', passed);
        };

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    const scrolledPast = !entry.isIntersecting && entry.boundingClientRect.top < 0;
                    setPassed(scrolledPast);
                });
            }, { threshold: 0, rootMargin: '-72px 0px 0px 0px' });
            observer.observe(heroCard);
        } else {
            // フォールバック: スクロール量で判定
            const onScroll = () => {
                const rect = heroCard.getBoundingClientRect();
                setPassed(rect.bottom < 72);
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();
        }
    }
}
