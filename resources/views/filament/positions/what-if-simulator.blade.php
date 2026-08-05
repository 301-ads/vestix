@php
    $result = null;
    if ($record instanceof \App\Models\Position && $record->status === 'closed') {
        $result = app(\App\Services\WhatIfSimulatorService::class)->simulate($record);
    }
@endphp

@if ($record instanceof \App\Models\Position && $result)
    <div
        class="vestix-what-if space-y-3"
        x-data="{
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
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Wat als je stop ruimer was, of je exit later?
        </p>
        <div class="grid grid-cols-1 gap-2">
            <label class="text-sm">
                <span class="text-gray-500">Alt. stop</span>
                <input type="number" step="0.01" x-model.number="stop" class="fi-input mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
            </label>
            <label class="text-sm">
                <span class="text-gray-500">Alt. exit</span>
                <input type="number" step="0.01" x-model.number="exit" class="fi-input mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
            </label>
        </div>
        <button type="button" @click="run()" class="fi-btn fi-btn-color-primary fi-size-sm w-full justify-center" x-bind:disabled="loading">
            <span x-text="loading ? 'Berekenen…' : 'Simuleer'"></span>
        </button>
        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10" x-show="result && !result.error" x-cloak>
            <div class="space-y-1">
                <div>Sim P&amp;L: <strong x-text="result ? ('$' + Number(result.simulated_pnl).toFixed(2)) : '—'"></strong></div>
                <div>Δ: <strong x-text="result ? ('$' + Number(result.delta_pnl).toFixed(2)) : '—'"></strong></div>
                <div>Sim R: <strong x-text="result?.simulated_r ?? '—'"></strong></div>
            </div>
            <p class="mt-2 text-xs text-gray-500" x-text="result?.exit_reason"></p>
        </div>
        <div class="text-xs text-gray-500" x-show="!result">
            Nu: ${{ number_format($result['original_pnl'], 2) }}
            @if ($result['original_r'] !== null)
                · {{ number_format($result['original_r'], 2) }}R
            @endif
        </div>
    </div>
@endif
