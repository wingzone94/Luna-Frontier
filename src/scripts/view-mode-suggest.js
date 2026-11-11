import { storage } from '../storage';
import {
    getDeviceSignals,
    isPhoneLikeDevice,
    isRealDesktopHoverDevice,
    isTabletLikeDevice,
} from './device-mode';
import {
    VIEW_SUGGEST_DISMISS_MS,
    isViewSuggestDismissed,
    shouldSuggestViewMode,
} from './view-mode-suggest-logic';

export const VIEW_SUGGEST_DISMISS_KEY = 'view-mode-suggest-dismiss';

function isTabletIdentity(signals) {
    const { ua, platform, maxTouchPoints } = signals;
    return /iPad/i.test(ua)
        || (platform === 'MacIntel' && maxTouchPoints > 1)
        || (/Android/i.test(ua) && !/Mobile/i.test(ua))
        || /Tablet|Silk|Kindle|PlayBook/i.test(ua);
}

function readSuggestContext() {
    const signals = getDeviceSignals();
    const savedRaw = storage.get('view-mode');
    const saved = savedRaw === 'mobile' || savedRaw === 'pc' ? savedRaw : null;
    return {
        saved,
        serverTablet: document.documentElement.dataset.deviceClass === 'tablet',
        phoneLike: isPhoneLikeDevice(),
        tabletLike: isTabletLikeDevice(),
        tabletIdentity: isTabletIdentity(signals),
        hasHoverMouse: signals.hasHoverMouse,
        realDesktop: isRealDesktopHoverDevice(),
    };
}

const COPY = {
    mobile: {
        text: 'このデバイスではモバイル表示がおすすめです',
        apply: 'モバイル表示にする',
    },
    pc: {
        text: 'このデバイスではPC表示がおすすめです',
        apply: 'PC表示にする',
    },
};

export function initViewModeSuggest() {
    if (document.documentElement.dataset.nativeApp === '1') return;
    if (document.body && document.body.classList.contains('is-native-app')) return;
    if (window.matchMedia('print').matches) return;
    if (document.querySelector('.m3-view-suggest')) return;

    const decision = shouldSuggestViewMode(readSuggestContext());
    if (!decision) return;

    const now = Date.now();
    let dismissed = null;
    try {
        dismissed = storage.get(VIEW_SUGGEST_DISMISS_KEY);
    } catch {
        dismissed = null;
    }
    if (isViewSuggestDismissed(dismissed, decision.fingerprint, now)) return;

    const copy = COPY[decision.recommended];
    const narrow = window.matchMedia('(max-width: 600px)').matches;
    const banner = document.createElement('div');
    banner.className = 'm3-view-suggest ' + (narrow ? 'm3-view-suggest--mobile' : 'm3-view-suggest--header');
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-labelledby', 'm3-view-suggest-text');

    const text = document.createElement('p');
    text.id = 'm3-view-suggest-text';
    text.className = 'm3-view-suggest__text';
    text.textContent = copy.text;

    const actions = document.createElement('div');
    actions.className = 'm3-view-suggest__actions';

    const apply = document.createElement('button');
    apply.type = 'button';
    apply.className = 'm3-view-suggest__apply';
    apply.textContent = copy.apply;

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'm3-view-suggest__dismiss';
    dismiss.textContent = 'このままにする';
    dismiss.setAttribute('aria-label', '現在の表示のまま閉じる');

    actions.append(apply, dismiss);
    banner.append(text, actions);
    document.body.appendChild(banner);

    const close = () => {
        document.removeEventListener('keydown', onKey);
        banner.remove();
    };

    const onKey = (event) => {
        if (event.key !== 'Escape') return;
        if (document.querySelector('dialog[open]')) return;
        event.preventDefault();
        rememberDismiss();
        close();
    };

    const rememberDismiss = () => {
        try {
            storage.set(VIEW_SUGGEST_DISMISS_KEY, {
                fingerprint: decision.fingerprint,
                until: Date.now() + VIEW_SUGGEST_DISMISS_MS,
            });
        } catch {
            /* 保存できない場合は閉じるだけ。表示モード本体は変えない。 */
        }
    };

    apply.addEventListener('click', () => {
        storage.set('view-mode', decision.recommended);
        location.reload();
    });
    dismiss.addEventListener('click', () => {
        rememberDismiss();
        close();
    });
    document.addEventListener('keydown', onKey);

    requestAnimationFrame(() => banner.classList.add('is-visible'));
}
