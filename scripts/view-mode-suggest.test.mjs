import assert from 'node:assert/strict';
import test from 'node:test';
import {
    isPhoneLikeDevice,
    isTabletIdentitySignals,
    isTabletLikeDevice,
} from '../src/scripts/device-mode.js';
import {
    effectiveViewMode,
    isViewSuggestDismissed,
    shouldSuggestViewMode,
    VIEW_SUGGEST_DISMISS_MS,
} from '../src/scripts/view-mode-suggest-logic.js';
import {
    resolveViewToggleIcon,
    resolveViewToggleIconKey,
    syncViewToggleUI,
    VIEW_MODE_ICON,
} from '../src/scripts/view-switcher.js';

const phone = {
    phoneLike: true,
    tabletIdentity: false,
    tabletLike: false,
    hasHoverMouse: false,
    realDesktop: false,
    serverTablet: false,
};

const tablet = {
    phoneLike: false,
    tabletIdentity: true,
    tabletLike: true,
    hasHoverMouse: false,
    realDesktop: false,
    serverTablet: true,
};

const desktop = {
    phoneLike: false,
    tabletIdentity: false,
    tabletLike: false,
    hasHoverMouse: true,
    realDesktop: true,
    serverTablet: false,
};

const touchLaptop = {
    phoneLike: false,
    tabletIdentity: false,
    tabletLike: true,
    hasHoverMouse: true,
    realDesktop: false,
    serverTablet: false,
};

test('phone saved pc suggests mobile', () => {
    const result = shouldSuggestViewMode({ ...phone, saved: 'pc' });
    assert.equal(result?.recommended, 'mobile');
    assert.equal(result?.current, 'pc');
    assert.equal(result?.deviceKind, 'phone');
});

test('phone saved mobile does not suggest', () => {
    assert.equal(shouldSuggestViewMode({ ...phone, saved: 'mobile' }), null);
});

test('tablet defaults to pc and never suggests mobile', () => {
    assert.equal(effectiveViewMode({ ...tablet, saved: null }), 'pc');
    assert.equal(shouldSuggestViewMode({ ...tablet, saved: null }), null);
    assert.equal(shouldSuggestViewMode({ ...tablet, saved: 'pc' }), null);
});

test('tablet explicit mobile choice is respected without suggestion', () => {
    assert.equal(effectiveViewMode({ ...tablet, saved: 'mobile' }), 'mobile');
    assert.equal(shouldSuggestViewMode({ ...tablet, saved: 'mobile' }), null);
});

test('desktop saved mobile suggests pc', () => {
    const result = shouldSuggestViewMode({ ...desktop, saved: 'mobile' });
    assert.equal(result?.recommended, 'pc');
    assert.equal(result?.current, 'mobile');
    assert.equal(result?.deviceKind, 'desktop');
});

test('desktop saved pc does not suggest', () => {
    assert.equal(shouldSuggestViewMode({ ...desktop, saved: 'pc' }), null);
});

test('no saved value follows the new initial mode per device', () => {
    assert.equal(effectiveViewMode({ ...phone, saved: null }), 'mobile');
    assert.equal(effectiveViewMode({ ...tablet, saved: null }), 'pc');
    assert.equal(effectiveViewMode({ ...desktop, saved: null }), 'pc');
    assert.equal(shouldSuggestViewMode({ ...phone, saved: null }), null);
    assert.equal(shouldSuggestViewMode({ ...tablet, saved: null }), null);
    assert.equal(shouldSuggestViewMode({ ...desktop, saved: null }), null);
});

test('touch laptop does not suggest even when the saved mode looks wrong', () => {
    assert.equal(shouldSuggestViewMode({ ...touchLaptop, saved: 'pc' }), null);
    assert.equal(shouldSuggestViewMode({ ...touchLaptop, saved: 'mobile' }), null);
});

test('tablet device class does not force mobile mode on desktop', () => {
    assert.equal(shouldSuggestViewMode({ ...desktop, saved: null, serverTablet: true }), null);
});

test('device-mode detects iPad, iPadOS desktop UA, Android tablet, and Windows touch tablet', () => {
    const iPadMini = { ua: 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)', platform: 'iPad', maxTouchPoints: 5, hasCoarsePointer: true, isLargeViewport: false };
    assert.equal(isTabletIdentitySignals(iPadMini), true);
    assert.equal(isTabletLikeDevice(iPadMini), true);
    assert.equal(isPhoneLikeDevice(iPadMini), false);

    const iPadDesktopUa = { ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: '', maxTouchPoints: 5, hasCoarsePointer: true, isLargeViewport: true };
    assert.equal(isTabletIdentitySignals(iPadDesktopUa), true);
    assert.equal(isTabletLikeDevice(iPadDesktopUa), true);
    assert.equal(isPhoneLikeDevice(iPadDesktopUa), false);

    const androidTablet = { ua: 'Mozilla/5.0 (Linux; Android 14; Pixel Tablet) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', platform: 'Linux armv8l', maxTouchPoints: 5, hasCoarsePointer: true, isLargeViewport: true };
    assert.equal(isTabletIdentitySignals(androidTablet), true);
    assert.equal(isPhoneLikeDevice(androidTablet), false);

    const windowsTablet = { ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', platform: 'Win32', maxTouchPoints: 10, hasHoverMouse: true, hasCoarsePointer: true, isLargeViewport: true };
    assert.equal(isTabletIdentitySignals(windowsTablet), true);
    assert.equal(isTabletLikeDevice(windowsTablet), true);
    assert.equal(isPhoneLikeDevice(windowsTablet), false);

    const windowsDesktop = { ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', platform: 'Win32', maxTouchPoints: 0, hasHoverMouse: true, hasCoarsePointer: false, isLargeViewport: true };
    assert.equal(isTabletIdentitySignals(windowsDesktop), false);
    assert.equal(isTabletLikeDevice(windowsDesktop), false);

    const iPhone = { ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', platform: 'iPhone', maxTouchPoints: 5, hasCoarsePointer: true, isLargeViewport: false };
    assert.equal(isTabletIdentitySignals(iPhone), false);
    assert.equal(isTabletLikeDevice(iPhone), false);
    assert.equal(isPhoneLikeDevice(iPhone), true);

    const windowsPhone = { ua: 'Mozilla/5.0 (Windows Phone 10.0; Android 6.0; Microsoft; Lumia 950)', platform: 'ARM', maxTouchPoints: 5, hasCoarsePointer: true, isLargeViewport: false };
    assert.equal(isTabletIdentitySignals(windowsPhone), false);
    assert.equal(isPhoneLikeDevice(windowsPhone), true);
});

test('dismiss hides the same fingerprint only until it expires', () => {
    const decision = shouldSuggestViewMode({ ...phone, saved: 'pc' });
    const now = 1_000_000;
    const record = {
        fingerprint: decision.fingerprint,
        until: now + VIEW_SUGGEST_DISMISS_MS,
    };
    assert.equal(isViewSuggestDismissed(record, decision.fingerprint, now), true);
    assert.equal(isViewSuggestDismissed(record, 'desktop:mobile:pc', now), false);
    assert.equal(isViewSuggestDismissed(record, decision.fingerprint, record.until), false);
});

test('applying the suggestion clears the mismatch', () => {
    const before = shouldSuggestViewMode({ ...phone, saved: 'pc' });
    assert.ok(before);
    const after = shouldSuggestViewMode({ ...phone, saved: before.recommended });
    assert.equal(after, null);
});

test('view-toggle icon resolves tablet_mac for tablet pc mode and preserves computer/smartphone', () => {
    assert.deepEqual(VIEW_MODE_ICON, {
        pc: 'computer',
        tablet: 'tablet_mac',
        mobile: 'smartphone',
    });

    assert.equal(resolveViewToggleIconKey('pc', 'tablet'), 'tablet');
    assert.equal(resolveViewToggleIcon('pc', 'tablet'), 'tablet_mac');

    assert.equal(resolveViewToggleIconKey('mobile', 'tablet'), 'mobile');
    assert.equal(resolveViewToggleIcon('mobile', 'tablet'), 'smartphone');

    assert.equal(resolveViewToggleIconKey('pc', ''), 'pc');
    assert.equal(resolveViewToggleIcon('pc', ''), 'computer');
    assert.equal(resolveViewToggleIcon('pc', 'desktop'), 'computer');

    assert.equal(resolveViewToggleIconKey('mobile', 'phone'), 'mobile');
    assert.equal(resolveViewToggleIcon('mobile', 'phone'), 'smartphone');
    assert.equal(resolveViewToggleIcon('pc', 'phone'), 'computer');
});

test('syncViewToggleUI writes tablet_mac/computer/smartphone without altering 2-state labels or viewMode', () => {
    const origDoc = globalThis.document;
    const origWin = globalThis.window;
    const origNavDesc = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const origLS = globalThis.localStorage;

    try {
        const store = new Map();
        globalThis.localStorage = {
            getItem: (k) => (store.has(k) ? store.get(k) : null),
            setItem: (k, v) => store.set(k, String(v)),
            removeItem: (k) => store.delete(k),
        };
        globalThis.window = {
            matchMedia: () => ({ matches: false }),
        };
        Object.defineProperty(globalThis, 'navigator', {
            value: { userAgent: '', platform: '', maxTouchPoints: 0 },
            configurable: true,
        });

        const runSync = ({ deviceClass, ua, platform = '', maxTouchPoints = 0, saved = null }) => {
            store.clear();
            if (saved !== null) {
                store.set('m3_store_view-mode', JSON.stringify(saved));
            }
            Object.defineProperty(globalThis, 'navigator', {
                value: { userAgent: ua, platform, maxTouchPoints },
                configurable: true,
            });

            const classes = new Set();
            const root = {
                dataset: deviceClass ? { deviceClass } : {},
                classList: {
                    contains: (c) => classes.has(c),
                    toggle: (c, f) => (f ? classes.add(c) : classes.delete(c)),
                },
            };
            const attrs = new Map();
            const icon = { dataset: { icon: 'smartphone' }, textContent: '' };
            const btn = {
                hidden: true,
                dataset: { viewMode: 'mobile' },
                setAttribute: (k, v) => attrs.set(k, v),
            };
            globalThis.document = {
                documentElement: root,
                getElementById: (id) => (id === 'm3-view-toggle-icon' ? icon : null),
            };

            syncViewToggleUI(btn);
            return { root, btn, icon, attrs };
        };

        // Tablet PC (default)
        const tabletPc = runSync({
            deviceClass: 'tablet',
            ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            platform: 'MacIntel',
            maxTouchPoints: 5,
        });
        assert.equal(tabletPc.btn.dataset.viewMode, 'pc');
        assert.equal(tabletPc.icon.dataset.icon, 'tablet_mac');
        assert.equal(tabletPc.icon.textContent, '');
        assert.equal(tabletPc.attrs.get('aria-label'), 'PC表示モード');
        assert.equal(tabletPc.btn.dataset.tooltip, 'PC表示モード（タップでモバイル表示）');

        // Tablet explicit mobile
        const tabletMobile = runSync({
            deviceClass: 'tablet',
            ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            platform: 'MacIntel',
            maxTouchPoints: 5,
            saved: 'mobile',
        });
        assert.equal(tabletMobile.btn.dataset.viewMode, 'mobile');
        assert.equal(tabletMobile.icon.dataset.icon, 'smartphone');
        assert.equal(tabletMobile.icon.textContent, '');
        assert.equal(tabletMobile.attrs.get('aria-label'), 'モバイル表示モード');

        // Desktop PC
        const desktopPc = runSync({
            deviceClass: '',
            ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            platform: 'MacIntel',
            maxTouchPoints: 0,
        });
        assert.equal(desktopPc.btn.dataset.viewMode, 'pc');
        assert.equal(desktopPc.icon.dataset.icon, 'computer');
        assert.equal(desktopPc.icon.textContent, '');

        // Phone mobile
        const phoneMobile = runSync({
            deviceClass: 'phone',
            ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)',
            platform: 'iPhone',
            maxTouchPoints: 5,
        });
        assert.equal(phoneMobile.btn.dataset.viewMode, 'mobile');
        assert.equal(phoneMobile.icon.dataset.icon, 'smartphone');
        assert.equal(phoneMobile.icon.textContent, '');
    } finally {
        globalThis.document = origDoc;
        globalThis.window = origWin;
        if (origNavDesc) {
            Object.defineProperty(globalThis, 'navigator', origNavDesc);
        } else {
            delete globalThis.navigator;
        }
        globalThis.localStorage = origLS;
    }
});
