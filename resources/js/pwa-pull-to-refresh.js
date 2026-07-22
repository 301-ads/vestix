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

function isPageAtTop() {
    return maxScrollTop() <= SCROLL_TOP_TOLERANCE;
}

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

function clearPtrTopClass() {
    document.body?.classList.remove('ptr--top');
}

/**
 * Completely tear down pull-to-refresh while the user is browsing mid-page.
 * This avoids pulltorefreshjs calling preventDefault / applying pan-down
 * touch-action that can trap iOS scrolling before the bottom buttons.
 */
function disablePullToRefresh() {
    if (ptrInstance) {
        ptrInstance.destroy();
        ptrInstance = null;
    }

    // Ensure no leftover global handlers from the library remain active.
    PullToRefresh.destroyAll();
    clearPtrTopClass();
}

function enablePullToRefresh() {
    if (ptrInstance || !shouldPullToRefresh()) {
        return;
    }

    PullToRefresh.setPassiveMode(false);

    ptrInstance = PullToRefresh.init({
        mainElement: 'body',
        triggerElement: 'body',
        instructionsPullToRefresh: 'Trek om te vernieuwen',
        instructionsReleaseToRefresh: 'Laat los om te scannen',
        instructionsRefreshing: 'Vestix radar updaten...',
        distIgnore: 0,
        distThreshold: 55,
        distMax: 80,
        distReload: 50,
        shouldPullToRefresh,
        // Strip the library's pan-down touch-action rule that blocks scrolling down.
        getStyles() {
            return `
.__PREFIX__ptr {
  box-shadow: inset 0 -1px 0 rgba(0, 0, 0, 0.12);
  pointer-events: none;
  font-size: 0.85em;
  font-weight: bold;
  top: 0;
  height: 0;
  transition: height 0.3s, min-height 0.3s;
  text-align: center;
  width: 100%;
  overflow: hidden;
  display: flex;
  align-items: flex-end;
  align-content: stretch;
}
.__PREFIX__box {
  padding: 10px;
  flex-basis: 100%;
}
.__PREFIX__pull {
  transition: none;
}
.__PREFIX__text {
  margin-top: .33em;
}
.__PREFIX__icon {
  transition: transform .3s;
}
.__PREFIX__top {
  touch-action: pan-x pan-y pinch-zoom;
}
.__PREFIX__release .__PREFIX__icon {
  transform: rotate(180deg);
}
`;
        },
        onRefresh: () => window.location.reload(),
    });
}

function syncPullToRefreshForScrollPosition() {
    if (shouldPullToRefresh()) {
        enablePullToRefresh();
    } else {
        disablePullToRefresh();
    }
}

function bindScrollSync() {
    if (scrollSyncBound) {
        syncPullToRefreshForScrollPosition();

        return;
    }

    scrollSyncBound = true;

    const options = { passive: true };
    const onScroll = () => syncPullToRefreshForScrollPosition();

    window.addEventListener('scroll', onScroll, options);
    scrollRoots().forEach((el) => {
        el.addEventListener('scroll', onScroll, options);
    });

    syncPullToRefreshForScrollPosition();
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

    bindScrollSync();
});
