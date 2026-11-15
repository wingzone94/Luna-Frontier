const TOGGLE_SELECTOR = '[data-product-download-info-toggle]';
const POPOVER_SELECTOR = '[data-product-download-info-popover]';

const positionPopover = (popover, toggle) => {
    popover.style.transform = '';
    popover.dataset.placement = 'top';

    const margin = 12;
    let rect = popover.getBoundingClientRect();
    const toggleRect = toggle.getBoundingClientRect();

    if (rect.top < margin && (window.innerHeight - toggleRect.bottom) > rect.height + margin) {
        popover.dataset.placement = 'bottom';
        rect = popover.getBoundingClientRect();
    }

    let shiftX = 0;
    if (rect.left < margin) {
        shiftX = margin - rect.left;
    } else if (rect.right > window.innerWidth - margin) {
        shiftX = (window.innerWidth - margin) - rect.right;
    }

    if (shiftX !== 0) {
        popover.style.transform = `translateX(${Math.round(shiftX)}px)`;
    }
};

const getPopoverForToggle = (toggle) => {
    const id = toggle?.getAttribute('aria-controls');
    if (!id) return null;
    const popover = document.getElementById(id);
    return popover && popover.matches(POPOVER_SELECTOR) ? popover : null;
};

let activeState = null;
let listenersBound = false;

const closeActivePopover = ({ restoreFocus = false } = {}) => {
    if (!activeState) return;
    const { toggle, popover } = activeState;
    popover.hidden = true;
    popover.style.transform = '';
    toggle.setAttribute('aria-expanded', 'false');
    activeState = null;
    if (restoreFocus && typeof toggle.focus === 'function') {
        toggle.focus();
    }
};

const openPopover = (toggle, popover) => {
    if (activeState && activeState.toggle !== toggle) {
        closeActivePopover();
    }
    popover.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    activeState = { toggle, popover };
    positionPopover(popover, toggle);
};

const bindDocumentListeners = () => {
    if (listenersBound) return;
    listenersBound = true;

    document.addEventListener('click', (event) => {
        if (!activeState) return;
        const { toggle, popover } = activeState;
        const target = event.target;
        if (toggle.contains(target) || popover.contains(target)) {
            return;
        }
        closeActivePopover();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeState) {
            event.preventDefault();
            closeActivePopover({ restoreFocus: true });
        }
    });

    window.addEventListener('resize', () => {
        if (activeState) {
            positionPopover(activeState.popover, activeState.toggle);
        }
    }, { passive: true });
};

/**
 * Initialize download info popovers on Nexus product cards.
 */
export const initProductCardPopovers = () => {
    const toggles = document.querySelectorAll(TOGGLE_SELECTOR);
    if (toggles.length === 0) return;

    bindDocumentListeners();

    toggles.forEach((toggle) => {
        if (toggle.dataset.popoverReady === 'true') return;
        const popover = getPopoverForToggle(toggle);
        if (!popover) return;

        toggle.dataset.popoverReady = 'true';
        popover.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');

        toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const isOpen = toggle.getAttribute('aria-expanded') === 'true' && !popover.hidden;
            if (isOpen) {
                closeActivePopover();
            } else {
                openPopover(toggle, popover);
            }
        });
    });
};
