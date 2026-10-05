import './gsap';
import './scripts/card-animation';
import './scripts/share-actions';
import './scripts/blogcard-actions';
import { initSearchBar } from './scripts/search-bar';
import { initArticleNavigation } from './scripts/article-navigation';
import { initHeroInfoBubble, initSectionArchiveLinks } from './scripts/home-layout';
import { initHeadlineCarousel } from './scripts/headline-carousel';
import { initExpressiveFloatingTOC } from './scripts/expressive-toc';
import { initHeroTOC } from './scripts/hero-toc';
import { initFloatingActions } from './scripts/floating-actions';
import { initReadingProgressSingleOnly } from './scripts/reading-progress';
import { initScrollAnimations } from './scripts/scroll-animations';
import { initSmartHeader } from './scripts/smart-header';
import { initKeyboardShortcuts, initOverdriveScroll, initRippleEffect, initTooltips } from './scripts/ui-effects';
import { initViewSwitcher } from './scripts/view-switcher';

const optionalInitializers = [
    {
        name: 'initColorExtraction',
        shouldRun: () => document.querySelector('.m3-article__category-group a[data-thumb], .m3-reading-badge-label[data-thumb]'),
        load: () => import('./scripts/color-extraction')
    },
    {
        name: 'initCommentForm',
        shouldRun: () => document.getElementById('commentform'),
        load: () => import('./scripts/comment-form')
    },
    {
        name: 'initHeaderClock',
        shouldRun: () => document.getElementById('m3-header-clock'),
        load: () => import('./scripts/header-clock')
    },
    {
        name: 'initTableSorter',
        shouldRun: () => document.querySelector('.wp-block-table.is-sortable table, .wp-block-table.is-style-sortable table, .m3-table--sortable table'),
        load: () => import('./scripts/table-sorter')
    },
    {
        name: 'initPagination',
        shouldRun: () => (
            document.getElementById('m3-page-selector') ||
            document.querySelector('.m3-pagination__number') ||
            document.getElementById('m3-article-toc-anchor') ||
            document.getElementById('m3-article-top-anchor')
        ),
        load: () => import('./scripts/pagination')
    },
    {
        name: 'initFootnotesPopover',
        shouldRun: () => document.querySelector('.m3-article__body sup[data-fn]'),
        load: () => import('./scripts/footnotes-popover')
    },
    {
        name: 'initReadingBadgeAnimation',
        shouldRun: () => (
            (document.body.classList.contains('single') || document.body.classList.contains('single-post')) &&
            document.getElementById('m3-hero-reading-badge')
        ),
        load: () => import('./scripts/reading-badge-animation')
    },
    {
        name: 'initSeriesToc',
        shouldRun: () => document.querySelector('.m3-series-toc__toggle'),
        load: () => import('./scripts/series-toc')
    }
];

const runOptionalInitializers = () => {
    optionalInitializers.forEach(async optional => {
        if (!optional.shouldRun()) return;

        try {
            const module = await optional.load();
            module[optional.name]();
        } catch (e) {
            console.error(`Initializer failed: ${optional.name}`, e);
        }
    });
};

const scheduleOptionalInitializers = () => {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(runOptionalInitializers, { timeout: 1200 });
        return;
    }

    window.setTimeout(runOptionalInitializers, 250);
};

function initColorModeLoader() {
    if (!document.querySelector('#theme-toggle, #m3-theme-toggle-handy, #m3-color-scheme-toggle, .m3-color-scheme-toggle, [data-color-toggle]')) return;

    import('./scripts/color-mode')
        .then(module => {
            module.initColorMode();
        })
        .catch(e => {
            console.error('Initializer failed: initColorMode', e);
        });
}

function initNodeLibraryQr() {
    document.querySelectorAll('[data-node-library-qr-toggle]').forEach(toggle => {
        if (toggle.dataset.nodeLibraryQrReady === 'true') return;

        const dialogId = toggle.getAttribute('aria-controls');
        const dialog = dialogId ? document.getElementById(dialogId) : null;
        const canvas = dialog?.querySelector('[data-node-library-qr-canvas]');
        const status = dialog?.querySelector('[data-node-library-qr-status]');
        const close = dialog?.querySelector('[data-node-library-qr-close]');
        const copy = dialog?.querySelector('[data-node-library-qr-copy]');
        const copyStatus = dialog?.querySelector('[data-node-library-qr-copy-status]');
        const url = dialog?.dataset.qrUrl || '';
        if (!dialog || !canvas || !status || !close || !copy || !copyStatus || !url) return;

        toggle.dataset.nodeLibraryQrReady = 'true';

        const renderQr = async () => {
            if (dialog.dataset.qrReady === 'true') return;

            status.hidden = false;
            status.textContent = 'QRコードを生成中…';
            canvas.hidden = true;

            const { toCanvas } = await import('qrcode');
            await toCanvas(canvas, url, {
                width: 192,
                margin: 2,
                errorCorrectionLevel: 'M',
                color: {
                    dark: '#111111',
                    light: '#ffffff'
                }
            });

            status.hidden = true;
            canvas.hidden = false;
            dialog.dataset.qrReady = 'true';
        };

        const copyUrl = async () => {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(url);
                } else {
                    const textarea = document.createElement('textarea');
                    textarea.value = url;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    textarea.remove();
                }
                copyStatus.textContent = 'コピーしました';
            } catch (error) {
                copyStatus.textContent = 'コピーできませんでした';
                console.error('Luna Library URL copy failed', error);
            }
        };

        toggle.addEventListener('click', async () => {
            toggle.disabled = true;
            toggle.setAttribute('aria-busy', 'true');

            try {
                await renderQr();
                dialog.showModal();
                toggle.setAttribute('aria-expanded', 'true');
            } catch (error) {
                if (!dialog.open) dialog.showModal();
                status.hidden = false;
                status.textContent = 'QRコードを生成できませんでした。';
                console.error('Luna Library QR generation failed', error);
            } finally {
                toggle.disabled = false;
                toggle.removeAttribute('aria-busy');
            }
        });

        close.addEventListener('click', () => dialog.close());
        copy.addEventListener('click', copyUrl);
        dialog.addEventListener('click', event => {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', () => {
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus({ preventScroll: true });
        });
    });
}

function initNodeLibraryTabs() {
    document.querySelectorAll('[data-node-library-tabs]').forEach(section => {
        const tabs = Array.from(section.querySelectorAll('[role="tab"][data-node-library-tab]'));
        const panels = Array.from(section.querySelectorAll('[role="tabpanel"][data-node-library-panel]'));
        const nextButton = section.querySelector('[data-node-library-tab-next]');
        const backButton = section.querySelector('[data-node-library-tab-back]');
        if (tabs.length < 2 || panels.length < 2) return;
        let previousTarget = tabs.find(tab => tab.getAttribute('aria-selected') === 'true')?.dataset.nodeLibraryTab || tabs[0].dataset.nodeLibraryTab;

        const activate = (target, shouldFocus = false) => {
            const isAllView = target === 'all';
            if (!isAllView) previousTarget = target;
            section.classList.toggle('is-all-view', isAllView);

            tabs.forEach(tab => {
                const active = tab.dataset.nodeLibraryTab === target;
                tab.setAttribute('aria-selected', String(active));
                tab.tabIndex = active ? 0 : -1;
                if (active && shouldFocus) tab.focus();
            });
            panels.forEach(panel => {
                panel.hidden = panel.dataset.nodeLibraryPanel !== target;
            });

            if (nextButton) {
                nextButton.hidden = isAllView;
            }
            if (backButton) backButton.hidden = !isAllView;
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => activate(tab.dataset.nodeLibraryTab));
            tab.addEventListener('keydown', event => {
                const direction = ['ArrowRight', 'ArrowDown'].includes(event.key)
                    ? 1
                    : ['ArrowLeft', 'ArrowUp'].includes(event.key) ? -1 : 0;
                if (!direction) return;

                event.preventDefault();
                const next = (index + direction + tabs.length) % tabs.length;
                activate(tabs[next].dataset.nodeLibraryTab, true);
            });
        });

        nextButton?.addEventListener('click', () => {
            const nextIndex = Math.min(3, tabs.length - 1);
            tabs[nextIndex].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'end' });
            tabs[nextIndex].focus({ preventScroll: true });
        });

        backButton?.addEventListener('click', () => activate(previousTarget, true));

        activate(previousTarget);
    });
}

function initNodeLibraryAccordionMotion() {
    const supportsAccordionMotion = CSS.supports('interpolate-size: allow-keywords')
        && CSS.supports('selector(details::details-content)');

    if (!supportsAccordionMotion || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    document.querySelectorAll('.node-library-single__more').forEach(details => {
        if (details.dataset.nodeLibraryAccordionReady === 'true') return;

        const summary = details.querySelector(':scope > summary');
        if (!summary) return;

        details.dataset.nodeLibraryAccordionReady = 'true';

        summary.addEventListener('click', event => {
            if (!details.open || details.dataset.nodeLibraryClosing === 'true') return;

            event.preventDefault();
            details.dataset.nodeLibraryClosing = 'true';

            const finishClosing = () => {
                window.clearTimeout(fallbackTimer);
                details.removeEventListener('transitionend', handleTransitionEnd);
                details.open = false;
                delete details.dataset.nodeLibraryClosing;
            };

            const handleTransitionEnd = transitionEvent => {
                if (transitionEvent.propertyName === 'block-size') finishClosing();
            };

            const fallbackTimer = window.setTimeout(finishClosing, 420);
            details.addEventListener('transitionend', handleTransitionEnd);
        });
    });
}

function initNodeLibraryFilterNavigation() {
    const archive = document.querySelector('.node-library-archive');
    if (!archive || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    archive.querySelectorAll('.node-library-filter__item').forEach(link => {
        link.addEventListener('click', event => {
            if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.href === window.location.href) return;

            event.preventDefault();
            archive.classList.add('is-filter-leaving');
            window.setTimeout(() => window.location.assign(link.href), 180);
        });
    });
}

function initNodeLibraryNintendoWarnings() {
    const warningTimers = new WeakMap();

    const hideWarning = link => {
        const warning = link.closest('.node-library-platform-link, .node-library-nintendo-link')?.querySelector('.node-library-platform-warning, .node-library-nintendo-warning');
        const timer = warningTimers.get(link);
        if (timer) window.clearTimeout(timer);

        link.dataset.nodeLibraryPlatformArmed = 'false';
        link.dataset.nodeLibraryNintendoArmed = 'false';
        link.setAttribute('aria-expanded', 'false');
        warning?.setAttribute('hidden', '');
        warningTimers.delete(link);
    };

    const showWarning = link => {
        const warning = link.closest('.node-library-platform-link, .node-library-nintendo-link')?.querySelector('.node-library-platform-warning, .node-library-nintendo-warning');
        if (!warning) return false;

        document.querySelectorAll('[data-node-library-platform-warning][data-node-library-platform-armed="true"], [data-node-library-nintendo-warning][data-node-library-nintendo-armed="true"]').forEach(activeLink => {
            if (activeLink !== link) hideWarning(activeLink);
        });

        warning.hidden = false;
        link.dataset.nodeLibraryPlatformArmed = 'true';
        link.dataset.nodeLibraryNintendoArmed = 'true';
        link.setAttribute('aria-expanded', 'true');

        const timer = window.setTimeout(() => hideWarning(link), 3500);
        warningTimers.set(link, timer);
        return true;
    };

    document.querySelectorAll('[data-node-library-platform-warning], [data-node-library-nintendo-warning]').forEach(link => {
        if (link.dataset.nodeLibraryPlatformReady === 'true' || link.dataset.nodeLibraryNintendoReady === 'true') return;

        link.dataset.nodeLibraryPlatformReady = 'true';
        link.dataset.nodeLibraryNintendoReady = 'true';
        link.dataset.nodeLibraryPlatformArmed = 'false';
        link.dataset.nodeLibraryNintendoArmed = 'false';
        link.setAttribute('aria-haspopup', 'true');
        link.setAttribute('aria-expanded', 'false');

        link.addEventListener('click', event => {
            if (link.dataset.nodeLibraryPlatformArmed === 'true' || link.dataset.nodeLibraryNintendoArmed === 'true') {
                hideWarning(link);
                return;
            }

            if (showWarning(link)) {
                event.preventDefault();
            }
        });

        link.addEventListener('blur', () => {
            window.setTimeout(() => {
                if (!link.matches(':focus')) hideWarning(link);
            }, 80);
        });
    });
}

function initNodeLibrarySteamEmbedToggles() {
    document.querySelectorAll('[data-node-library-steam-toggle]').forEach(toggle => {
        if (toggle.dataset.nodeLibrarySteamReady === 'true') return;

        const panelId = toggle.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;
        const control = toggle.closest('[data-node-library-steam-control]');
        const card = toggle.closest('.node-library-card');
        const steamButtons = card ? Array.from(card.querySelectorAll('.m3-platform-button--steam')) : [];
        const stores = card?.querySelector('.m3-game-card__stores');
        if (!panel) return;

        toggle.dataset.nodeLibrarySteamReady = 'true';

        const sync = () => {
            const isOpen = toggle.checked;
            panel.hidden = !isOpen;
            steamButtons.forEach(button => {
                button.hidden = isOpen;
            });
            if (stores) {
                const activePanel = stores.querySelector('.m3-game-card__store-panel:not([hidden])') || stores.querySelector('.m3-game-card__store-panel');
                const visibleButtons = activePanel
                    ? Array.from(activePanel.querySelectorAll('.m3-platform-button')).filter(button => !button.hidden)
                    : [];
                stores.hidden = isOpen && visibleButtons.length === 0;
            }
            toggle.setAttribute('aria-expanded', String(isOpen));
            control?.classList.toggle('is-steam-visible', isOpen);
        };

        toggle.addEventListener('change', sync);
        sync();
    });
}

function initCategoryOverflowToggle() {
    document.querySelectorAll('.m3-label--category-more--toggle').forEach(toggle => {
        toggle.addEventListener('click', () => {
            const group = toggle.closest('.m3-article__category-group');
            if (!group) return;

            group.classList.add('is-expanded');
            toggle.setAttribute('aria-expanded', 'true');
        });
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    const initializers = [
        initSearchBar,
        initViewSwitcher,
        initColorModeLoader,
        initNodeLibraryQr,
        initNodeLibraryTabs,
        initNodeLibraryAccordionMotion,
        initNodeLibraryFilterNavigation,
        initNodeLibraryNintendoWarnings,
        initNodeLibrarySteamEmbedToggles,
        initExpressiveFloatingTOC,
        initHeroTOC,
        initFloatingActions,
        initSmartHeader,
        initOverdriveScroll,
        initKeyboardShortcuts,
        initTooltips,
        initRippleEffect,
        initReadingProgressSingleOnly,
        initSectionArchiveLinks,
        initHeadlineCarousel,
        initArticleNavigation,
        initHeroInfoBubble,
        initScrollAnimations,
        initCategoryOverflowToggle,
    ];

    initializers.forEach(init => {
        try {
            init();
        } catch (e) {
            console.error(`Initializer failed: ${init.name}`, e);
        }
    });

    scheduleOptionalInitializers();
});
