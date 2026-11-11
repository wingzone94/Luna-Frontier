import assert from 'node:assert/strict';
import test from 'node:test';
import {
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

test('tablet saved pc suggests mobile', () => {
    const result = shouldSuggestViewMode({ ...tablet, saved: 'pc' });
    assert.equal(result?.recommended, 'mobile');
    assert.equal(result?.current, 'pc');
    assert.equal(result?.deviceKind, 'tablet');
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

test('no saved value follows the existing initial mode', () => {
    assert.equal(shouldSuggestViewMode({ ...phone, saved: null }), null);
    assert.equal(shouldSuggestViewMode({ ...tablet, saved: null }), null);
    assert.equal(shouldSuggestViewMode({ ...desktop, saved: null }), null);
});

test('touch laptop does not suggest even when the saved mode looks wrong', () => {
    assert.equal(shouldSuggestViewMode({ ...touchLaptop, saved: 'pc' }), null);
    assert.equal(shouldSuggestViewMode({ ...touchLaptop, saved: 'mobile' }), null);
});

test('server tablet viewport versus a real desktop is a mismatch', () => {
    const result = shouldSuggestViewMode({
        ...desktop,
        saved: null,
        serverTablet: true,
    });
    assert.equal(result?.current, 'mobile');
    assert.equal(result?.recommended, 'pc');
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
