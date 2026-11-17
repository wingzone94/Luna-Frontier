import { storage } from '../storage.js';
import { isPhoneLikeDevice, isTabletLikeDevice, syncTabletPcClass } from './device-mode.js';
import { initViewModeSuggest } from './view-mode-suggest.js';

export const VIEW_MODE_ICON = {
    pc: 'computer',
    tablet: 'tablet',
    mobile: 'smartphone',
};

const VIEW_MODE_LABEL = {
    pc: 'モバイル表示に切り替える',
    mobile: 'PC表示に切り替える',
};

const VIEW_MODE_TOOLTIP = {
    pc: '現在はPC表示です',
    mobile: '現在はモバイル表示です',
};

/**
 * Resolve the key in VIEW_MODE_ICON for a 2-state view mode and device class.
 */
export function resolveViewToggleIconKey(mode, deviceClass = '') {
    if (mode === 'pc' && deviceClass === 'tablet') {
        return 'tablet';
    }
    return mode === 'mobile' ? 'mobile' : 'pc';
}

export function resolveViewToggleIcon(mode, deviceClass = '') {
    return VIEW_MODE_ICON[resolveViewToggleIconKey(mode, deviceClass)];
}

function getDeviceClass(root) {
    const declared = root.dataset.deviceClass;
    if (declared) {
        return declared;
    }
    if (isPhoneLikeDevice()) {
        return 'phone';
    }
    if (isTabletLikeDevice()) {
        return 'tablet';
    }
    return '';
}

function getViewMode() {
    const saved = storage.get('view-mode');
    if (saved === 'mobile' || saved === 'pc') {
        return saved;
    }

    if (isPhoneLikeDevice()) {
        return 'mobile';
    }

    if (isTabletLikeDevice()) {
        return 'pc';
    }

    return window.matchMedia('(max-width: 767px)').matches ? 'mobile' : 'pc';
}

export function syncViewToggleUI(btn) {
    const root = document.documentElement;
    const mode = getViewMode();
    const deviceClass = getDeviceClass(root);
    const icon = document.getElementById('m3-view-toggle-icon');

    root.dataset.viewMode = mode;
    syncTabletPcClass();
    btn.hidden = false;

    if (icon) {
        icon.dataset.icon = VIEW_MODE_ICON[resolveViewToggleIconKey(mode, deviceClass)];
        icon.textContent = '';
    }

    btn.dataset.viewMode = mode;
    btn.setAttribute('aria-label', VIEW_MODE_LABEL[mode]);
    btn.dataset.tooltip = VIEW_MODE_TOOLTIP[mode];
    const fallback = document.getElementById('m3-view-toggle-fallback');
    if (fallback) fallback.textContent = mode === 'pc' ? 'PC' : 'スマホ';
}

export function initViewSwitcher() {
    const btn = document.getElementById('m3-view-toggle');
    if (!btn) return;

    syncViewToggleUI(btn);
    initViewModeSuggest();

    const icon = document.getElementById('m3-view-toggle-icon');
    const fallback = document.getElementById('m3-view-toggle-fallback');
    const updateFontState = async () => {
        let ready = false;
        try {
            // check() alone returns true when a failed stylesheet registered no faces.
            const faces = await document.fonts?.load('24px "Material Symbols Outlined"', icon?.dataset.icon || '');
            ready = Boolean(faces?.length && faces.every(face => face.status === 'loaded'));
        } catch {
            // Keep the readable fallback when the font request fails.
        }
        btn.dataset.viewIconReady = String(ready);
        if (fallback) fallback.hidden = ready;
    };
    updateFontState();
    document.fonts?.addEventListener('loadingdone', updateFontState);

    const tooltip = document.createElement('span');
    tooltip.id = 'm3-view-toggle-tooltip';
    tooltip.className = 'm3-view-toggle-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.textContent = btn.dataset.tooltip;
    tooltip.hidden = true;
    btn.after(tooltip);
    btn.setAttribute('aria-describedby', tooltip.id);
    let hideTimer;
    const showTooltip = () => {
        clearTimeout(hideTimer);
        tooltip.hidden = false;
    };
    const hideTooltip = () => { tooltip.hidden = true; };
    const scheduleHide = () => {
        clearTimeout(hideTimer);
        if (!btn.matches(':focus-visible')) hideTimer = setTimeout(hideTooltip, 150);
    };
    btn.addEventListener('focus', showTooltip);
    btn.addEventListener('blur', scheduleHide);
    btn.addEventListener('pointerenter', showTooltip);
    btn.addEventListener('pointerleave', scheduleHide);
    tooltip.addEventListener('pointerenter', showTooltip);
    tooltip.addEventListener('pointerleave', scheduleHide);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            clearTimeout(hideTimer);
            hideTooltip();
        }
    });

    btn.addEventListener('click', () => {
        const current = getViewMode();
        const next = current === 'pc' ? 'mobile' : 'pc';
        storage.set('view-mode', next);
        location.reload();
    });
}
