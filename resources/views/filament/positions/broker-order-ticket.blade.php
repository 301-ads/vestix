@php
    use Illuminate\View\ComponentAttributeBag;

    /** @var array{title: string, intro?: string|null, rows: list<array{label: string, value: string, accent?: bool, tone?: string, copy_value?: string, hint?: string}>, difference_label: string|null, confirmation: string, submit_label: string} $ticket */
@endphp

<div class="vestix-broker-order-ticket">
    @if (filled($ticket['intro'] ?? null))
        <section class="vestix-broker-order-ticket__section">
            <p class="vestix-broker-order-ticket__intro">{{ $ticket['intro'] }}</p>
        </section>
    @endif

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
                    <dd class="vestix-broker-order-ticket__value-ctn">
                        <span class="vestix-broker-order-ticket__value">{{ $row['value'] }}</span>

                        @if (filled($row['copy_value'] ?? null))
                            <button
                                type="button"
                                class="vestix-broker-order-ticket__copy-btn"
                                x-data="{ copied: false }"
                                x-tooltip="{
                                    content: copied ? 'Gekopieerd!' : 'Kopieer waarde',
                                    theme: $store.theme,
                                    trigger: 'mouseenter',
                                }"
                                @click="
                                    navigator.clipboard.writeText(@js($row['copy_value'])).then(() => {
                                        copied = true;
                                        setTimeout(() => copied = false, 1500);
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
                        @endif
                    </dd>
                </div>

                @if (filled($row['hint'] ?? null))
                    <p class="vestix-broker-order-ticket__hint">{{ $row['hint'] }}</p>
                @endif

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
