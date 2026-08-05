@php
    $result = null;
    if ($record instanceof \App\Models\Position && $record->status === 'closed') {
        $result = app(\App\Services\WhatIfSimulatorService::class)->simulate($record);
    }
    $collapsed = $collapsed ?? false;
@endphp

@if ($record instanceof \App\Models\Position && $result)
    <div
        class="vestix-what-if"
        x-data="{
            open: false,
            stop: {{ json_encode($record->initial_sl ?? $record->current_sl) }},
            exit: {{ json_encode($record->exit_price) }},
            loading: false,
            result: null,
            async run() {
                this.loading = true;
                try {
                    const res = await fetch(@js(route('positions.what-if', $record)), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ stop: this.stop, exit: this.exit }),
                    });
                    this.result = await res.json();
                } catch (e) {
                    this.result = { error: 'Simulatie mislukt' };
                } finally {
                    this.loading = false;
                }
            }
        }"
    >
        @if ($collapsed)
            <button
                type="button"
                class="vestix-what-if__toggle flex w-full items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2.5 text-left text-sm transition hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5"
                @click="open = !open"
                :aria-expanded="open.toString()"
            >
                <span class="flex flex-col gap-0.5">
                    <span class="font-medium text-gray-900 dark:text-gray-100">Wat Als simulator</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Alternatieve stop/exit vs jouw echte uitkomst — nu ${{ number_format($result['original_pnl'], 2) }}
                        @if ($result['original_r'] !== null)
                            · {{ number_format($result['original_r'], 2) }}R
                        @endif
                    </span>
                </span>
                <svg class="h-4 w-4 shrink-0 text-gray-400 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </button>
        @endif

            <div
                class="vestix-what-if__body space-y-3"
                @if ($collapsed)
                    x-show="open"
                    x-cloak
                    x-collapse.duration.200ms
                @endif
            >
            @unless ($collapsed)
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Wat als je stop ruimer was, of je exit later?
                </p>
            @endunless

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" @class(['mt-3' => $collapsed])>
                <label class="text-sm">
                    <span class="text-gray-500">Alt. stop</span>
                    <input type="number" step="0.01" x-model.number="stop" class="fi-input mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                </label>
                <label class="text-sm">
                    <span class="text-gray-500">Alt. exit</span>
                    <input type="number" step="0.01" x-model.number="exit" class="fi-input mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                </label>
            </div>
            <button type="button" @click="run()" class="fi-btn fi-btn-color-primary fi-size-sm justify-center sm:w-auto" x-bind:disabled="loading">
                <span x-text="loading ? 'Berekenen…' : 'Simuleer'"></span>
            </button>
            <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10" x-show="result && !result.error" x-cloak>
                <div class="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-3">
                    <div>Sim P&amp;L: <strong x-text="result ? ('$' + Number(result.simulated_pnl).toFixed(2)) : '—'"></strong></div>
                    <div>Δ: <strong x-text="result ? ('$' + Number(result.delta_pnl).toFixed(2)) : '—'"></strong></div>
                    <div>Sim R: <strong x-text="result?.simulated_r ?? '—'"></strong></div>
                </div>
                <p class="mt-2 text-xs text-gray-500" x-text="result?.exit_reason"></p>
            </div>
            @unless ($collapsed)
                <div class="text-xs text-gray-500" x-show="!result">
                    Nu: ${{ number_format($result['original_pnl'], 2) }}
                    @if ($result['original_r'] !== null)
                        · {{ number_format($result['original_r'], 2) }}R
                    @endif
                </div>
            @endunless
        </div>
    </div>
@endif
