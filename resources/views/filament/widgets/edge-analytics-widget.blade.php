@php
    $payload = $this->payload;
    $stats = $payload['stats'];
    $protocol = $payload['protocol'];
    $expectancyPositive = $stats['expectancy'] >= 0;
    $protocolValue = $protocol['avg_score'] !== null
        ? number_format($protocol['avg_score'], 0).'/100'
        : '—';
    $protocolHint = $protocol['scored_trades'] > 0
        ? $protocol['scored_trades'].' gescoord'
            .($protocol['weak_count'] > 0 ? ' · '.$protocol['weak_count'].' zwak' : '')
        : 'Nog geen protocol-scores';
@endphp

<x-filament-widgets::widget class="vestix-edge-analytics-widget">
    <x-filament::section
        heading="Edge analytics"
        description="Overall edge + expectancy per setup-grade — actie, geen vanity."
        :compact="true"
    >
        <div class="vestix-edge-analytics">
            <div class="vestix-edge-analytics__kpis">
                <div class="fi-wi-stats-overview-stat vestix-stat-card vestix-stat-card--vestix">
                    <div class="fi-wi-stats-overview-stat-content">
                        <span class="fi-wi-stats-overview-stat-label">Win rate</span>
                        <div class="fi-wi-stats-overview-stat-value">{{ number_format($stats['win_rate'], 1) }}%</div>
                        <p class="vestix-edge-analytics__kpi-meta">{{ $stats['total_trades'] }} gesloten trades</p>
                    </div>
                </div>

                <div @class([
                    'fi-wi-stats-overview-stat',
                    'vestix-stat-card',
                    'vestix-stat-card--green' => $expectancyPositive,
                    'vestix-stat-card--rose' => ! $expectancyPositive,
                ])>
                    <div class="fi-wi-stats-overview-stat-content">
                        <span class="fi-wi-stats-overview-stat-label">Expectancy</span>
                        <div class="fi-wi-stats-overview-stat-value">{{ number_format($stats['expectancy'], 2) }}%</div>
                        <p class="vestix-edge-analytics__kpi-meta">Per trade, gemiddeld</p>
                    </div>
                </div>

                <div class="fi-wi-stats-overview-stat vestix-stat-card vestix-stat-card--amber">
                    <div class="fi-wi-stats-overview-stat-content">
                        <span class="fi-wi-stats-overview-stat-label">Max drawdown</span>
                        <div class="fi-wi-stats-overview-stat-value">{{ number_format($stats['max_drawdown'], 2) }}%</div>
                        <p class="vestix-edge-analytics__kpi-meta">Diepste equity-dip</p>
                    </div>
                </div>

                <div class="fi-wi-stats-overview-stat vestix-stat-card vestix-stat-card--blue">
                    <div class="fi-wi-stats-overview-stat-content">
                        <span class="fi-wi-stats-overview-stat-label">Protocol avg</span>
                        <div class="fi-wi-stats-overview-stat-value">{{ $protocolValue }}</div>
                        <p class="vestix-edge-analytics__kpi-meta">{{ $protocolHint }}</p>
                    </div>
                </div>
            </div>

            @if ($payload['until_coach'] > 0)
                <p class="vestix-edge-analytics__hint">
                    Vestix Coach unlockt over {{ $payload['until_coach'] }} gesloten trade(s).
                </p>
            @endif

            @if ($payload['has_grade_breakdown'])
                <div class="vestix-edge-analytics__grades">
                    <h3 class="vestix-edge-analytics__grades-title">Per setup-grade</h3>
                    <div class="vestix-edge-analytics__table-wrap">
                        <table class="vestix-edge-analytics__table">
                            <thead>
                                <tr>
                                    <th>Grade</th>
                                    <th>Trades</th>
                                    <th>Win %</th>
                                    <th>Expectancy</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($payload['by_grade'] as $row)
                                    <tr>
                                        <td>
                                            <x-filament.positions.setup-grade-badge :grade="$row['grade']" />
                                        </td>
                                        <td>{{ $row['trades'] }}</td>
                                        <td>{{ number_format($row['win_rate'], 1) }}%</td>
                                        <td @class([
                                            'vestix-edge-analytics__num--pos' => $row['expectancy'] >= 0,
                                            'vestix-edge-analytics__num--neg' => $row['expectancy'] < 0,
                                        ])>{{ number_format($row['expectancy'], 2) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($payload['ungraded_trades'] > 0)
                        <p class="vestix-edge-analytics__hint">
                            {{ $payload['ungraded_trades'] }} trade(s) zonder setup-grade — niet in de tabel.
                        </p>
                    @endif
                </div>
            @else
                <p class="vestix-edge-analytics__hint">
                    Nog geen setup-grades op gesloten trades. Scores verschijnen zodra een trade een scorecard of review-grade heeft.
                </p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
