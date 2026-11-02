import { storage } from '../storage';
import { isPhoneLikeDevice, isTabletLikeDevice } from './device-mode';

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

    return isPhoneLikeDevice() || isTabletLikeDevice()
        || window.matchMedia('(max-width: 767px)').matches ? 'mobile' : 'pc';
}

function syncViewToggleUI(btn) {
    const mode = getViewMode();
    const icon = document.getElementById('m3-view-toggle-icon');
    const isMobileToggle = isPhoneLikeDevice() || isTabletLikeDevice()
        || window.matchMedia('(max-width: 767px)').matches
        || document.documentElement.dataset.deviceClass === 'phone'
        || document.documentElement.dataset.deviceClass === 'tablet'
        || storage.get('view-mode') === 'mobile';

    if (!isMobileToggle) {
        btn.hidden = true;
        return;
    }

    document.documentElement.dataset.viewMode = mode;
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

    btn.addEventListener('click', () => {
        const current = getViewMode();
        const next = current === 'pc' ? 'mobile' : 'pc';
        storage.set('view-mode', next);
        location.reload();
    });
}
