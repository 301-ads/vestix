@php
    use App\Support\Kluis\KluisOrderPlan;
    use App\Support\Kluis\KluisThermometerReading;

    /** @var KluisThermometerReading|null $reading */
    /** @var KluisOrderPlan|null $plan */
@endphp

<div class="vestix-kluis-command space-y-6">
    <div class="vestix-kluis-command__grid">
        <section class="vestix-kluis-panel">
            <h3 class="vestix-kluis-panel__title">Droog kruit</h3>
            <p class="vestix-kluis-panel__value">
                €{{ number_format((float) $dryPowder, 2, ',', '.') }}
            </p>
            <p class="vestix-kluis-panel__hint">Cash-reserve langs de zijlijn (los van IBKR swing-cash).</p>
        </section>

        <section class="vestix-kluis-panel vestix-kluis-panel--thermometer">
            <h3 class="vestix-kluis-panel__title">Vestix Thermometer</h3>

            @if ($error)
                <p class="vestix-kluis-panel__error">{{ $error }}</p>
            @elseif ($reading)
                <div class="vestix-kluis-thermometer">
                    <span @class([
                        'vestix-kluis-thermometer__badge',
                        'vestix-kluis-thermometer__badge--'.$reading->climate->value,
                    ])>
                        {{ $reading->climate->codeLabel() }}
                    </span>
                    <p class="vestix-kluis-panel__value">
                        {{ sprintf('%+.1f%%', $reading->deviationPct) }}
                    </p>
                    <p class="vestix-kluis-panel__hint">
                        {{ $reading->ticker }} · koers
                        @if ($reading->resolvedSymbol && strtoupper($reading->resolvedSymbol) !== strtoupper($reading->ticker))
                            via {{ $reading->resolvedSymbol }}
                        @endif
                        €{{ number_format($reading->close, 2, ',', '.') }}
                        · SMA-200 €{{ number_format($reading->sma200, 2, ',', '.') }}
                    </p>
                    @if ($reading->resolvedSymbol && strtoupper($reading->resolvedSymbol) !== strtoupper($reading->ticker))
                        <p class="vestix-kluis-panel__hint">
                            Thermometer gebruikt {{ $reading->resolvedSymbol }} als marktdata-proxy ({{ $reading->ticker }} is niet beschikbaar op onze koersbronnen).
                        </p>
                    @endif
                    <p class="vestix-kluis-panel__message">{{ $reading->message() }}</p>
                </div>
            @else
                <p class="vestix-kluis-panel__hint">
                    Nog geen marktdata. Klik op “Thermometer verversen”.
                </p>
            @endif
        </section>

        <section class="vestix-kluis-panel vestix-kluis-panel--plan">
            <h3 class="vestix-kluis-panel__title">Order plan</h3>

            @if ($plan)
                <ul class="vestix-kluis-plan">
                    <li>
                        <span>Naar {{ $reading?->ticker ?? 'ETF' }}</span>
                        <strong>€{{ number_format($plan->etfAmount, 2, ',', '.') }}</strong>
                    </li>
                    @if ($plan->dryPowderDelta > 0)
                        <li>
                            <span>Naar droog kruit</span>
                            <strong>+€{{ number_format($plan->cashReserveAmount(), 2, ',', '.') }}</strong>
                        </li>
                    @elseif ($plan->dryPowderDelta < 0)
                        <li>
                            <span>Uit droog kruit</span>
                            <strong>−€{{ number_format($plan->dryPowderDeployed(), 2, ',', '.') }}</strong>
                        </li>
                    @else
                        <li>
                            <span>Droog kruit</span>
                            <strong>ongewijzigd</strong>
                        </li>
                    @endif
                    <li>
                        <span>Droog kruit na executie</span>
                        <strong>€{{ number_format($plan->dryPowderAfter, 2, ',', '.') }}</strong>
                    </li>
                </ul>

                @if ($alreadyConfirmed)
                    <p class="vestix-kluis-panel__hint">Deze maand is al bevestigd.</p>
                @else
                    <p class="vestix-kluis-panel__hint">
                        Advies tot je rechtsboven “Uitgevoerd bevestigen” kiest.
                    </p>
                @endif
            @else
                <p class="vestix-kluis-panel__hint">Order plan verschijnt zodra de thermometer data heeft.</p>
            @endif
        </section>
    </div>
</div>
