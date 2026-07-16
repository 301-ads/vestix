@php
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
@endphp

<div class="vestix-smart-allocation">
    <p class="vestix-smart-allocation__intro">
        Risicopie:
        <strong>{{ number_format($result['pie_percent'], 2) }}%</strong>
        van ${{ number_format($result['bankroll'], 2) }}
        = <strong>${{ number_format($result['pie'], 2) }}</strong>
        · modus:
        <strong>{{ $result['mode'] === 'equal' ? 'Gelijkmatig' : 'Smart Sizing' }}</strong>
    </p>

    @if ($result['allocations'] === [])
        <p class="vestix-smart-allocation__empty">
            Geen allocaties mogelijk. Controleer scores (≥ {{ config('vestix.smart_sizing.min_score', 5) }}),
            bankroll en entry/stop-loss.
        </p>
    @else
        <div class="vestix-smart-allocation__table-wrap">
            <table class="vestix-smart-allocation__table">
                <thead>
                    <tr>
                        <th>Ticker</th>
                        <th>Score</th>
                        <th>R/R</th>
                        <th>Sector</th>
                        <th>Risico %</th>
                        <th>Risico $</th>
                        <th>Aantal</th>
                        <th>Inleg</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['allocations'] as $row)
                        <tr>
                            <td class="vestix-smart-allocation__ticker">{{ $row['ticker'] }}</td>
                            <td>{{ $row['score'] }}</td>
                            <td>
                                @if ($row['reward_risk'] !== null)
                                    {{ number_format($row['reward_risk'], 2) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                {{ $row['sector'] ?? '—' }}
                                @if ($row['sector_penalty'] > 0)
                                    <span class="vestix-smart-allocation__penalty">
                                        −{{ number_format($row['sector_penalty'] * 100, 0) }}%
                                    </span>
                                @endif
                            </td>
                            <td>
                                <span class="vestix-smart-allocation__copy-group">
                                    {{ number_format($row['risk_percent'], 2) }}%
                                    <button
                                        type="button"
                                        class="vestix-broker-order-ticket__copy-btn"
                                        x-data="{ copied: false }"
                                        x-tooltip="{ content: copied ? 'Gekopieerd!' : 'Kopieer risico %', theme: $store.theme, trigger: 'mouseenter' }"
                                        @click="
                                            navigator.clipboard.writeText(@js(number_format($row['risk_percent'], 2, '.', ''))).then(() => {
                                                copied = true; setTimeout(() => copied = false, 1500);
                                            })
                                        "
                                    >
                                        <span x-show="! copied">
                                            {{ \Filament\Support\generate_icon_html('heroicon-o-document-duplicate', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm'])) }}
                                        </span>
                                        <span x-show="copied" x-cloak>
                                            {{ \Filament\Support\generate_icon_html('heroicon-m-check', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm text-success-500'])) }}
                                        </span>
                                    </button>
                                </span>
                            </td>
                            <td>${{ number_format($row['risk_dollars'], 2) }}</td>
                            <td>
                                <span class="vestix-smart-allocation__copy-group">
                                    {{ number_format($row['quantity'], 0) }}
                                    <button
                                        type="button"
                                        class="vestix-broker-order-ticket__copy-btn"
                                        x-data="{ copied: false }"
                                        x-tooltip="{ content: copied ? 'Gekopieerd!' : 'Kopieer aantal', theme: $store.theme, trigger: 'mouseenter' }"
                                        @click="
                                            navigator.clipboard.writeText(@js((string) $row['quantity'])).then(() => {
                                                copied = true; setTimeout(() => copied = false, 1500);
                                            })
                                        "
                                    >
                                        <span x-show="! copied">
                                            {{ \Filament\Support\generate_icon_html('heroicon-o-document-duplicate', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm'])) }}
                                        </span>
                                        <span x-show="copied" x-cloak>
                                            {{ \Filament\Support\generate_icon_html('heroicon-m-check', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm text-success-500'])) }}
                                        </span>
                                    </button>
                                </span>
                            </td>
                            <td>
                                <span class="vestix-smart-allocation__copy-group">
                                    ${{ number_format($row['investment'], 2) }}
                                    <button
                                        type="button"
                                        class="vestix-broker-order-ticket__copy-btn"
                                        x-data="{ copied: false }"
                                        x-tooltip="{ content: copied ? 'Gekopieerd!' : 'Kopieer inleg', theme: $store.theme, trigger: 'mouseenter' }"
                                        @click="
                                            navigator.clipboard.writeText(@js(number_format($row['investment'], 2, '.', ''))).then(() => {
                                                copied = true; setTimeout(() => copied = false, 1500);
                                            })
                                        "
                                    >
                                        <span x-show="! copied">
                                            {{ \Filament\Support\generate_icon_html('heroicon-o-document-duplicate', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm'])) }}
                                        </span>
                                        <span x-show="copied" x-cloak>
                                            {{ \Filament\Support\generate_icon_html('heroicon-m-check', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm text-success-500'])) }}
                                        </span>
                                    </button>
                                </span>
                            </td>
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
                    <li>
                        <strong>{{ $exclusion['ticker'] }}</strong>
                        — {{ $exclusion['reason'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($result['allocations'] !== [])
        <p class="vestix-smart-allocation__hint">
            Bevestig om quantity en risicobudget op de scouts te zetten. Daarna plaats je per scout je order via
            <em>Order plaatsen</em> / <em>Order geplaatst</em>.
        </p>
    @endif
</div>
