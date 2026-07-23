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
                        @php
                            $parts = [];
                            foreach (['long', 'short'] as $direction) {
                                $bucket = $row[$direction];
                                if ($bucket['risk_on_count'] > 0) {
                                    $parts[] = implode(', ', $bucket['risk_on']).' '.$direction.' risk-on';
                                }
                                if ($bucket['locked_count'] > 0) {
                                    $parts[] = implode(', ', $bucket['locked']).' '.$direction.' locked';
                                }
                            }
                        @endphp
                        @if ($parts !== [])
                            <span class="vestix-portfolio-coach__strip-item">
                                <strong>{{ $sector }}</strong>: {{ implode(' · ', $parts) }}
                            </span>
                        @endif
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
