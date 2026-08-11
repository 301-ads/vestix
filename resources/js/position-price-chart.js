import { createChart, ColorType, CrosshairMode, AreaSeries, CandlestickSeries, LineSeries } from 'lightweight-charts';

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

function rsiChartTheme(dark = isDarkMode()) {
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

function alignPaneWidths(chart, rsiChart) {
    const minWidth = 72;
    const width = Math.max(
        chart.priceScale('right').width(),
        rsiChart.priceScale('right').width(),
        minWidth,
    );

    chart.priceScale('right').applyOptions({ minimumWidth: width });
    rsiChart.priceScale('right').applyOptions({ minimumWidth: width });
}

function alignSeriesToCandles(candles, seriesRows) {
    const byTime = new Map((seriesRows ?? []).map((row) => [row.time, row]));

    return candles.map((candle) => {
        const row = byTime.get(candle.time);

        return row ?? { time: candle.time };
    });
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

function syncEntryMarker(state) {
    const { chart, primarySeries, chartHost, markerHost, marker, points } = state;

    if (!markerHost || !chartHost || !chart || !primarySeries) {
        return;
    }

    markerHost.innerHTML = '';

    if (!marker) {
        return;
    }

    const lineValue = lineValueAt(points, marker.time);

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
    dot.title = `Entry $${Number(marker.value).toFixed(2)}`;
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

function setRsiPaneVisible(el, visible) {
    const label = el.querySelector('[data-price-chart-rsi-label]');
    const wrap = el.querySelector('[data-price-chart-rsi-wrap]');

    if (label) {
        label.hidden = !visible;
    }

    if (wrap) {
        wrap.hidden = !visible;
    }
}

/**
 * Short ranges (1W/1M) have few daily bars — fitContent stretches them into fat blocks.
 * Keep a fixed viewport (~44 slots) so candles stay readable and left-aligned.
 */
function fitPriceChartTimeScale(chart, barCount, rightOffset = 8) {
    if (!chart || barCount < 2) {
        return;
    }

    chart.timeScale().applyOptions({ rightOffset });

    if (barCount < 40) {
        const viewport = Math.max(barCount + 4, 44);
        chart.timeScale().setVisibleLogicalRange({
            from: 0,
            to: viewport - 1,
        });
        return;
    }

    chart.timeScale().fitContent();
}

function syncTimeScales(state) {
    const { chart, rsiChart, candles, points, seriesType } = state;

    if (!chart) {
        return;
    }

    const barCount = seriesType === 'candles'
        ? (candles?.length ?? 0)
        : (points?.length ?? 0);
    const rightOffset = rsiChart ? 8 : 6;

    fitPriceChartTimeScale(chart, barCount, rightOffset);

    if (rsiChart) {
        rsiChart.timeScale().applyOptions({ rightOffset });
        const range = chart.timeScale().getVisibleLogicalRange();

        if (range) {
            rsiChart.timeScale().setVisibleLogicalRange(range);
        }

        alignPaneWidths(chart, rsiChart);
    }
}

async function loadChart(el, range = '3M') {
    const url = el.dataset.chartUrl;
    const status = el.querySelector('[data-price-chart-status]');
    const chartHost = el.querySelector('[data-price-chart]');
    const markerHost = el.querySelector('[data-price-chart-markers]');
    const rsiHost = el.querySelector('[data-price-chart-rsi]');

    if (!url || !chartHost || !status) {
        return;
    }

    teardown(el);
    status.textContent = 'Koersdata laden…';
    status.hidden = false;
    status.classList.remove('text-amber-500', 'text-rose-400');
    setActiveRange(el, range);
    setRsiPaneVisible(el, false);

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
        const showIndicators = seriesType === 'candles'
            && !payload.intraday
            && ((payload.sma20?.length ?? 0) > 0 || (payload.rsi14?.length ?? 0) > 0);

        chartHost.innerHTML = '';
        if (markerHost) {
            markerHost.innerHTML = '';
        }
        if (rsiHost) {
            rsiHost.innerHTML = '';
        }

        const priceScaleOptions = {
            borderVisible: false,
            minimumWidth: showIndicators ? 72 : 56,
        };
        const theme = chartTheme(dark);
        const chart = createChart(chartHost, {
            ...theme,
            layout: {
                ...theme.layout,
                attributionLogo: false,
            },
            crosshair: { mode: CrosshairMode.Normal },
            leftPriceScale: { visible: false },
            rightPriceScale: priceScaleOptions,
            timeScale: {
                borderVisible: false,
                rightOffset: showIndicators ? 10 : 6,
                timeVisible: Boolean(payload.intraday),
                secondsVisible: false,
            },
            height: 300,
            autoSize: true,
            width: chartHost.clientWidth || undefined,
        });

        let primarySeries;
        let smaSeries = null;
        let rsiChart = null;
        let rsiSeries = null;

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

            if (showIndicators && (payload.sma20?.length ?? 0) > 0) {
                smaSeries = chart.addSeries(LineSeries, {
                    color: '#3b82f6',
                    lineWidth: 2,
                    title: 'SMA-20',
                    priceLineVisible: false,
                    lastValueVisible: true,
                });
                smaSeries.setData(alignSeriesToCandles(payload.candles, payload.sma20));
            }

            if (showIndicators && rsiHost && (payload.rsi14?.length ?? 0) > 0) {
                setRsiPaneVisible(el, true);
                const rsiTheme = rsiChartTheme(dark);
                rsiChart = createChart(rsiHost, {
                    ...rsiTheme,
                    layout: {
                        ...rsiTheme.layout,
                        attributionLogo: false,
                    },
                    leftPriceScale: { visible: false },
                    rightPriceScale: { ...priceScaleOptions },
                    timeScale: {
                        borderVisible: false,
                        rightOffset: 10,
                        timeVisible: false,
                        secondsVisible: false,
                    },
                    height: 120,
                    autoSize: true,
                    width: chartHost.clientWidth || rsiHost.clientWidth || undefined,
                });

                rsiSeries = rsiChart.addSeries(LineSeries, {
                    color: '#f59e0b',
                    lineWidth: 2,
                    title: 'RSI(14)',
                    priceLineVisible: false,
                });
                rsiSeries.createPriceLine({
                    price: 70,
                    color: '#ef4444',
                    lineWidth: 1,
                    lineStyle: 2,
                    axisLabelVisible: true,
                    title: '70',
                });
                rsiSeries.createPriceLine({
                    price: 30,
                    color: '#22c55e',
                    lineWidth: 1,
                    lineStyle: 2,
                    axisLabelVisible: true,
                    title: '30',
                });
                rsiSeries.setData(alignSeriesToCandles(payload.candles, payload.rsi14));
            }
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

        // Scout candles: no fill marker (entry is still a planned buy-stop line only).
        const entryMarker = seriesType === 'candles'
            ? null
            : (payload.markers?.find((marker) => marker.role === 'entry') ?? null);

        const state = {
            root: el,
            chart,
            rsiChart,
            primarySeries,
            smaSeries,
            rsiSeries,
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

        syncTimeScales(state);
        requestAnimationFrame(() => {
            syncTimeScales(state);
            syncEntryMarker(state);
        });

        status.textContent = payload.demo
            ? `${payload.ticker} · demo-data`
            : payload.intraday
                ? `${payload.ticker} · vandaag · ${(payload.candles ?? payload.points).length}×5m`
                : `${payload.ticker} · ${(payload.candles ?? payload.points).length} dagen`;
        status.classList.toggle('text-amber-500', Boolean(payload.demo));

        requestAnimationFrame(() => syncEntryMarker(state));

        const onRangeChange = (visibleRange) => {
            if (rsiChart && visibleRange) {
                rsiChart.timeScale().setVisibleLogicalRange(visibleRange);
            }
            syncEntryMarker(state);
        };
        chart.timeScale().subscribeVisibleLogicalRangeChange(onRangeChange);

        const onResize = () => {
            if (rsiChart) {
                alignPaneWidths(chart, rsiChart);
            }
            syncEntryMarker(state);
        };
        const resizeObserver = typeof ResizeObserver !== 'undefined'
            ? new ResizeObserver(onResize)
            : null;
        resizeObserver?.observe(chartHost);
        if (rsiHost) {
            resizeObserver?.observe(rsiHost);
        }
        window.addEventListener('resize', onResize);

        const themeObserver = new MutationObserver(() => {
            const nextDark = isDarkMode();
            chart.applyOptions(chartTheme(nextDark));
            if (rsiChart) {
                rsiChart.applyOptions(rsiChartTheme(nextDark));
            }
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
            rsiChart?.remove();
        };

        el._vestixPriceChartState = state;
    } catch (error) {
        console.error('Position price chart failed', error);
        status.textContent = 'Koersgrafiek niet beschikbaar.';
        status.classList.add('text-rose-400');
        status.hidden = false;
        setRsiPaneVisible(el, false);
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
