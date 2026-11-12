/**
 * Floating action stack (back-to-top, comments, AI, TOC trigger visibility).
 */

import { isIndexOrArchiveView, isSinglePostView } from './page-state';

export function initFloatingActions() {
    const actionStack = document.querySelector('.m3-action-stack');
    if (!actionStack) return;

    const isSingle = isSinglePostView();
    const showBackToTopContext = isSingle || isIndexOrArchiveView();
    const backToTop = document.getElementById('m3-back-to-top');
    const tocTrigger = document.getElementById('m3-toc-trigger');
    const tocPanel = document.getElementById('m3-sticky-toc');
    const mobileToggle = document.getElementById('m3-mobile-actions-toggle');

    // The mobile pull tab folds the whole floating action area, not the TOC panel.
    // Keep its icon and accessible state synchronized with the actual stack state.
    if (mobileToggle) {
        const syncMobileToggle = () => {
            const collapsed = actionStack.classList.contains('is-mobile-collapsed');
            mobileToggle.setAttribute('aria-expanded', String(!collapsed));
            mobileToggle.setAttribute('aria-label', collapsed ? '追従ボタンを展開する' : '追従ボタンを折りたたむ');
            const icon = mobileToggle.querySelector('.material-symbols-outlined');
            if (icon) icon.textContent = collapsed ? 'keyboard_arrow_down' : 'keyboard_arrow_up';
        };
        mobileToggle.addEventListener('click', () => {
            actionStack.classList.toggle('is-mobile-collapsed');
            syncMobileToggle();
        });
        syncMobileToggle();
    }

    const lunaCharts = document.querySelectorAll('.luna-chart');
    if (lunaCharts.length && 'IntersectionObserver' in window) {
        const visibleCharts = new Set();
        const chartObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) visibleCharts.add(entry.target);
                else visibleCharts.delete(entry.target);
            });
            document.body.classList.toggle('is-luna-chart-visible', visibleCharts.size > 0);
        });
        lunaCharts.forEach((chart) => chartObserver.observe(chart));
    }

    backToTop?.addEventListener('click', (event) => {
        event.preventDefault();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    if (!isSingle) {
        tocTrigger?.remove();
        tocPanel?.classList.remove('is-active');
        tocPanel?.remove();
        document.querySelector('.m3-toc-scrim')?.remove();
        actionStack.classList.remove('is-has-toc');
        document.body.classList.remove('is-active-toc');
    }

    const updateVisibility = () => {
        if (!showBackToTopContext) {
            actionStack.classList.remove('is-visible');
            return;
        }

        const scrollY = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
        const tocReady = isSingle && Boolean(document.querySelector('#m3-toc-trigger.toc-ready'));
        const isDesktop = window.matchMedia('(min-width: 1001px)').matches;
        const shouldShow = isDesktop || scrollY > 200 || tocReady;

        actionStack.classList.toggle('is-visible', shouldShow);
    };

    window.addEventListener('scroll', updateVisibility, { passive: true });
    window.addEventListener('resize', updateVisibility, { passive: true });
    document.addEventListener('m3:toc:ready', updateVisibility);
    setTimeout(updateVisibility, 150);
}
