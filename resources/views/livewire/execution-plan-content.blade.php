@php
    use App\Filament\Resources\Scouts\ScoutResource;
    use App\Services\SmartAllocationService;
@endphp

<div @class([
    'vestix-execution-plan-content',
    'vestix-execution-plan-content--panel' => $layout === 'panel',
    'vestix-execution-plan-content--embedded' => $layout === 'embedded',
])>
    @if ($scouts->isEmpty() && $activeScouts->isEmpty())
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
        @if ($scouts->isNotEmpty())
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

        @if ($activeScouts->isNotEmpty())
            <div class="vestix-smart-allocation__active">
                <h4 class="vestix-smart-allocation__active-heading">Actief vandaag</h4>
                <p class="vestix-smart-allocation__active-intro">
                    Buy-stops die je al hebt geplaatst — wachten op fill.
                </p>
                <div class="vestix-smart-allocation__table-wrap vestix-smart-allocation__table-wrap--active">
                    <table class="vestix-smart-allocation__table">
                        <thead>
                            <tr>
                                <th>Ticker</th>
                                <th>Score</th>
                                <th>Aantal</th>
                                <th>Inleg</th>
                                <th class="vestix-smart-allocation__actions-col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($activeScouts as $active)
                                @php
                                    $editUrl = ScoutResource::getUrl('edit', ['record' => $active]);
                                    $qty = $active->quantity !== null ? (float) $active->quantity : null;
                                    $entry = $active->entry_price !== null ? (float) $active->entry_price : null;
                                    $investment = ($qty !== null && $entry !== null) ? $qty * $entry : null;
                                @endphp
                                <tr>
                                    <td class="vestix-smart-allocation__ticker">
                                        <a href="{{ $editUrl }}" class="vestix-smart-allocation__ticker-link">
                                            {{ $active->ticker }}
                                        </a>
                                        <x-filament.positions.direction-badge :direction="$active->tradeDirection()" />
                                    </td>
                                    <td>
                                        {{ $active->evaluateSetupScore()['totalPoints'] }}
                                    </td>
                                    <td>
                                        {{ $qty !== null && $qty > 0 ? number_format($qty, 0) : '—' }}
                                    </td>
                                    <td>
                                        {{ $investment !== null ? '$'.number_format($investment, 2) : '—' }}
                                    </td>
                                    <td class="vestix-smart-allocation__actions-col">
                                        <div class="vestix-smart-allocation__row-actions">
                                            {{ $this->activateScoutActionForPosition($active) }}
                                            {{ $this->clearBuyStopActionForPosition($active) }}
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif

    <x-filament-actions::modals />
</div>
