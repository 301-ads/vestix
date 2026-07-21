@php
    use App\Enums\BankrollCashflowType;
    use App\Models\BankrollCashflow;

    /** @var \Illuminate\Support\Collection<int, BankrollCashflow> $cashflows */
    $cashflows = $cashflows ?? collect();
@endphp

<div class="vestix-cashflows">
    @if ($cashflows->isEmpty())
        <p class="vestix-cashflows__empty">
            Nog geen stortingen of opnames. Flex sync vult dit automatisch; handmatig alleen als iets mist.
        </p>
    @else
        <div class="vestix-cashflows__table-wrap">
            <table class="vestix-cashflows__table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Type</th>
                        <th>Bedrag</th>
                        <th>Bron</th>
                        <th class="vestix-cashflows__actions-col">
                            <span class="sr-only">Acties</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cashflows as $cashflow)
                        @php
                            $isDeposit = $cashflow->type === BankrollCashflowType::Deposit;
                            $sign = $isDeposit ? '+' : '−';
                            $isIbkr = $cashflow->source === 'ibkr';
                            $hasNote = filled($cashflow->note);
                        @endphp
                        <tr>
                            <td>{{ $cashflow->occurred_on->format('d-m-Y') }}</td>
                            <td>{{ $cashflow->type->label() }}</td>
                            <td>
                                <span
                                    @class([
                                        'vestix-cashflows__amount',
                                        'vestix-cashflows__amount--deposit' => $isDeposit,
                                        'vestix-cashflows__amount--withdrawal' => ! $isDeposit,
                                        'vestix-cashflows__amount--has-note' => $hasNote,
                                    ])
                                    @if ($hasNote)
                                        x-data
                                        x-tooltip="{
                                            content: @js($cashflow->note),
                                            theme: $store.theme,
                                            trigger: 'mouseenter',
                                        }"
                                    @endif
                                >
                                    {{ $sign }}${{ number_format((float) $cashflow->amount, 2, '.', ',') }}
                                </span>
                            </td>
                            <td>
                                <span @class([
                                    'vestix-cashflows__source',
                                    'vestix-cashflows__source--ibkr' => $isIbkr,
                                    'vestix-cashflows__source--manual' => ! $isIbkr,
                                ])>
                                    {{ $isIbkr ? 'IBKR sync' : 'Handmatig' }}
                                </span>
                            </td>
                            <td class="vestix-cashflows__actions-col">
                                <div class="vestix-cashflows__row-actions">
                                    <button
                                        type="button"
                                        class="vestix-cashflows__action-btn"
                                        title="Wijzig"
                                        wire:click="mountAction('edit_cashflow', { cashflow: {{ (int) $cashflow->id }} })"
                                    >
                                        {{ \Filament\Support\generate_icon_html('heroicon-o-pencil-square', attributes: (new \Illuminate\View\ComponentAttributeBag)->class(['fi-icon fi-size-sm'])) }}
                                        <span>Wijzig</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="vestix-cashflows__action-btn vestix-cashflows__action-btn--danger"
                                        title="Verwijder"
                                        wire:click="mountAction('delete_cashflow', { cashflow: {{ (int) $cashflow->id }} })"
                                    >
                                        {{ \Filament\Support\generate_icon_html('heroicon-o-trash', attributes: (new \Illuminate\View\ComponentAttributeBag)->class(['fi-icon fi-size-sm'])) }}
                                        <span>Verwijder</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
