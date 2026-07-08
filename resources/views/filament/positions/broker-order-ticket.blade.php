@php
    /** @var array{title: string, rows: list<array{label: string, value: string, accent?: bool, tone?: string}>, difference_label: string|null, confirmation: string, submit_label: string} $ticket */
@endphp

<div class="vestix-broker-order-ticket">
    <section class="vestix-broker-order-ticket__section">
        <h3 class="vestix-broker-order-ticket__heading">Overzicht</h3>

        <dl class="vestix-broker-order-ticket__rows">
            @foreach ($ticket['rows'] as $row)
                <div @class([
                    'vestix-broker-order-ticket__row',
                    'vestix-broker-order-ticket__row--accent' => $row['accent'] ?? false,
                    'vestix-broker-order-ticket__row--old' => ($row['tone'] ?? null) === 'old',
                    'vestix-broker-order-ticket__row--new' => ($row['tone'] ?? null) === 'new',
                ])>
                    <dt class="vestix-broker-order-ticket__label">{{ $row['label'] }}</dt>
                    <dd class="vestix-broker-order-ticket__value">{{ $row['value'] }}</dd>
                </div>

                @if (($row['accent'] ?? false) && filled($ticket['difference_label'] ?? null))
                    <p class="vestix-broker-order-ticket__difference-label">{{ $ticket['difference_label'] }}</p>
                @endif
            @endforeach
        </dl>
    </section>

    <section class="vestix-broker-order-ticket__section">
        <h3 class="vestix-broker-order-ticket__heading">Bevestiging</h3>
        <p class="vestix-broker-order-ticket__confirmation">{{ $ticket['confirmation'] }}</p>
    </section>
</div>
