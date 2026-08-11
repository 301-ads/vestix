import { createChart, ColorType, CrosshairMode, AreaSeries } from 'lightweight-charts';

const RANGES = [
    { key: '1D', label: '1D' },
    { key: '1W', label: '1W' },
    { key: '1M', label: '1M' },
    { key: '3M', label: '3M' },
    { key: '6M', label: '6M' },
    { key: '1Y', label: '1J' },
];

function isDarkMode() {
    if (document.documentElement.classList.contains('dark')) {
        return true;
    }

    const theme = window.theme ?? localStorage.getItem('theme') ?? 'dark';

    if (theme === 'light') {
        return false;
    }

    if (theme === 'dark') {
        return true;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function chartTheme(dark = isDarkMode()) {
    return {
        layout: {
            background: {
                type: ColorType.Solid,
                color: dark ? '#09090b' : '#fafafa',
            },
            textColor: dark ? '#a1a1aa' : '#71717a',
        },
        grid: {
            vertLines: { color: dark ? 'rgba(148,163,184,0.06)' : 'rgba(113,113,122,0.1)' },
            horzLines: { color: dark ? 'rgba(148,163,184,0.06)' : 'rgba(113,113,122,0.1)' },
        },
    };
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function formatSignedMoney(value) {
    const abs = Math.abs(Number(value)).toFixed(2);
    const sign = Number(value) >= 0 ? '+' : '−';

    return `${sign}$ ${abs}`;
}

function formatSignedPercent(value) {
    const abs = Math.abs(Number(value)).toFixed(2);
    const sign = Number(value) >= 0 ? '+' : '−';

    return `${sign}${abs}%`;
}

function areaColors(positive, dark) {
    if (positive) {
        return {
            lineColor: '#22c55e',
            topColor: dark ? 'rgba(34, 197, 94, 0.35)' : 'rgba(34, 197, 94, 0.28)',
            bottomColor: dark ? 'rgba(34, 197, 94, 0.02)' : 'rgba(34, 197, 94, 0.01)',
        };
    }

    return {
        lineColor: '#ef4444',
        topColor: dark ? 'rgba(239, 68, 68, 0.32)' : 'rgba(239, 68, 68, 0.24)',
        bottomColor: dark ? 'rgba(239, 68, 68, 0.02)' : 'rgba(239, 68, 68, 0.01)',
    };
}

function teardown(el) {
    if (el._vestixPriceChartCleanup) {
        el._vestixPriceChartCleanup();
        el._vestixPriceChartCleanup = null;
    }
}

function syncEntryMarker(state) {
    const { chart, areaSeries, chartHost, markerHost, marker } = state;

    if (!markerHost || !chartHost || !chart || !areaSeries) {
        return;
    }

    markerHost.innerHTML = '';

    if (!marker) {
        return;
    }

    const x = chart.timeScale().timeToCoordinate(marker.time);
    const y = areaSeries.priceToCoordinate(Number(marker.value));

    if (x === null || y === null || Number.isNaN(x) || Number.isNaN(y)) {
        return;
    }

    const width = chartHost.clientWidth;
    const height = chartHost.clientHeight;

    if (x < -12 || x > width + 12 || y < -12 || y > height + 12) {
        return;
    }

    const dot = document.createElement('div');
    dot.className = 'vestix-price-chart-entry';
    dot.style.color = marker.color;
    dot.style.transform = `translate(${Math.round(x - 5)}px, ${Math.round(y - 5)}px)`;
    dot.title = `Entry $${Number(marker.value).toFixed(2)}`;
    markerHost.appendChild(dot);
}

function applyPriceLines(state) {
    const { areaSeries, priceLines, levels } = state;

    Object.values(priceLines).forEach((line) => {
        try {
            areaSeries.removePriceLine(line);
        } catch {
            // already removed
        }
    });
    state.priceLines = {};

    const levelMeta = [
        ['entry', '#22c55e', 'Entry'],
        ['stop', '#ef4444', 'SL'],
        ['target1', '#a855f7', 'T1'],
    ];

    for (const [key, color, title] of levelMeta) {
        if (levels[key] != null) {
            state.priceLines[key] = areaSeries.createPriceLine({
                price: Number(levels[key]),
                color,
                lineWidth: 1,
                lineStyle: 2,
                axisLabelVisible: true,
                title,
            });
        }
    }
}

function updatePeriodChange(el, periodChange) {
    const host = el.querySelector('[data-price-chart-change]');

    if (!host || !periodChange) {
        return;
    }

    const positive = Boolean(periodChange.positive);
    host.textContent = `${formatSignedMoney(periodChange.absolute)} (${formatSignedPercent(periodChange.percent)})`;
    host.classList.toggle('vestix-price-chart__change--up', positive);
    host.classList.toggle('vestix-price-chart__change--down', !positive);
}

function setActiveRange(el, range) {
    el.querySelectorAll('[data-price-chart-range]').forEach((btn) => {
        const active = btn.dataset.priceChartRange === range;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}

async function loadChart(el, range = '3M') {
    const url = el.dataset.chartUrl;
    const status = el.querySelector('[data-price-chart-status]');
    const chartHost = el.querySelector('[data-price-chart]');
    const markerHost = el.querySelector('[data-price-chart-markers]');

    if (!url || !chartHost || !status) {
        return;
    }

    teardown(el);
    status.textContent = 'Koersdata laden…';
    status.hidden = false;
    status.classList.remove('text-amber-500', 'text-rose-400');
    setActiveRange(el, range);

    try {
        const response = await fetch(`${url}?range=${encodeURIComponent(range)}`, {
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();

        if (!payload?.points?.length) {
            throw new Error('Empty points');
        }

        const dark = isDarkMode();
        const positive = Boolean(payload.period_change?.positive);
        const colors = areaColors(positive, dark);

        chartHost.innerHTML = '';
        if (markerHost) {
            markerHost.innerHTML = '';
        }

        const chart = createChart(chartHost, {
            ...chartTheme(dark),
            crosshair: { mode: CrosshairMode.Normal },
            leftPriceScale: { visible: false },
            rightPriceScale: {
                borderVisible: false,
                minimumWidth: 56,
            },
            timeScale: {
                borderVisible: false,
                rightOffset: 6,
                timeVisible: Boolean(payload.intraday),
                secondsVisible: false,
            },
            height: 300,
            autoSize: true,
            width: chartHost.clientWidth || undefined,
        });

        const areaSeries = chart.addSeries(AreaSeries, {
            lineColor: colors.lineColor,
            topColor: colors.topColor,
            bottomColor: colors.bottomColor,
            lineWidth: 2,
            priceLineVisible: false,
            lastValueVisible: true,
            crosshairMarkerVisible: true,
            crosshairMarkerRadius: 4,
        });

        areaSeries.setData(payload.points);
        chart.timeScale().fitContent();

        const entryMarker = payload.markers?.find((marker) => marker.role === 'entry') ?? null;

        const state = {
            root: el,
            chart,
            areaSeries,
            chartHost,
            markerHost,
            levels: payload.levels ?? {},
            marker: entryMarker,
            priceLines: {},
        };

        applyPriceLines(state);
        updatePeriodChange(el, payload.period_change);

        status.textContent = payload.demo
            ? `${payload.ticker} · demo-data`
            : payload.intraday
                ? `${payload.ticker} · vandaag · ${payload.points.length}×5m`
                : `${payload.ticker} · ${payload.points.length} dagen`;
        status.classList.toggle('text-amber-500', Boolean(payload.demo));

        requestAnimationFrame(() => syncEntryMarker(state));

        const onRangeChange = () => syncEntryMarker(state);
        chart.timeScale().subscribeVisibleLogicalRangeChange(onRangeChange);

        const onResize = () => syncEntryMarker(state);
        const resizeObserver = typeof ResizeObserver !== 'undefined'
            ? new ResizeObserver(onResize)
            : null;
        resizeObserver?.observe(chartHost);
        window.addEventListener('resize', onResize);

        const themeObserver = new MutationObserver(() => {
            const nextDark = isDarkMode();
            chart.applyOptions(chartTheme(nextDark));
            const nextColors = areaColors(positive, nextDark);
            areaSeries.applyOptions({
                lineColor: nextColors.lineColor,
                topColor: nextColors.topColor,
                bottomColor: nextColors.bottomColor,
            });
            syncEntryMarker(state);
        });
        themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });

        el._vestixPriceChartCleanup = () => {
            themeObserver.disconnect();
            resizeObserver?.disconnect();
            window.removeEventListener('resize', onResize);
            chart.remove();
        };

        el._vestixPriceChartState = state;
    } catch (error) {
        console.error('Position price chart failed', error);
        status.textContent = 'Koersgrafiek niet beschikbaar.';
        status.classList.add('text-rose-400');
        status.hidden = false;
        updatePeriodChange(el, { absolute: 0, percent: 0, positive: true });
        const change = el.querySelector('[data-price-chart-change]');
        if (change) {
            change.textContent = '—';
            change.classList.remove('vestix-price-chart__change--up', 'vestix-price-chart__change--down');
        }
    }
}

function bindRoot(el) {
    if (el.dataset.priceChartBound === '1') {
        return;
    }

    el.dataset.priceChartBound = '1';

    const pills = el.querySelector('[data-price-chart-ranges]');
    if (pills && !pills.childElementCount) {
        RANGES.forEach(({ key, label }) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'vestix-price-chart__pill';
            btn.dataset.priceChartRange = key;
            btn.textContent = label;
            btn.setAttribute('aria-pressed', 'false');
            pills.appendChild(btn);
        });
    }

    el.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-price-chart-range]');
        if (!btn || !el.contains(btn)) {
            return;
        }

        event.preventDefault();
        loadChart(el, btn.dataset.priceChartRange || '3M');
    });

    const initial = el.dataset.initialRange || '3M';
    loadChart(el, initial);
}

function boot() {
    document.querySelectorAll('[data-position-price-chart]').forEach(bindRoot);
}

document.addEventListener('DOMContentLoaded', boot);
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:init', () => {
    Livewire.hook('morphed', () => {
        document.querySelectorAll('[data-position-price-chart]').forEach((el) => {
            if (!el.isConnected) {
                teardown(el);
            }
        });
        boot();
    });
});
