import { isRealDesktopHoverDevice } from './device-mode';

const FOOTNOTE_SELECTOR = '.m3-article__body sup[data-fn]';
const FOOTNOTE_LIST_SELECTOR = '.m3-article__body .wp-block-footnotes';
const POPOVER_CLASS = 'node-footnote-popover';
const FOOTNOTE_SECTION_SELECTOR = '[data-node-footnotes]';
const FOOTNOTE_TABS_SELECTOR = '[data-node-footnote-tabs]';

const stripReturnLinks = (root, id) => {
    const escapedId = window.CSS?.escape ? window.CSS.escape(id) : id.replace(/["\\]/g, '\\$&');

    root.querySelectorAll(`a[href="#${escapedId}-link"], a[aria-label^="脚注参照"]`).forEach(link => {
        link.remove();
    });
};

const getFootnoteContent = (id) => {
    const item = document.getElementById(id);

    if (!item || !item.closest(FOOTNOTE_LIST_SELECTOR)) {
        return null;
    }

    const content = item.cloneNode(true);
    stripReturnLinks(content, id);

    return content.innerHTML.trim();
};

const positionPopover = (popover, reference) => {
    const gap = 12;
    const viewportPadding = 16;
    const refRect = reference.getBoundingClientRect();

    popover.style.left = '0px';
    popover.style.top = '0px';

    const popoverRect = popover.getBoundingClientRect();
    const preferredLeft = refRect.left + (refRect.width / 2) - (popoverRect.width / 2);
    const left = Math.min(
        window.innerWidth - popoverRect.width - viewportPadding,
        Math.max(viewportPadding, preferredLeft)
    );

    const canPlaceAbove = refRect.top >= popoverRect.height + gap + viewportPadding;
    const top = canPlaceAbove
        ? refRect.top - popoverRect.height - gap
        : refRect.bottom + gap;

    popover.style.left = `${Math.round(left)}px`;
    popover.style.top = `${Math.round(Math.max(viewportPadding, top))}px`;
    popover.dataset.placement = canPlaceAbove ? 'top' : 'bottom';
};

const initFootnoteInfoControls = () => {
    document.querySelectorAll(FOOTNOTE_SECTION_SELECTOR).forEach(section => {
        if (section.dataset.footnoteInfoReady === 'true') return;

        const toggle = section.querySelector('[data-footnote-info-toggle]');
        const panelId = toggle?.getAttribute('aria-controls') || '';
        const panel = panelId ? document.getElementById(panelId) : null;
        if (!toggle || !panel) return;

        section.dataset.footnoteInfoReady = 'true';

        const setOpen = (open) => {
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', String(open));
            toggle.setAttribute('aria-label', open ? '脚注の説明を非表示' : '脚注の説明を表示');
        };

        toggle.addEventListener('click', () => {
            setOpen(panel.hidden);
        });
    });
};

const initFootnoteTabs = () => {
    document.querySelectorAll(FOOTNOTE_TABS_SELECTOR).forEach(section => {
        if (section.dataset.footnoteTabsReady === 'true') return;

        const tabs = Array.from(section.querySelectorAll('[role="tab"][data-footnote-tab]'));
        const panels = Array.from(section.querySelectorAll('[role="tabpanel"][data-footnote-panel]'));
        if (tabs.length < 2 || panels.length < 2) return;

        section.dataset.footnoteTabsReady = 'true';
        section.classList.add('is-footnote-tabs-ready');

        const updateMeta = (tab) => {
            const count = Number(tab?.dataset.footnoteCount || 0);
            const meta = section.querySelector('.node-footnotes__meta');
            if (!meta || !count) return;
            const totalCount = Number(meta.dataset.footnoteTotalCount || count);

            meta.dataset.footnoteCount = String(count);

            const desktop = meta.querySelector('.node-footnotes__meta-desktop');
            const mobile = meta.querySelector('.node-footnotes__meta-mobile');
            if (desktop) desktop.textContent = `このページの脚注：${count}件 / 記事全体：${totalCount}件`;
            if (mobile) mobile.textContent = `ページ ${count}件 / 全体 ${totalCount}件`;
        };

        const activate = (targetPage, shouldFocus = false, scrollTabIntoView = true) => {
            let activeTab = null;

            tabs.forEach(tab => {
                const isActive = tab.dataset.footnoteTab === targetPage;
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                tab.tabIndex = isActive ? 0 : -1;

                if (isActive) {
                    activeTab = tab;

                    if (scrollTabIntoView) {
                        tab.scrollIntoView({ block: 'nearest', inline: 'center' });
                    } else {
                        // 初期表示ではページ全体を縦スクロールさせず、タブ帯の中だけ横位置を合わせる
                        const strip = tab.closest('.node-footnotes__tabs');

                        if (strip) {
                            const stripRect = strip.getBoundingClientRect();
                            const tabRect = tab.getBoundingClientRect();
                            strip.scrollLeft += tabRect.left - stripRect.left - (strip.clientWidth - tabRect.width) / 2;
                        }
                    }

                    if (shouldFocus) {
                        tab.focus({ preventScroll: true });
                    }
                }
            });

            panels.forEach(panel => {
                panel.hidden = panel.dataset.footnotePanel !== targetPage;
            });

            updateMeta(activeTab);
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => {
                activate(tab.dataset.footnoteTab);
            });

            tab.addEventListener('keydown', event => {
                const keyMap = {
                    ArrowRight: 1,
                    ArrowDown: 1,
                    ArrowLeft: -1,
                    ArrowUp: -1
                };

                if (event.key === 'Home') {
                    event.preventDefault();
                    activate(tabs[0].dataset.footnoteTab, true);
                    return;
                }

                if (event.key === 'End') {
                    event.preventDefault();
                    activate(tabs[tabs.length - 1].dataset.footnoteTab, true);
                    return;
                }

                if (!Object.prototype.hasOwnProperty.call(keyMap, event.key)) return;

                event.preventDefault();
                const nextIndex = (index + keyMap[event.key] + tabs.length) % tabs.length;
                activate(tabs[nextIndex].dataset.footnoteTab, true);
            });
        });

        const activeTab = tabs.find(tab => tab.getAttribute('aria-selected') === 'true') || tabs[0];
        activate(activeTab.dataset.footnoteTab, false, false);
    });
};

export function initFootnotesPopover() {
    initFootnoteInfoControls();
    initFootnoteTabs();

    if (!isRealDesktopHoverDevice()) return;

    const references = Array.from(document.querySelectorAll(FOOTNOTE_SELECTOR));
    if (!references.length || !document.querySelector(FOOTNOTE_LIST_SELECTOR)) return;

    const popover = document.createElement('div');
    popover.className = POPOVER_CLASS;
    popover.setAttribute('role', 'tooltip');
    popover.setAttribute('aria-hidden', 'true');
    document.body.appendChild(popover);

    let activeReference = null;
    let hideTimer = 0;

    const hide = () => {
        window.clearTimeout(hideTimer);
        activeReference = null;
        popover.classList.remove('is-visible');
        popover.setAttribute('aria-hidden', 'true');
    };

    const show = (reference) => {
        const id = reference.getAttribute('data-fn');
        const content = id ? getFootnoteContent(id) : null;
        if (!id || !content) return;

        window.clearTimeout(hideTimer);
        activeReference = reference;
        popover.innerHTML = `<div class="${POPOVER_CLASS}__label">脚注 ${reference.textContent.trim()}</div><div class="${POPOVER_CLASS}__content">${content}</div>`;
        popover.setAttribute('aria-hidden', 'false');
        popover.classList.add('is-visible');
        positionPopover(popover, reference);
    };

    references.forEach(reference => {
        reference.classList.add('node-footnote-ref');

        reference.addEventListener('mouseenter', () => show(reference));
        reference.addEventListener('mouseleave', () => {
            hideTimer = window.setTimeout(hide, 120);
        });
        reference.addEventListener('focusin', () => show(reference));
        reference.addEventListener('focusout', () => {
            hideTimer = window.setTimeout(hide, 120);
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && activeReference) {
            hide();
        }
    });

    popover.addEventListener('mouseenter', () => {
        window.clearTimeout(hideTimer);
    });

    popover.addEventListener('mouseleave', () => {
        hideTimer = window.setTimeout(hide, 120);
    });

    window.addEventListener('scroll', () => {
        if (activeReference) positionPopover(popover, activeReference);
    }, { passive: true });

    window.addEventListener('resize', () => {
        if (!isRealDesktopHoverDevice()) {
            hide();
            return;
        }

        if (activeReference) positionPopover(popover, activeReference);
    }, { passive: true });
}
