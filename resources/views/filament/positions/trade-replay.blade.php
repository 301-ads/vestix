@if ($record instanceof \App\Models\Position)
    <div class="vestix-replay-stack space-y-4">
        <div
            data-trade-replay
            data-position-id="{{ $record->id }}"
            data-replay-url="{{ route('positions.trade-replay', $record) }}"
            class="vestix-trade-replay space-y-3"
        >
            <p data-replay-status class="text-sm text-gray-500 dark:text-gray-400">Replay voorbereiden…</p>

            <div data-replay-chart-wrap class="vestix-replay-chart-wrap relative w-full">
                <div data-replay-chart class="w-full overflow-hidden rounded-lg" style="min-height: 420px;"></div>
                <div data-replay-arrows class="vestix-replay-arrows pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true"></div>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-[10px] uppercase tracking-wide text-gray-500">RSI(14)</span>
                <span class="h-px flex-1 bg-gray-200/80 dark:bg-white/10"></span>
            </div>
            <div data-replay-rsi class="w-full overflow-hidden rounded-lg" style="min-height: 140px;"></div>

            <div data-replay-reveal-host class="vestix-replay-reveal-host">
                <button
                    type="button"
                    data-replay-reveal
                    class="vestix-replay-reveal-btn fi-btn fi-btn-color-primary fi-size-md w-full justify-center sm:w-auto"
                >
                    Onthul Uitkomst
                </button>
            </div>

            <p data-replay-legend class="text-xs text-gray-500 dark:text-gray-400">
                Fog of War: alleen tot je entry. Groene ▲ = entry · Rode ▼ = exit (na onthullen). Stippellijnen = Entry / SL / T1.
            </p>
        </div>

        <div data-reveal-only hidden>
            @include('filament.positions.what-if-simulator', ['record' => $record, 'collapsed' => true])
        </div>
    </div>
@endif
