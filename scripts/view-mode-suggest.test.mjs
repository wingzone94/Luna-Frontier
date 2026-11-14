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
