import { createChart, ColorType, CrosshairMode, CandlestickSeries, LineSeries } from 'lightweight-charts';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
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

    if (!chartHost || !rsiHost) {
        return;
    }

    status.textContent = 'Koersdata laden…';

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
        status.textContent = `${payload.ticker} · ${payload.candles.length} bars · SMA-20 + RSI(14)`;

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
            height: 320,
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
        });
        smaSeries.setData(payload.sma20);

        if (payload.markers?.length) {
            candleSeries.setMarkers(payload.markers);
        }

        const levels = payload.levels ?? {};
        for (const [key, color] of [
            ['entry', '#22c55e'],
            ['stop', '#ef4444'],
            ['target1', '#a855f7'],
            ['exit', '#f59e0b'],
        ]) {
            if (levels[key] != null) {
                candleSeries.createPriceLine({
                    price: levels[key],
                    color,
                    lineWidth: 1,
                    lineStyle: 2,
                    axisLabelVisible: true,
                    title: key,
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
            height: 120,
        });

        const rsiSeries = rsiChart.addSeries(LineSeries, {
            color: '#f59e0b',
            lineWidth: 2,
            title: 'RSI(14)',
        });
        rsiSeries.setData(payload.rsi14);
        rsiSeries.createPriceLine({ price: 70, color: '#ef4444', lineWidth: 1, lineStyle: 2, axisLabelVisible: false });
        rsiSeries.createPriceLine({ price: 30, color: '#22c55e', lineWidth: 1, lineStyle: 2, axisLabelVisible: false });

        chart.timeScale().fitContent();
        rsiChart.timeScale().fitContent();

        chart.timeScale().subscribeVisibleLogicalRangeChange((range) => {
            if (range) {
                rsiChart.timeScale().setVisibleLogicalRange(range);
            }
        });

        const resize = () => {
            chart.applyOptions({ width: chartHost.clientWidth });
            rsiChart.applyOptions({ width: rsiHost.clientWidth });
        };
        resize();
        window.addEventListener('resize', resize);
    } catch (error) {
        status.textContent = 'Replay niet beschikbaar (geen historische data).';
        console.warn('Trade replay failed', error);
    }
}

function boot() {
    document.querySelectorAll('[data-trade-replay]').forEach((el) => {
        if (el.dataset.replayBooted === '1') {
            return;
        }
        el.dataset.replayBooted = '1';
        loadReplay(el);
    });
}

document.addEventListener('DOMContentLoaded', boot);
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:init', () => {
    Livewire.hook('morphed', () => boot());
});
