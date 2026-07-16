@php
    use App\Services\SmartAllocationService;
@endphp

<div @class([
    'vestix-execution-plan-content',
    'vestix-execution-plan-content--panel' => $layout === 'panel',
    'vestix-execution-plan-content--embedded' => $layout === 'embedded',
])>
    @if ($scouts->isEmpty())
        <div class="vestix-execution-plan__empty">
            <div class="vestix-execution-plan__empty-icon" aria-hidden="true">
                {{ \Filament\Support\generate_icon_html('heroicon-o-shopping-cart', attributes: (new \Illuminate\View\ComponentAttributeBag)->class(['fi-icon fi-size-lg'])) }}
            </div>
            <p class="font-medium text-gray-950 dark:text-white">Nog geen setups in je Order Plan</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Zet op Mijn Radar het winkelwagen-icoon aan bij scouts die je wilt executeren.
            </p>
        </div>
    @else
        <div class="vestix-execution-plan__mode">
            <span class="vestix-execution-plan__mode-label">Verdeelmethode</span>
            <div class="vestix-execution-plan__mode-btns" role="group" aria-label="Verdeelmethode">
                <button
                    type="button"
                    wire:click="setMode('{{ SmartAllocationService::MODE_SMART }}')"
                    @class([
                        'vestix-execution-plan__mode-btn',
                        'vestix-execution-plan__mode-btn--active' => $mode === SmartAllocationService::MODE_SMART,
                    ])
                >
                    Smart Sizing
                </button>
                <button
                    type="button"
                    wire:click="setMode('{{ SmartAllocationService::MODE_EQUAL }}')"
                    @class([
                        'vestix-execution-plan__mode-btn',
                        'vestix-execution-plan__mode-btn--active' => $mode === SmartAllocationService::MODE_EQUAL,
                    ])
                >
                    Gelijkmatig
                </button>
            </div>
        </div>

        @include('filament.positions.smart-budget-allocation', [
            'result' => $result,
            'scouts' => $scouts,
            'removable' => true,
            'actionable' => true,
            'hint' => 'Klik Toepassen om quantity en risicobudget op de scouts te zetten. Daarna open je per scout het Order Ticket.',
        ])

        <div class="vestix-execution-plan__footer">
            <p class="vestix-execution-plan__footer-summary">
                {{ $planCount }} setup{{ $planCount === 1 ? '' : 's' }}
                · inleg ≈ ${{ number_format($totalInvestment, 2) }}
            </p>
            <x-filament::button
                color="primary"
                wire:click="applyAllocation"
                wire:loading.attr="disabled"
            >
                Toepassen
            </x-filament::button>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
