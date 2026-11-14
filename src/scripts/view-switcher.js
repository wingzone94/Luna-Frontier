import { storage } from '../storage';
import { isPhoneLikeDevice, isTabletLikeDevice, syncTabletPcClass } from './device-mode';
import { initViewModeSuggest } from './view-mode-suggest';

const VIEW_MODE_ICON = {
    pc: 'computer',
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

function syncViewToggleUI(btn) {
    const mode = getViewMode();
    const icon = document.getElementById('m3-view-toggle-icon');

    document.documentElement.dataset.viewMode = mode;
    syncTabletPcClass();
    btn.hidden = false;

    if (icon) {
        icon.textContent = VIEW_MODE_ICON[mode];
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
