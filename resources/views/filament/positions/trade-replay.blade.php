@if ($record instanceof \App\Models\Position)
    <div
        data-trade-replay
        data-position-id="{{ $record->id }}"
        data-replay-url="{{ route('positions.trade-replay', $record) }}"
        class="vestix-trade-replay space-y-2"
    >
        <p data-replay-status class="text-sm text-gray-500 dark:text-gray-400">Replay voorbereiden…</p>
        <div data-replay-chart class="w-full rounded-lg border border-gray-200 dark:border-white/10" style="min-height: 320px;"></div>
        <div data-replay-rsi class="w-full rounded-lg border border-gray-200 dark:border-white/10" style="min-height: 120px;"></div>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Levende replay: SMA-20 (blauw) en RSI(14) standaard zichtbaar. Markers voor entry/exit; lijnen voor SL en Target 1.
        </p>
    </div>
@endif
