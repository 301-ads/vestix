import { createChart, ColorType, CrosshairMode, CandlestickSeries, LineSeries } from 'lightweight-charts';

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

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function teardown(el) {
    if (el._vestixReplayCleanup) {
        el._vestixReplayCleanup();
        el._vestixReplayCleanup = null;
    }
}

function indexAtTime(rows, time) {
    if (!time || !rows?.length) {
        return -1;
    }

    return rows.findIndex((row) => row.time === time);
}

function sliceThrough(rows, time) {
    const index = indexAtTime(rows, time);

    if (index < 0) {
        return rows ?? [];
    }

    return rows.slice(0, index + 1);
}

function candleAt(candles, time) {
    if (!candles?.length || !time) {
        return null;
    }

    return candles.find((candle) => candle.time === time) ?? null;
}

function createArrowElement(marker) {
    const el = document.createElement('div');
    el.className = `vestix-replay-arrow vestix-replay-arrow--${marker.direction}`;
    el.dataset.role = marker.role;
    el.dataset.time = marker.time;
    el.dataset.position = marker.position;
    el.dataset.direction = marker.direction;
    el.style.color = marker.color;
    el.style.visibility = 'hidden';

    // TradingView-style: thin stem + open chevron head (not a filled triangle block).
    const path = marker.direction === 'up'
        ? 'M8 18 V5.5 M8 5.5 L3.5 11 M8 5.5 L12.5 11'
        : 'M8 2 V14.5 M8 14.5 L3.5 9 M8 14.5 L12.5 9';

    el.innerHTML = `<svg viewBox="0 0 16 20" width="12" height="15" aria-hidden="true"><path d="${path}" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/></svg>`;

    return el;
}

function positionArrows(state) {
    const { chart, candleSeries, chartHost, arrowsHost } = state;
    const candles = state.visibleCandles ?? state.payload?.candles ?? [];

    if (!arrowsHost || !chartHost || !chart || !candleSeries) {
        return;
    }

    const width = chartHost.clientWidth;
    const height = chartHost.clientHeight;

    arrowsHost.querySelectorAll('.vestix-replay-arrow').forEach((arrow) => {
        const time = arrow.dataset.time;
        const position = arrow.dataset.position;
        const candle = candleAt(candles, time);

        if (!candle) {
            arrow.style.visibility = 'hidden';

            return;
        }

        const x = chart.timeScale().timeToCoordinate(time);
        const price = position === 'aboveBar' ? candle.high : candle.low;
        const y = candleSeries.priceToCoordinate(price);

        if (x === null || y === null || Number.isNaN(x) || Number.isNaN(y)) {
            arrow.style.visibility = 'hidden';

            return;
        }

        const offset = 4;
        const top = position === 'aboveBar' ? y - 15 - offset : y + offset;

        if (x < -20 || x > width + 20 || top < -20 || top > height + 20) {
            arrow.style.visibility = 'hidden';

            return;
        }

        arrow.style.visibility = 'visible';
        arrow.style.transform = `translate(${Math.round(x - 6)}px, ${Math.round(top)}px)`;
    });
}

function syncArrowLayer(state) {
    const { arrowsHost, revealed, markers } = state;
    if (!arrowsHost) {
        return;
    }

    arrowsHost.innerHTML = '';

    const visible = (markers ?? []).filter((marker) => marker.role === 'entry' || revealed);

    visible.forEach((marker) => {
        arrowsHost.appendChild(createArrowElement(marker));
    });

    positionArrows(state);
}

function applySeriesData(state, fogged) {
    const { candleSeries, smaSeries, rsiSeries, payload, entryTime } = state;
    const candles = fogged ? sliceThrough(payload.candles, entryTime) : payload.candles;
    const sma20 = fogged ? sliceThrough(payload.sma20 ?? [], entryTime) : (payload.sma20 ?? []);
    const rsi14 = fogged ? sliceThrough(payload.rsi14 ?? [], entryTime) : (payload.rsi14 ?? []);

    candleSeries.setData(candles);
    smaSeries.setData(sma20);
    rsiSeries.setData(rsi14);
    state.visibleCandles = candles;
}

function applyPriceLines(state, includeExit) {
    const { candleSeries, priceLines, levels } = state;

    Object.values(priceLines).forEach((line) => {
        try {
            candleSeries.removePriceLine(line);
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

    if (includeExit) {
        levelMeta.push(['exit', '#f59e0b', 'Exit']);
    }

    for (const [key, color, title] of levelMeta) {
        if (levels[key] != null) {
            state.priceLines[key] = candleSeries.createPriceLine({
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

function setRevealOnlyVisible(el, visible) {
    const stack = el.closest('.vestix-replay-stack') ?? el.parentElement;
    stack?.querySelectorAll('[data-reveal-only]').forEach((node) => {
        if (visible) {
            node.hidden = false;
        } else {
            node.hidden = true;
        }
    });
}

function revealOutcome(state) {
    if (state.revealed) {
        return;
    }

    state.revealed = true;
    applySeriesData(state, false);
    applyPriceLines(state, true);
    syncArrowLayer(state);
    state.chart.timeScale().fitContent();
    state.rsiChart.timeScale().fitContent();
    alignPaneWidths(state.chart, state.rsiChart);
    const range = state.chart.timeScale().getVisibleLogicalRange();
    if (range) {
        state.rsiChart.timeScale().setVisibleLogicalRange(range);
    }
    setRevealOnlyVisible(state.root, true);

    if (state.revealHost) {
        state.revealHost.hidden = true;
    }

    const exit = state.levels.exit;
    state.status.textContent = state.payload.demo
        ? `${state.payload.ticker} · uitkomst onthuld (demo)`
        : `${state.payload.ticker} · uitkomst onthuld${exit != null ? ` · exit $${Number(exit).toFixed(2)}` : ''}`;
    state.status.classList.remove('text-amber-500');

    if (state.legend) {
        state.legend.textContent = 'Groene ▲ = entry · Rode ▼ = exit. Stippellijnen = Entry / SL / T1 / Exit.';
    }
}

async function loadReplay(el) {
    const positionId = el.dataset.positionId;
    const url = el.dataset.replayUrl;

    if (!positionId || !url) {
        return;
    }

    const chartHost = el.querySelector('[data-replay-chart]');
    const arrowsHost = el.querySelector('[data-replay-arrows]');
    const rsiHost = el.querySelector('[data-replay-rsi]');
    const status = el.querySelector('[data-replay-status]');
    const revealBtn = el.querySelector('[data-replay-reveal]');
    const revealHost = el.querySelector('[data-replay-reveal-host]');
    const legend = el.querySelector('[data-replay-legend]');

    if (!chartHost || !rsiHost || !status) {
        return;
    }

    teardown(el);
    status.textContent = 'Koersdata laden…';
    status.classList.remove('text-amber-500', 'text-rose-400');
    setRevealOnlyVisible(el, false);
    if (revealHost) {
        revealHost.hidden = false;
    }

    try {
        const response = await fetch(url, {
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

        if (!payload?.candles?.length) {
            throw new Error('Empty candles');
        }

        const entryTime = payload.entry_time
            ?? payload.markers?.find((marker) => marker.role === 'entry')?.time
            ?? null;

        const hasFog = entryTime != null && indexAtTime(payload.candles, entryTime) >= 0;

        status.textContent = payload.demo
            ? `${payload.ticker} · demo-data · setup tot entry`
            : hasFog
                ? `${payload.ticker} · Fog of War · beoordeel de setup tot je entry`
                : `${payload.ticker} · ${payload.candles.length} bars · SMA-20 + RSI(14)`;

        if (payload.demo) {
            status.classList.add('text-amber-500');
        }

        chartHost.innerHTML = '';
        rsiHost.innerHTML = '';
        if (arrowsHost) {
            arrowsHost.innerHTML = '';
        }

        const isDark = document.documentElement.classList.contains('dark');
        const priceScaleOptions = {
            borderVisible: false,
            minimumWidth: 72,
        };

        const chart = createChart(chartHost, {
            layout: {
                background: {
                    type: ColorType.Solid,
                    color: isDark ? '#09090b' : '#fafafa',
                },
                textColor: isDark ? '#a1a1aa' : '#71717a',
            },
            grid: {
                vertLines: { color: isDark ? 'rgba(148,163,184,0.08)' : 'rgba(113,113,122,0.12)' },
                horzLines: { color: isDark ? 'rgba(148,163,184,0.08)' : 'rgba(113,113,122,0.12)' },
            },
            crosshair: { mode: CrosshairMode.Normal },
            leftPriceScale: { visible: false },
            rightPriceScale: priceScaleOptions,
            timeScale: { borderVisible: false },
            height: 420,
            autoSize: true,
            width: chartHost.clientWidth || undefined,
        });

        const candleSeries = chart.addSeries(CandlestickSeries, {
            upColor: '#22c55e',
            downColor: '#ef4444',
            borderVisible: false,
            wickUpColor: '#22c55e',
            wickDownColor: '#ef4444',
        });

        const smaSeries = chart.addSeries(LineSeries, {
            color: '#3b82f6',
            lineWidth: 2,
            title: 'SMA-20',
            priceLineVisible: false,
            lastValueVisible: true,
        });

        const rsiChart = createChart(rsiHost, {
            layout: {
                background: {
                    type: ColorType.Solid,
                    color: isDark ? '#09090b' : '#fafafa',
                },
                textColor: isDark ? '#a1a1aa' : '#71717a',
            },
            grid: {
                vertLines: { color: isDark ? 'rgba(148,163,184,0.06)' : 'rgba(113,113,122,0.1)' },
                horzLines: { color: isDark ? 'rgba(148,163,184,0.06)' : 'rgba(113,113,122,0.1)' },
            },
            leftPriceScale: { visible: false },
            rightPriceScale: { ...priceScaleOptions },
            timeScale: { borderVisible: false },
            height: 140,
            autoSize: true,
            width: chartHost.clientWidth || rsiHost.clientWidth || undefined,
        });

        const rsiSeries = rsiChart.addSeries(LineSeries, {
            color: '#f59e0b',
            lineWidth: 2,
            title: 'RSI(14)',
            priceLineVisible: false,
        });
        rsiSeries.createPriceLine({ price: 70, color: '#ef4444', lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: '70' });
        rsiSeries.createPriceLine({ price: 30, color: '#22c55e', lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: '30' });

        const state = {
            root: el,
            payload,
            entryTime,
            levels: payload.levels ?? {},
            markers: payload.markers ?? [],
            chart,
            rsiChart,
            candleSeries,
            smaSeries,
            rsiSeries,
            chartHost,
            arrowsHost,
            status,
            legend,
            revealHost,
            priceLines: {},
            revealed: !hasFog,
            visibleCandles: payload.candles,
        };

        applySeriesData(state, hasFog);
        applyPriceLines(state, !hasFog);
        syncArrowLayer(state);

        chart.timeScale().fitContent();
        rsiChart.timeScale().fitContent();
        alignPaneWidths(chart, rsiChart);

        // Keep panes locked after scale labels settle.
        requestAnimationFrame(() => {
            alignPaneWidths(chart, rsiChart);
            const range = chart.timeScale().getVisibleLogicalRange();
            if (range) {
                rsiChart.timeScale().setVisibleLogicalRange(range);
            }
            positionArrows(state);
        });

        if (!hasFog) {
            setRevealOnlyVisible(el, true);
            if (revealHost) {
                revealHost.hidden = true;
            }
        }

        const syncRange = (range) => {
            if (range) {
                rsiChart.timeScale().setVisibleLogicalRange(range);
            }
            positionArrows(state);
        };
        chart.timeScale().subscribeVisibleLogicalRangeChange(syncRange);

        const onResize = () => {
            alignPaneWidths(chart, rsiChart);
            positionArrows(state);
        };
        const resizeObserver = typeof ResizeObserver !== 'undefined'
            ? new ResizeObserver(onResize)
            : null;
        resizeObserver?.observe(chartHost);
        resizeObserver?.observe(rsiHost);
        window.addEventListener('resize', onResize);

        const onReveal = (event) => {
            event.preventDefault();
            event.stopPropagation();
            revealOutcome(state);
        };
        revealBtn?.addEventListener('click', onReveal);

        // Reposition after first paint (autoSize may settle late).
        requestAnimationFrame(() => positionArrows(state));
        setTimeout(() => {
            alignPaneWidths(chart, rsiChart);
            positionArrows(state);
        }, 50);

        el._vestixReplayCleanup = () => {
            chart.timeScale().unsubscribeVisibleLogicalRangeChange(syncRange);
            revealBtn?.removeEventListener('click', onReveal);
            window.removeEventListener('resize', onResize);
            resizeObserver?.disconnect();
            chart.remove();
            rsiChart.remove();
            if (arrowsHost) {
                arrowsHost.innerHTML = '';
            }
        };
    } catch (error) {
        teardown(el);
        chartHost.innerHTML = '';
        rsiHost.innerHTML = '';
        if (arrowsHost) {
            arrowsHost.innerHTML = '';
        }
        if (revealHost) {
            revealHost.hidden = true;
        }
        status.textContent = 'Replay niet beschikbaar (geen historische data).';
        status.classList.add('text-rose-400');
        console.warn('Trade replay failed', error);
    }
}

function boot() {
    document.querySelectorAll('[data-trade-replay]').forEach((el) => {
        if (el.dataset.replayLoading === '1') {
            return;
        }

        const signature = `${el.dataset.positionId}:${el.dataset.replayUrl}`;
        if (el.dataset.replaySignature === signature && el.dataset.replayReady === '1') {
            return;
        }

        el.dataset.replaySignature = signature;
        el.dataset.replayLoading = '1';
        loadReplay(el).finally(() => {
            el.dataset.replayLoading = '0';
            el.dataset.replayReady = '1';
        });
    });
}

document.addEventListener('DOMContentLoaded', boot);
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:init', () => {
    Livewire.hook('morphed', () => {
        document.querySelectorAll('[data-trade-replay]').forEach((el) => {
            if (!el.isConnected) {
                teardown(el);
            }
        });
        boot();
    });
});
