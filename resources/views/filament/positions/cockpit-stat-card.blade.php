@php
    use Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\DescriptionComponent;
    use Illuminate\View\ComponentAttributeBag;

    $valueColor = $valueColor ?? null;
    $description = $description ?? null;
    $descriptionColor = $descriptionColor ?? 'gray';
    $descriptionIcon = $descriptionIcon ?? null;
    $valuePulse = $valuePulse ?? false;
    $labelHintIcon = $labelHintIcon ?? null;
    $labelHintTooltip = $labelHintTooltip ?? null;
    $cardVariant = $cardVariant ?? 'zinc';
@endphp

<div @class([
    'fi-wi-stats-overview-stat',
    'vestix-stat-card',
    'vestix-stat-card--uppercase-label',
    'vestix-stat-card--'.$cardVariant,
    'h-full',
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

        @if (filled($description))
            <div {{ (new ComponentAttributeBag)->color(DescriptionComponent::class, $descriptionColor)->class(['fi-wi-stats-overview-stat-description']) }}>
                <span class="whitespace-nowrap">{{ $description }}</span>
                @if (filled($descriptionIcon))
                    {{ \Filament\Support\generate_icon_html($descriptionIcon) }}
                @endif
            </div>
        @endif
    </div>
</div>
