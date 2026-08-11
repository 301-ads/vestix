@if ($record instanceof \App\Models\Position && $record->status === 'open')
    <div
        data-position-price-chart
        data-position-id="{{ $record->id }}"
        data-chart-url="{{ route('positions.price-chart', $record) }}"
        data-initial-range="3M"
        class="vestix-price-chart space-y-3"
    >
        <div class="vestix-price-chart__header flex flex-wrap items-end justify-between gap-2">
            <div>
                <p class="vestix-price-chart__label text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Koers
                </p>
                <p
                    data-price-chart-change
                    class="vestix-price-chart__change text-sm font-semibold tabular-nums"
                >
                    —
                </p>
            </div>
            <p data-price-chart-status class="text-xs text-gray-500 dark:text-gray-400">
                Koersdata laden…
            </p>
        </div>

        <div class="vestix-price-chart__wrap relative w-full">
            <div data-price-chart class="w-full overflow-hidden" style="min-height: 300px;"></div>
            <div
                data-price-chart-markers
                class="vestix-price-chart__markers pointer-events-none absolute inset-0 overflow-hidden"
                aria-hidden="true"
            ></div>
        </div>

        <div class="vestix-price-chart__footer flex flex-wrap items-center justify-between gap-2">
            <div data-price-chart-ranges class="vestix-price-chart__ranges" role="group" aria-label="Timeframe"></div>
            <p class="text-[11px] text-gray-500 dark:text-gray-400">
                1D = Yahoo 5m (gratis) · overige = dagkoersen · punt = entry · lijnen = Entry / SL / T1
            </p>
        </div>
    </div>
@endif
