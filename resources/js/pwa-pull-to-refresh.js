import PullToRefresh from 'pulltorefreshjs';

document.addEventListener('DOMContentLoaded', () => {
    const isStandalone =
        window.matchMedia('(display-mode: standalone)').matches ||
        window.navigator.standalone === true;

    if (!isStandalone) {
        return;
    }

    PullToRefresh.init({
        mainElement: 'body',
        instructionsPullToRefresh: 'Trek om te vernieuwen',
        instructionsReleaseToRefresh: 'Laat los om te scannen',
        instructionsRefreshing: 'Vestix radar updaten...',
        distIgnore: 50,
        onRefresh: () => window.location.reload(),
    });
});
