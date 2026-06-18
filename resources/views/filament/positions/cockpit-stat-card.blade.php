@php
    use Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\DescriptionComponent;
    use Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\StatsOverviewWidgetStatChartComponent;
    use Illuminate\View\ComponentAttributeBag;

    $valueColor = $valueColor ?? null;
    $description = $description ?? null;
    $descriptionColor = $descriptionColor ?? 'gray';
    $descriptionIcon = $descriptionIcon ?? null;
    $valuePulse = $valuePulse ?? false;
    $labelHintIcon = $labelHintIcon ?? null;
    $labelHintTooltip = $labelHintTooltip ?? null;
    $cardVariant = $cardVariant ?? 'zinc';
    $copyValue = $copyValue ?? null;
    $secondaryDescription = $secondaryDescription ?? null;
    $chart = $chart ?? null;
    $chartColor = $chartColor ?? 'gray';
    $chartValues = filled($chart) ? array_values($chart) : [];
    $showChart = count($chartValues) >= 2;

    if ($showChart) {
        $chartMin = min($chartValues);
        $chartMax = max($chartValues);
        $chartRange = max($chartMax - $chartMin, 0.01);
        $chartWidth = 100;
        $chartHeight = 24;
        $chartPoints = [];

        foreach ($chartValues as $index => $price) {
            $x = ($index / (count($chartValues) - 1)) * $chartWidth;
            $y = $chartHeight - ((($price - $chartMin) / $chartRange) * $chartHeight);
            $chartPoints[] = round($x, 2).','.round($y, 2);
        }

        $chartLine = implode(' ', $chartPoints);
        $chartArea = '0,'.$chartHeight.' '.$chartLine.' '.$chartWidth.','.$chartHeight;
    }
@endphp

<div @class([
    'fi-wi-stats-overview-stat',
    'vestix-stat-card',
    'vestix-stat-card--dashboard',
    'vestix-stat-card--'.$cardVariant,
    'vestix-stat-card--charted' => $showChart,
])>
    <div class="fi-wi-stats-overview-stat-content">
        <div class="fi-wi-stats-overview-stat-label-ctn flex items-center gap-1">
            <span class="fi-wi-stats-overview-stat-label">{{ $label }}</span>
            @if (filled($labelHintIcon) && filled($labelHintTooltip))
                <span
                    x-tooltip="{
                        content: @js($labelHintTooltip),
                        theme: $store.theme,
                    }"
                >
                    {{ \Filament\Support\generate_icon_html($labelHintIcon, attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm text-gray-400'])) }}
                </span>
            @endif
        </div>

        <div class="vestix-stat-card__value-row flex items-center gap-1.5">
            <div
                {{
                    (new ComponentAttributeBag)
                        ->class([
                            'fi-wi-stats-overview-stat-value',
                            'animate-pulse' => $valuePulse,
                        ])
                        ->when(filled($valueColor), fn (ComponentAttributeBag $bag) => $bag->color(DescriptionComponent::class, $valueColor))
                }}
            >
                {{ $value }}
            </div>

            @if (filled($copyValue))
                <button
                    type="button"
                    class="vestix-stat-card__copy-btn"
                    x-data="{ copied: false }"
                    x-tooltip="{
                        content: copied ? 'Gekopieerd!' : 'Kopieer SL-prijs',
                        theme: $store.theme,
                    }"
                    @click="
                        navigator.clipboard.writeText(@js($copyValue)).then(() => {
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
        </div>

        <div class="vestix-stat-card__meta-row">
            @if (filled($description))
                <div {{ (new ComponentAttributeBag)->color(DescriptionComponent::class, $descriptionColor)->class(['fi-wi-stats-overview-stat-description']) }}>
                    <span class="whitespace-nowrap">{{ $description }}</span>
                    @if (filled($descriptionIcon))
                        {{ \Filament\Support\generate_icon_html($descriptionIcon) }}
                    @endif
                </div>
            @elseif (filled($secondaryDescription))
                <div {{ (new ComponentAttributeBag)->color(DescriptionComponent::class, $secondaryDescription['color'] ?? 'info')->class(['fi-wi-stats-overview-stat-description']) }}>
                    <span
                        class="whitespace-nowrap inline-flex items-center gap-1"
                        @if (filled($secondaryDescription['tooltip'] ?? null))
                            x-tooltip="{
                                content: @js($secondaryDescription['tooltip']),
                                theme: $store.theme,
                            }"
                        @endif
                    >
                        <span>{{ $secondaryDescription['text'] }}</span>
                        @if (filled($secondaryDescription['icon'] ?? null))
                            {{ \Filament\Support\generate_icon_html($secondaryDescription['icon'], attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-xs'])) }}
                        @endif
                    </span>
                </div>
            @endif
        </div>
    </div>

    @if ($showChart)
        <div {{ (new ComponentAttributeBag)->color(StatsOverviewWidgetStatChartComponent::class, $chartColor)->class(['fi-wi-stats-overview-stat-chart', 'vestix-sparkline']) }}>
            <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" preserveAspectRatio="none" aria-hidden="true">
                <polygon points="{{ $chartArea }}" class="vestix-sparkline__fill"></polygon>
                <polyline points="{{ $chartLine }}" class="vestix-sparkline__line" vector-effect="non-scaling-stroke"></polyline>
            </svg>
        </div>
    @endif
</div>
