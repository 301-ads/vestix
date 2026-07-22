import PullToRefresh from 'pulltorefreshjs';

const SCROLL_TOP_TOLERANCE = 2;

function isStandalonePwa() {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true
    );
}

/**
 * Filament's shell often scrolls `.fi-layout` (overflow-x: clip forces overflow-y),
 * while window.scrollY stays 0. Check every plausible scroll root.
 *
 * @returns {HTMLElement[]}
 */
function scrollRoots() {
    const roots = [
        document.scrollingElement,
        document.documentElement,
        document.body,
        document.querySelector('.fi-layout'),
        document.querySelector('.fi-main-ctn'),
        document.querySelector('.fi-main'),
    ].filter((el) => el instanceof HTMLElement);

    return [...new Set(roots)];
}

function maxScrollTop() {
    return scrollRoots().reduce((max, el) => Math.max(max, el.scrollTop || 0), window.scrollY || 0);
}

/**
 * Allow a couple of pixels of bounce/residue so iOS does not miss the gesture
 * when scrollTop is not exactly zero after rubber-banding.
 */
function isPageAtTop() {
    return maxScrollTop() <= SCROLL_TOP_TOLERANCE;
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
let scrollSyncBound = false;

function shouldPullToRefresh() {
    if (!isPageAtTop()) {
        return false;
    }

    if (lastTouchTarget && hasScrolledOverflowAncestor(lastTouchTarget)) {
        return false;
    }

    return true;
}

/**
 * pulltorefreshjs only listens to window scroll to toggle `.ptr--top`.
 * When Filament scrolls `.fi-layout`, that class would stick and
 * `touch-action: pan-down` would block scrolling further down the page.
 */
function syncPtrTopClass() {
    const main = document.body;

    if (!(main instanceof HTMLElement)) {
        return;
    }

    main.classList.toggle('ptr--top', shouldPullToRefresh());
}

function bindScrollSync() {
    if (scrollSyncBound) {
        syncPtrTopClass();

        return;
    }

    scrollSyncBound = true;

    const options = { passive: true };

    window.addEventListener('scroll', syncPtrTopClass, options);
    scrollRoots().forEach((el) => {
        el.addEventListener('scroll', syncPtrTopClass, options);
    });

    syncPtrTopClass();
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

    bindScrollSync();
}

function trackTouchTarget(event) {
    lastTouchTarget = event.target instanceof Element ? event.target : null;
    syncPtrTopClass();
}

document.addEventListener('DOMContentLoaded', () => {
    if (!isStandalonePwa()) {
        return;
    }

    document.addEventListener('touchstart', trackTouchTarget, { capture: true, passive: true });
    document.addEventListener('pointerdown', trackTouchTarget, { capture: true, passive: true });

    initPullToRefresh();
});
