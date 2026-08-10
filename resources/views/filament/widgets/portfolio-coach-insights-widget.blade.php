@php
    $cc = $this->getCommandCenter();
    $vitals = $cc['vitals'];
    $balance = $vitals['balance'];
    $directives = $cc['directives'];
    $sectors = $cc['sectors'];
    $selected = $this->getSelectedSectorDetail();
    $longPct = (int) round(($balance['long_pct'] ?? 0) * 100);
    $shortPct = (int) round(($balance['short_pct'] ?? 0) * 100);
@endphp

<x-filament-widgets::widget class="vestix-portfolio-coach-widget">
    <x-filament::section
        heading="Command Center"
        description="Operationele status van je portfolio"
        :compact="true"
    >
        <div class="vestix-portfolio-coach">
            {{-- Module 1: Vital Signs --}}
            <div class="vestix-portfolio-coach__vitals" role="group" aria-label="Portfolio vital signs">
                <div class="vestix-portfolio-coach__vital">
                    <p class="vestix-portfolio-coach__vital-label">Long / Short</p>
                    <p class="vestix-portfolio-coach__vital-value">
                        {{ $balance['long'] }}L / {{ $balance['short'] }}S
                    </p>
                    <div
                        class="vestix-portfolio-coach__split"
                        role="img"
                        aria-label="{{ $longPct }}% long, {{ $shortPct }}% short"
                    >
                        <span class="vestix-portfolio-coach__split-long" style="width: {{ $longPct }}%"></span>
                        <span class="vestix-portfolio-coach__split-short" style="width: {{ $shortPct }}%"></span>
                    </div>
                    <p class="vestix-portfolio-coach__vital-meta vestix-portfolio-coach__vital-meta--split">
                        <span class="vestix-portfolio-coach__balance-pct-long">{{ $longPct }}% L</span>
                        <span
                            @class([
                                'vestix-portfolio-coach__vital-badge',
                                'vestix-portfolio-coach__vital-badge--ok' => $balance['balanced'],
                                'vestix-portfolio-coach__vital-badge--warn' => ! $balance['balanced'],
                            ])
                        >{{ $balance['label'] }}</span>
                        <span class="vestix-portfolio-coach__balance-pct-short">{{ $shortPct }}% S</span>
                    </p>
                </div>

                <div class="vestix-portfolio-coach__vital">
                    <p class="vestix-portfolio-coach__vital-label">Sectoren</p>
                    <p class="vestix-portfolio-coach__vital-value">
                        {{ $vitals['sectors']['active'] }}/{{ $vitals['sectors']['total'] }}
                    </p>
                    <p class="vestix-portfolio-coach__vital-meta">{{ $vitals['sectors']['label'] }}</p>
                </div>

                <div class="vestix-portfolio-coach__vital">
                    <p class="vestix-portfolio-coach__vital-label">Risicostatus</p>
                    <p @class([
                        'vestix-portfolio-coach__vital-value',
                        'vestix-portfolio-coach__risk--'.$vitals['risk']['level'],
                    ])>
                        {{ $vitals['risk']['label'] }}
                    </p>
                    <p class="vestix-portfolio-coach__vital-meta">Correlatie &amp; balans</p>
                </div>
            </div>

            {{-- Module 2: Directives (full width) --}}
            <div class="vestix-portfolio-coach__directives">
                <h3 class="vestix-portfolio-coach__module-title">Tactical Directives</h3>
                <ul class="vestix-portfolio-coach__directive-list">
                    @foreach ($directives as $directive)
                        @php $ctaUrl = $this->resolveCtaUrl($directive['cta'] ?? null); @endphp
                        <li @class([
                            'vestix-portfolio-coach__directive',
                            'vestix-portfolio-coach__directive--'.$directive['severity'],
                        ])>
                            <div class="vestix-portfolio-coach__directive-top">
                                <div class="vestix-portfolio-coach__directive-head">
                                    <span class="vestix-portfolio-coach__directive-icon" aria-hidden="true"></span>
                                    <p class="vestix-portfolio-coach__directive-headline">
                                        {{ $directive['headline'] }}
                                    </p>
                                </div>
                                @if ($ctaUrl)
                                    <a href="{{ $ctaUrl }}" class="vestix-portfolio-coach__cta">
                                        {{ $directive['cta']['label'] }}
                                    </a>
                                @endif
                            </div>
                            <p class="vestix-portfolio-coach__directive-body">
                                <span class="vestix-portfolio-coach__directive-status">{{ $directive['status'] }}</span>
                                <span class="vestix-portfolio-coach__directive-sep" aria-hidden="true">·</span>
                                <span class="vestix-portfolio-coach__directive-order">
                                    <span class="vestix-portfolio-coach__order-label">Order</span>
                                    {{ $directive['order'] }}
                                </span>
                            </p>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Module 3: Sector grid --}}
            <div class="vestix-portfolio-coach__sectors">
                <h3 class="vestix-portfolio-coach__module-title">Sector Exposure</h3>
                <div class="vestix-portfolio-coach__sector-grid" role="list">
                    @foreach ($sectors as $sector)
                        <button
                            type="button"
                            wire:click="selectSector('{{ $sector['etf'] }}')"
                            @class([
                                'vestix-portfolio-coach__sector',
                                'vestix-portfolio-coach__sector--'.$sector['state'],
                                'vestix-portfolio-coach__sector--selected' => $this->selectedSector === $sector['etf'],
                            ])
                            role="listitem"
                            aria-pressed="{{ $this->selectedSector === $sector['etf'] ? 'true' : 'false' }}"
                        >
                            <span class="vestix-portfolio-coach__sector-etf">{{ $sector['etf'] }}</span>
                            @if ($sector['tickers'] !== [])
                                <span class="vestix-portfolio-coach__sector-tickers">
                                    {{ implode(', ', array_slice($sector['tickers'], 0, 2)) }}
                                    @if (count($sector['tickers']) > 2)
                                        +{{ count($sector['tickers']) - 2 }}
                                    @endif
                                </span>
                            @elseif ($sector['meewind'])
                                <span class="vestix-portfolio-coach__sector-tickers">meewind</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                @if ($selected)
                    <div class="vestix-portfolio-coach__sector-detail">
                        <p class="vestix-portfolio-coach__sector-detail-title">
                            {{ $selected['etf'] }}
                            <span @class([
                                'vestix-portfolio-coach__sector-state',
                                'vestix-portfolio-coach__sector-state--'.$selected['state'],
                            ])>
                                {{ strtoupper($selected['state']) }}
                            </span>
                        </p>
                        @foreach (['long' => 'Long', 'short' => 'Short'] as $dirKey => $dirLabel)
                            @php $bucket = $selected[$dirKey]; @endphp
                            @if ($bucket['risk_on_count'] > 0 || $bucket['locked_count'] > 0)
                                <p class="vestix-portfolio-coach__sector-detail-row">
                                    <strong>{{ $dirLabel }}:</strong>
                                    @if ($bucket['risk_on_count'] > 0)
                                        risk-on {{ implode(', ', $bucket['risk_on']) }}
                                    @endif
                                    @if ($bucket['risk_on_count'] > 0 && $bucket['locked_count'] > 0)
                                        ·
                                    @endif
                                    @if ($bucket['locked_count'] > 0)
                                        locked {{ implode(', ', $bucket['locked']) }}
                                    @endif
                                </p>
                            @endif
                        @endforeach
                        @if ($selected['tickers'] === [] && $selected['meewind'])
                            <p class="vestix-portfolio-coach__sector-detail-row">
                                Geen open exposure — meewind-kandidaat voor scan.
                            </p>
                        @elseif ($selected['tickers'] === [])
                            <p class="vestix-portfolio-coach__sector-detail-row">
                                Geen open posities in deze sector.
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
