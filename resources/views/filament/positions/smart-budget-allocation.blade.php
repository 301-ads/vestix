@php
    use App\Support\StopLimitBuffer;
    use Illuminate\View\ComponentAttributeBag;

    /** @var array{
     *     mode: string,
     *     pie: float,
     *     pie_percent: float,
     *     bankroll: float,
     *     allocations: list<array{
     *         position_id: int,
     *         ticker: string,
     *         score: int,
     *         reward_risk: float|null,
     *         expected_value: float|null,
     *         sector: string|null,
     *         sector_penalty: float,
     *         weight: float,
     *         weight_share: float,
     *         risk_dollars: float,
     *         risk_percent: float,
     *         quantity: int,
     *         investment: float,
     *         entry: float,
     *         stop_loss: float,
     *         target_1: float|null,
     *     }>,
     *     exclusions: list<array{position_id: int, ticker: string, reason: string}>,
     * } $result
     */
    $removable = $removable ?? false;
    $density = ($density ?? 'compact') === 'full' ? 'full' : 'compact';
    $hint = $hint ?? 'Bevestig om quantity en risicobudget op de scouts te zetten. Daarna plaats je per scout je order via Order plaatsen / Order geplaatst.';
@endphp

<div @class([
    'vestix-smart-allocation',
    'vestix-smart-allocation--full' => $density === 'full',
    'vestix-smart-allocation--compact' => $density === 'compact',
])>
    <p class="vestix-smart-allocation__intro">
        IBKR risicopie:
        <strong>{{ number_format($result['pie_percent'], 2) }}%</strong>
        van ${{ number_format($result['bankroll'], 2) }}
        = <strong>${{ number_format($result['pie'], 2) }}</strong>
        · modus:
        <strong>{{ $result['mode'] === 'equal' ? 'Gelijkmatig' : 'Smart Sizing' }}</strong>
    </p>

    @if (($result['mode'] ?? '') === 'smart' && ($result['weights_uniform'] ?? false) && count($result['allocations']) >= 2)
        <p class="vestix-smart-allocation__hint">
            Smart Sizing verdeelt hier gelijk aan Gelijkmatig: score en R/R zijn per setup (nagenoeg) gelijk,
            dus de gewichten vallen samen. Verschil zie je pas bij ongelijke scores of R/R.
        </p>
    @endif

    @if ($result['allocations'] === [])
        <p class="vestix-smart-allocation__empty">
            Geen allocaties mogelijk. Controleer scores (≥ {{ config('vestix.smart_sizing.min_score', 5) }}),
            IBKR bankroll en entry/stop-loss.
        </p>
    @else
        <div class="vestix-smart-allocation__table-wrap">
            <table class="vestix-smart-allocation__table">
                <thead>
                    <tr>
                        <th>Ticker</th>
                        <th>Score</th>
                        @if ($density === 'compact')
                            <th
                                x-data
                                x-tooltip="{ content: 'Reward/Risk tot Target 1 — potentiële winst per dollar risico (entry → stop).', theme: $store.theme, trigger: 'mouseenter' }"
                                class="vestix-smart-allocation__rr-col"
                            >
                                R/R
                            </th>
                        @endif
                        <th>Sector</th>
                        @if ($density === 'full')
                            <th>Aantal</th>
                            <th>Buy-Stop</th>
                            <th>Limit</th>
                            <th>Stop-Loss</th>
                            <th>Take-Profit</th>
                            <th>Risico $</th>
                            <th>Inleg</th>
                        @else
                            <th>Risico $</th>
                            <th>Aantal</th>
                            <th>Inleg</th>
                        @endif
                        @if ($removable)
                            <th class="vestix-smart-allocation__actions-col"></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['allocations'] as $row)
                        @php
                            $limitPrice = StopLimitBuffer::limitPrice((float) $row['entry']);
                        @endphp
                        <tr>
                            <td class="vestix-smart-allocation__ticker">{{ $row['ticker'] }}</td>
                            <td>{{ $row['score'] }}</td>
                            @if ($density === 'compact')
                                <td>
                                    @if ($row['reward_risk'] !== null)
                                        {{ number_format($row['reward_risk'], 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            <td>
                                {{ $row['sector'] ?? '—' }}
                                @if ($row['sector_penalty'] > 0)
                                    <span class="vestix-smart-allocation__penalty">
                                        −{{ number_format($row['sector_penalty'] * 100, 0) }}%
                                    </span>
                                @endif
                            </td>
                            @if ($density === 'full')
                                <td>
                                    @include('filament.positions.partials.copy-value-button', [
                                        'display' => number_format($row['quantity'], 0),
                                        'copyValue' => (string) $row['quantity'],
                                        'label' => 'Kopieer aantal',
                                    ])
                                </td>
                                <td>
                                    @include('filament.positions.partials.copy-value-button', [
                                        'display' => '$'.number_format($row['entry'], 2),
                                        'copyValue' => number_format($row['entry'], 2, '.', ''),
                                        'label' => 'Kopieer Buy-Stop',
                                    ])
                                </td>
                                <td>
                                    @include('filament.positions.partials.copy-value-button', [
                                        'display' => '$'.number_format($limitPrice, 2),
                                        'copyValue' => number_format($limitPrice, 2, '.', ''),
                                        'label' => 'Kopieer Limit',
                                    ])
                                </td>
                                <td>
                                    @include('filament.positions.partials.copy-value-button', [
                                        'display' => '$'.number_format($row['stop_loss'], 2),
                                        'copyValue' => number_format($row['stop_loss'], 2, '.', ''),
                                        'label' => 'Kopieer Stop-Loss',
                                    ])
                                </td>
                                <td>
                                    @if ($row['target_1'] !== null)
                                        @include('filament.positions.partials.copy-value-button', [
                                            'display' => '$'.number_format($row['target_1'], 2),
                                            'copyValue' => number_format($row['target_1'], 2, '.', ''),
                                            'label' => 'Kopieer Take-Profit',
                                        ])
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>${{ number_format($row['risk_dollars'], 2) }}</td>
                                <td>
                                    @include('filament.positions.partials.copy-value-button', [
                                        'display' => '$'.number_format($row['investment'], 2),
                                        'copyValue' => number_format($row['investment'], 2, '.', ''),
                                        'label' => 'Kopieer inleg',
                                    ])
                                </td>
                            @else
                                <td>${{ number_format($row['risk_dollars'], 2) }}</td>
                                <td>
                                    @include('filament.positions.partials.copy-value-button', [
                                        'display' => number_format($row['quantity'], 0),
                                        'copyValue' => (string) $row['quantity'],
                                        'label' => 'Kopieer aantal',
                                    ])
                                </td>
                                <td>
                                    @include('filament.positions.partials.copy-value-button', [
                                        'display' => '$'.number_format($row['investment'], 2),
                                        'copyValue' => number_format($row['investment'], 2, '.', ''),
                                        'label' => 'Kopieer inleg',
                                    ])
                                </td>
                            @endif
                            @if ($removable)
                                <td class="vestix-smart-allocation__actions-col">
                                    <button
                                        type="button"
                                        class="vestix-execution-plan__remove-btn"
                                        wire:click="removeFromPlan({{ (int) $row['position_id'] }})"
                                        wire:loading.attr="disabled"
                                        title="Haal uit Order Plan"
                                    >
                                        {{ \Filament\Support\generate_icon_html('heroicon-o-x-mark', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm'])) }}
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($result['exclusions'] !== [])
        <div class="vestix-smart-allocation__exclusions">
            <h4 class="vestix-smart-allocation__exclusions-heading">Uitgesloten</h4>
            <ul>
                @foreach ($result['exclusions'] as $exclusion)
                    <li class="vestix-smart-allocation__exclusion-row">
                        <span>
                            <strong>{{ $exclusion['ticker'] }}</strong>
                            — {{ $exclusion['reason'] }}
                        </span>
                        @if ($removable)
                            <button
                                type="button"
                                class="vestix-execution-plan__remove-btn"
                                wire:click="removeFromPlan({{ (int) $exclusion['position_id'] }})"
                                wire:loading.attr="disabled"
                                title="Haal uit Order Plan"
                            >
                                {{ \Filament\Support\generate_icon_html('heroicon-o-x-mark', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm'])) }}
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($result['allocations'] !== [])
        <p class="vestix-smart-allocation__hint">
            {{ $hint }}
        </p>
    @endif
</div>
