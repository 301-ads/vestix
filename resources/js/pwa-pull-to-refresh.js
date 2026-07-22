import PullToRefresh from 'pulltorefreshjs';

const SCROLL_TOP_TOLERANCE = 2;

function isStandalonePwa() {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true
    );
}

function pageScrollTop() {
    return Math.max(
        window.scrollY || 0,
        document.documentElement?.scrollTop || 0,
        document.body?.scrollTop || 0,
    );
}

/**
 * Allow a couple of pixels of bounce/residue so iOS does not miss the gesture
 * when scrollTop is not exactly zero after rubber-banding.
 */
function isPageAtTop() {
    return pageScrollTop() <= SCROLL_TOP_TOLERANCE;
}

/**
 * Nested scroll areas (tables, modals) should keep their own scroll instead of
 * triggering a full page refresh while they are scrolled.
 */
function hasScrolledOverflowAncestor(target) {
    let node = target instanceof Element ? target : null;

    while (node && node !== document.body && node !== document.documentElement) {
        if (node instanceof HTMLElement) {
            const { overflowY } = window.getComputedStyle(node);
            const canScroll = overflowY === 'auto' || overflowY === 'scroll' || overflowY === 'overlay';

            if (canScroll && node.scrollTop > SCROLL_TOP_TOLERANCE) {
                return true;
            }
        }

        node = node.parentElement;
    }

    return false;
}

let lastTouchTarget = null;
let ptrInstance = null;

function shouldPullToRefresh() {
    if (!isPageAtTop()) {
        return false;
    }

    if (lastTouchTarget && hasScrolledOverflowAncestor(lastTouchTarget)) {
        return false;
    }

    return true;
}

function initPullToRefresh() {
    if (!isStandalonePwa()) {
        return;
    }

    if (ptrInstance) {
        ptrInstance.destroy();
        ptrInstance = null;
    }

    // Ensure touchmove can call preventDefault immediately when pulling.
    PullToRefresh.setPassiveMode(false);

    ptrInstance = PullToRefresh.init({
        mainElement: 'body',
        triggerElement: 'body',
        instructionsPullToRefresh: 'Trek om te vernieuwen',
        instructionsReleaseToRefresh: 'Laat los om te scannen',
        instructionsRefreshing: 'Vestix radar updaten...',
        // Start resisting on the first pull pixel so iOS cannot steal the gesture.
        distIgnore: 0,
        distThreshold: 55,
        distMax: 80,
        distReload: 50,
        shouldPullToRefresh,
        onRefresh: () => window.location.reload(),
    });
}

function trackTouchTarget(event) {
    lastTouchTarget = event.target instanceof Element ? event.target : null;
}

document.addEventListener('DOMContentLoaded', () => {
    if (!isStandalonePwa()) {
        return;
    }

    document.addEventListener('touchstart', trackTouchTarget, { capture: true, passive: true });
    document.addEventListener('pointerdown', trackTouchTarget, { capture: true, passive: true });

    initPullToRefresh();
});
