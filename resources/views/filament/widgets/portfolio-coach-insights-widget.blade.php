@php
    $insights = $this->getInsights();
    $balance = $this->getBalance();
    $exposure = $this->getSectorExposure();
@endphp

<x-filament-widgets::widget class="vestix-portfolio-coach-widget">
    <x-filament::section heading="Portfolio Coach" :compact="true">
        <div class="vestix-portfolio-coach">
            @if ($balance['total'] > 0)
                <div class="vestix-portfolio-coach__strip">
                    <span class="vestix-portfolio-coach__strip-item">
                        {{ $balance['long'] }} long / {{ $balance['short'] }} short
                        ({{ (int) round($balance['long_pct'] * 100) }}% / {{ (int) round($balance['short_pct'] * 100) }}%)
                    </span>
                    @foreach ($exposure as $sector => $row)
                        <span class="vestix-portfolio-coach__strip-item">
                            <strong>{{ $sector }}</strong>:
                            @if ($row['risk_on_count'] > 0)
                                {{ implode(', ', $row['risk_on']) }} risk-on
                            @endif
                            @if ($row['locked_count'] > 0)
                                @if ($row['risk_on_count'] > 0) · @endif
                                {{ implode(', ', $row['locked']) }} locked
                            @endif
                        </span>
                    @endforeach
                </div>
            @endif

            <ul class="vestix-portfolio-coach__insights">
                @foreach ($insights as $insight)
                    <li @class([
                        'vestix-portfolio-coach__insight',
                        'vestix-portfolio-coach__insight--'.$insight['severity'],
                    ])>
                        <p class="vestix-portfolio-coach__insight-title">{{ $insight['title'] }}</p>
                        <p class="vestix-portfolio-coach__insight-body">{{ $insight['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
