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
    const readingBody = document.querySelector('.m3-article__body');

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
        const form = document.querySelector('#respond');
        const formBounds = form?.getBoundingClientRect();
        // Give the entire comment form the viewport while it is visible or focused.
        // The TOC and TOP remain available before and after the form.
        const editing = !isDesktop && form && (form.contains(document.activeElement)
            || (formBounds.top < window.innerHeight && formBounds.bottom > 0));
        let inReadingLane = true;
        if (!isDesktop) {
            const bounds = readingBody?.getBoundingClientRect();
            const style = getComputedStyle(actionStack);
            const size = parseFloat(style.getPropertyValue('--node-action-size')) || 48;
            const gap = parseFloat(style.gap) || 12;
            const hasToc = tocTrigger && getComputedStyle(tocTrigger).display !== 'none';
            const horizontal = style.flexDirection === 'row' || style.flexDirection === 'row-reverse';
            const height = (horizontal || !hasToc ? size : 2 * size + gap)
                + (parseFloat(style.paddingTop) || 0) + (parseFloat(style.paddingBottom) || 0);
            const bottomOffset = parseFloat(style.bottom);
            const bottom = window.innerHeight - (Number.isFinite(bottomOffset) ? bottomOffset : 24);
            // Other article sections keep their full width. Retire the actions
            // before the body gutter ends rather than floating over those sections.
            inReadingLane = isSingle && Boolean(bounds
                && bounds.top <= bottom - height && bounds.bottom >= bottom);
        }
        const visible = shouldShow && !editing && inReadingLane;
        actionStack.hidden = !visible;
        actionStack.classList.toggle('is-visible', visible);
    };

    window.addEventListener('scroll', updateVisibility, { passive: true });
    window.addEventListener('resize', updateVisibility, { passive: true });
    document.addEventListener('m3:toc:ready', updateVisibility);
    document.addEventListener('focusin', updateVisibility);
    document.addEventListener('focusout', () => queueMicrotask(updateVisibility));
    setTimeout(updateVisibility, 150);
}
