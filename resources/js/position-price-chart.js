import { createChart, ColorType, CrosshairMode, AreaSeries, CandlestickSeries } from 'lightweight-charts';

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

function lineValueAt(points, time) {
    if (!points?.length || time == null) {
        return null;
    }

    const match = points.find((point) => point.time === time || String(point.time) === String(time));

    return match != null ? Number(match.value) : null;
}

function candleCloseAt(candles, time) {
    if (!candles?.length || time == null) {
        return null;
    }

    const match = candles.find((candle) => candle.time === time || String(candle.time) === String(time));

    return match != null ? Number(match.close) : null;
}

function syncEntryMarker(state) {
    const { chart, primarySeries, chartHost, markerHost, marker, points, candles, seriesType } = state;

    if (!markerHost || !chartHost || !chart || !primarySeries) {
        return;
    }

    markerHost.innerHTML = '';

    if (!marker) {
        return;
    }

    const lineValue = seriesType === 'candles'
        ? candleCloseAt(candles, marker.time)
        : lineValueAt(points, marker.time);

    if (lineValue == null || Number.isNaN(lineValue)) {
        return;
    }

    const x = chart.timeScale().timeToCoordinate(marker.time);
    const y = primarySeries.priceToCoordinate(lineValue);

    if (x === null || y === null || Number.isNaN(x) || Number.isNaN(y)) {
        return;
    }

    const width = chartHost.clientWidth;
    const height = chartHost.clientHeight;

    if (x < -12 || x > width + 12 || y < -12 || y > height + 12) {
        return;
    }

    const size = 9;
    const dot = document.createElement('div');
    dot.className = 'vestix-price-chart-entry';
    dot.style.color = marker.color;
    dot.style.width = `${size}px`;
    dot.style.height = `${size}px`;
    dot.style.transform = `translate(${Math.round(x - size / 2)}px, ${Math.round(y - size / 2)}px)`;
    const label = marker.role === 'signal' ? 'Signaal' : 'Entry';
    dot.title = `${label} $${Number(marker.value).toFixed(2)}`;
    markerHost.appendChild(dot);
}

function applyPriceLines(state) {
    const { primarySeries, priceLines, levels } = state;

    Object.values(priceLines).forEach((line) => {
        try {
            primarySeries.removePriceLine(line);
        } catch {
            // already removed
        }
    });
    state.priceLines = {};

    const levelMeta = [
        ['entry', '#22c55e', 'Entry'],
        ['stop', '#ef4444', 'SL'],
        ['target1', '#a855f7', 'T1'],
        ['signal_high', '#38bdf8', 'H'],
        ['signal_low', '#38bdf8', 'L'],
        ['sma20', '#3b82f6', 'SMA20'],
    ];

    for (const [key, color, title] of levelMeta) {
        if (levels[key] != null) {
            state.priceLines[key] = primarySeries.createPriceLine({
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

function updatePremarketStatus(el, premarket) {
    const host = el.querySelector('[data-price-chart-premarket]');

    if (!host) {
        return;
    }

    if (!premarket) {
        host.hidden = true;
        host.textContent = '';
        return;
    }

    const parts = [];

    if (premarket.price != null) {
        parts.push(`PM $${Number(premarket.price).toFixed(2)}`);
    }

    if (premarket.label) {
        parts.push(premarket.label);
    }

    if (premarket.description) {
        parts.push(premarket.description);
    }

    host.textContent = parts.join(' · ');
    host.hidden = parts.length === 0;
    host.classList.remove(
        'text-rose-400',
        'text-emerald-500',
        'text-amber-500',
        'text-gray-500',
        'dark:text-gray-400',
    );

    const toneClass = {
        danger: 'text-rose-400',
        success: 'text-emerald-500',
        warning: 'text-amber-500',
        gray: 'text-gray-500 dark:text-gray-400',
    }[premarket.tone || 'gray'] || 'text-gray-500 dark:text-gray-400';

    toneClass.split(' ').forEach((cls) => host.classList.add(cls));
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
        const seriesType = payload.series === 'candles' ? 'candles' : 'area';
        const hasPoints = payload?.points?.length >= 2;
        const hasCandles = payload?.candles?.length >= 2;

        if (seriesType === 'candles' ? !hasCandles : !hasPoints) {
            throw new Error('Empty series');
        }

        const dark = isDarkMode();
        const positive = Boolean(payload.period_change?.positive);
        const colors = areaColors(positive, dark);

        chartHost.innerHTML = '';
        if (markerHost) {
            markerHost.innerHTML = '';
        }

        const theme = chartTheme(dark);
        const chart = createChart(chartHost, {
            ...theme,
            layout: {
                ...theme.layout,
                attributionLogo: false,
            },
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

        let primarySeries;

        if (seriesType === 'candles') {
            primarySeries = chart.addSeries(CandlestickSeries, {
                upColor: '#22c55e',
                downColor: '#ef4444',
                borderVisible: false,
                wickUpColor: '#22c55e',
                wickDownColor: '#ef4444',
                priceLineVisible: false,
                lastValueVisible: true,
            });
            primarySeries.setData(payload.candles);
        } else {
            primarySeries = chart.addSeries(AreaSeries, {
                lineColor: colors.lineColor,
                topColor: colors.topColor,
                bottomColor: colors.bottomColor,
                lineWidth: 2,
                priceLineVisible: false,
                lastValueVisible: true,
                crosshairMarkerVisible: true,
                crosshairMarkerRadius: 4,
            });
            primarySeries.setData(payload.points);
        }

        chart.timeScale().fitContent();

        const entryMarker = payload.markers?.find((marker) => marker.role === 'entry' || marker.role === 'signal') ?? null;

        const state = {
            root: el,
            chart,
            primarySeries,
            chartHost,
            markerHost,
            points: payload.points ?? [],
            candles: payload.candles ?? [],
            seriesType,
            levels: payload.levels ?? {},
            marker: entryMarker,
            priceLines: {},
        };

        applyPriceLines(state);
        updatePeriodChange(el, payload.period_change);
        updatePremarketStatus(el, payload.premarket);

        status.textContent = payload.demo
            ? `${payload.ticker} · demo-data`
            : payload.intraday
                ? `${payload.ticker} · vandaag · ${(payload.candles ?? payload.points).length}×5m`
                : `${payload.ticker} · ${(payload.candles ?? payload.points).length} dagen`;
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
            if (seriesType === 'area') {
                const nextColors = areaColors(positive, nextDark);
                primarySeries.applyOptions({
                    lineColor: nextColors.lineColor,
                    topColor: nextColors.topColor,
                    bottomColor: nextColors.bottomColor,
                });
            }
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
        updatePremarketStatus(el, null);
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
