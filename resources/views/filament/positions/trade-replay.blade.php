@if ($record instanceof \App\Models\Position)
    <div
        data-trade-replay
        data-position-id="{{ $record->id }}"
        data-replay-url="{{ route('positions.trade-replay', $record) }}"
        class="vestix-trade-replay space-y-2"
    >
        <p data-replay-status class="text-sm text-gray-500 dark:text-gray-400">Replay voorbereiden…</p>
        <div data-replay-chart class="w-full overflow-hidden rounded-lg border border-gray-200 dark:border-white/10" style="min-height: 280px;"></div>
        <div class="flex items-center gap-2">
            <span class="text-[10px] uppercase tracking-wide text-gray-500">RSI(14)</span>
            <span class="h-px flex-1 bg-gray-200 dark:bg-white/10"></span>
        </div>
        <div data-replay-rsi class="w-full overflow-hidden rounded-lg border border-gray-200 dark:border-white/10" style="min-height: 110px;"></div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Subtiele pijl = entry-fill (buy/sell-stop dag) · stip = exit. Stippellijnen = Entry / SL / T1.
        </p>
    </div>
@endif
