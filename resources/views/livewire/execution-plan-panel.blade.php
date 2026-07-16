@php
    use App\Services\SmartAllocationService;
    use Filament\Support\Enums\Width;
    use Illuminate\View\ComponentAttributeBag;
@endphp

<div class="fi-execution-plan" wire:key="execution-plan-panel">
    <x-filament::modal
        id="execution-plan"
        slide-over
        teleport="body"
        :width="Width::TwoExtraLarge"
        sticky-header
        sticky-footer
        close-button
        class="vestix-execution-plan-modal"
    >
        <x-slot name="trigger">
            <x-filament::icon-button
                :badge="$planCount ?: null"
                badge-color="primary"
                color="gray"
                icon="heroicon-o-shopping-cart"
                icon-size="lg"
                label="Order Plan"
                class="fi-topbar-execution-plan-btn"
            />
        </x-slot>

        <x-slot name="header">
            <div class="vestix-execution-plan__header">
                <h2 class="fi-modal-heading">
                    Order Plan
                    @if ($planCount > 0)
                        <span
                            {{
                                (new ComponentAttributeBag)->class([
                                    'fi-badge fi-size-xs fi-color-primary',
                                ])
                            }}
                        >
                            {{ $planCount }}
                        </span>
                    @endif
                </h2>
                <p class="vestix-execution-plan__subtitle">
                    Alleen setups waarop je gaat handelen. Zelfde lijst als de Telegram digest.
                </p>
            </div>
        </x-slot>

        @if ($scouts->isEmpty())
            <div class="vestix-execution-plan__empty">
                <p class="font-medium text-gray-950 dark:text-white">Nog geen setups in je Order Plan</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Zet op Mijn Radar de bel aan bij scouts die je wilt executeren. Die verschijnen hier met live budgetverdeling.
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
                'removable' => true,
                'hint' => 'Klik Toepassen om quantity en risicobudget op de scouts te zetten. Daarna plaats je per scout je order via Order plaatsen.',
            ])
        @endif

        <x-slot name="footer">
            @if ($scouts->isNotEmpty())
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
        </x-slot>
    </x-filament::modal>
</div>
