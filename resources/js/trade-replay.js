import { createChart, ColorType, CrosshairMode, CandlestickSeries, LineSeries, createSeriesMarkers } from 'lightweight-charts';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function teardown(el) {
    if (el._vestixReplayCleanup) {
        el._vestixReplayCleanup();
        el._vestixReplayCleanup = null;
    }
}

async function loadReplay(el) {
    const positionId = el.dataset.positionId;
    const url = el.dataset.replayUrl;

    if (!positionId || !url) {
        return;
    }

    const chartHost = el.querySelector('[data-replay-chart]');
    const rsiHost = el.querySelector('[data-replay-rsi]');
    const status = el.querySelector('[data-replay-status]');

    if (!chartHost || !rsiHost || !status) {
        return;
    }

    teardown(el);
    status.textContent = 'Koersdata laden…';
    status.classList.remove('text-amber-500', 'text-rose-400');

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

        status.textContent = payload.demo
            ? `${payload.ticker} · demo-data (API leeg) · SMA-20 + RSI(14)`
            : `${payload.ticker} · ${payload.candles.length} bars · SMA-20 + RSI(14)`;

        if (payload.demo) {
            status.classList.add('text-amber-500');
        }

        chartHost.innerHTML = '';
        rsiHost.innerHTML = '';

        const chart = createChart(chartHost, {
            layout: {
                background: { type: ColorType.Solid, color: 'transparent' },
                textColor: '#94a3b8',
            },
            grid: {
                vertLines: { color: 'rgba(148,163,184,0.12)' },
                horzLines: { color: 'rgba(148,163,184,0.12)' },
            },
            crosshair: { mode: CrosshairMode.Normal },
            rightPriceScale: { borderVisible: false },
            timeScale: { borderVisible: false },
            height: 280,
            autoSize: true,
        });

        const candleSeries = chart.addSeries(CandlestickSeries, {
            upColor: '#22c55e',
            downColor: '#ef4444',
            borderVisible: false,
            wickUpColor: '#22c55e',
            wickDownColor: '#ef4444',
        });
        candleSeries.setData(payload.candles);

        const smaSeries = chart.addSeries(LineSeries, {
            color: '#3b82f6',
            lineWidth: 2,
            title: 'SMA-20',
            priceLineVisible: false,
            lastValueVisible: true,
        });
        smaSeries.setData(payload.sma20 ?? []);

        if (payload.markers?.length) {
            createSeriesMarkers(candleSeries, payload.markers);
        }

        const levels = payload.levels ?? {};
        const levelMeta = [
            ['entry', '#22c55e', 'Entry'],
            ['stop', '#ef4444', 'SL'],
            ['target1', '#a855f7', 'T1'],
            ['exit', '#f59e0b', 'Exit'],
        ];

        for (const [key, color, title] of levelMeta) {
            if (levels[key] != null) {
                candleSeries.createPriceLine({
                    price: Number(levels[key]),
                    color,
                    lineWidth: 1,
                    lineStyle: 2,
                    axisLabelVisible: true,
                    title,
                });
            }
        }

        const rsiChart = createChart(rsiHost, {
            layout: {
                background: { type: ColorType.Solid, color: 'transparent' },
                textColor: '#94a3b8',
            },
            grid: {
                vertLines: { color: 'rgba(148,163,184,0.08)' },
                horzLines: { color: 'rgba(148,163,184,0.08)' },
            },
            rightPriceScale: { borderVisible: false },
            timeScale: { borderVisible: false },
            height: 110,
            autoSize: true,
        });

        const rsiSeries = rsiChart.addSeries(LineSeries, {
            color: '#f59e0b',
            lineWidth: 2,
            title: 'RSI(14)',
            priceLineVisible: false,
        });
        rsiSeries.setData(payload.rsi14 ?? []);
        rsiSeries.createPriceLine({ price: 70, color: '#ef4444', lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: '70' });
        rsiSeries.createPriceLine({ price: 30, color: '#22c55e', lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: '30' });

        chart.timeScale().fitContent();
        rsiChart.timeScale().fitContent();

        const syncRange = (range) => {
            if (range) {
                rsiChart.timeScale().setVisibleLogicalRange(range);
            }
        };
        chart.timeScale().subscribeVisibleLogicalRangeChange(syncRange);

        el._vestixReplayCleanup = () => {
            chart.timeScale().unsubscribeVisibleLogicalRangeChange(syncRange);
            chart.remove();
            rsiChart.remove();
        };
    } catch (error) {
        status.textContent = 'Replay niet beschikbaar (geen historische data).';
        status.classList.add('text-rose-400');
        console.warn('Trade replay failed', error);
    }
}

function boot() {
    document.querySelectorAll('[data-trade-replay]').forEach((el) => {
        // Livewire remorphs replace nodes; always (re)load on each boot pass for fresh nodes.
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
        // Allow remorphed nodes (without ready flag) to boot; clear stale charts first.
        document.querySelectorAll('[data-trade-replay]').forEach((el) => {
            if (!el.isConnected) {
                teardown(el);
            }
        });
        boot();
    });
});
