import { storage } from '../storage.js';
import { isPhoneLikeDevice, isTabletLikeDevice, syncTabletPcClass } from './device-mode.js';
import { initViewModeSuggest } from './view-mode-suggest.js';

export const VIEW_MODE_ICON = {
    pc: 'computer',
    tablet: 'tablet_mac',
    mobile: 'smartphone',
};

const VIEW_MODE_LABEL = {
    pc: 'PC表示モード',
    mobile: 'モバイル表示モード',
};

const VIEW_MODE_TOOLTIP = {
    pc: 'PC表示モード（タップでモバイル表示）',
    mobile: 'モバイル表示モード（タップでPC表示）',
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
}

export function initViewSwitcher() {
    const btn = document.getElementById('m3-view-toggle');
    if (!btn) return;

    syncViewToggleUI(btn);
    initViewModeSuggest();

    btn.addEventListener('click', () => {
        const current = getViewMode();
        const next = current === 'pc' ? 'mobile' : 'pc';
        storage.set('view-mode', next);
        location.reload();
    });
}
