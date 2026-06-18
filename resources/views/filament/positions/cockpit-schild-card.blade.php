@php
    use Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\DescriptionComponent;
    use Illuminate\View\ComponentAttributeBag;

    $action = $action ?? 'HOLD';
    $value = $value ?? '—';
    $copyValue = $copyValue ?? null;
    $description = $description ?? null;
    $descriptionColor = $descriptionColor ?? 'gray';
    $valuePulse = $valuePulse ?? false;
    $cardVariant = $cardVariant ?? 'zinc';
    $isActionable = $action === 'UPDATE';
@endphp

<div @class([
    'fi-wi-stats-overview-stat',
    'vestix-stat-card',
    'vestix-stat-card--dashboard',
    'vestix-stat-card--'.$cardVariant,
    'vestix-stat-card--actionable' => $isActionable,
])>
    <div class="fi-wi-stats-overview-stat-content">
        <div class="vestix-stat-card__header flex items-start justify-between gap-2">
            <span class="fi-wi-stats-overview-stat-label">Berekende SL</span>

            @if ($isActionable)
                <span class="vestix-stat-card__corner-badge">Update</span>
            @endif
        </div>

        <div class="vestix-stat-card__value-row flex items-center gap-1.5">
            <div
                {{
                    (new ComponentAttributeBag)
                        ->class([
                            'fi-wi-stats-overview-stat-value',
                            'animate-pulse' => $valuePulse && ! $isActionable,
                        ])
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

            @if ($isActionable)
                <button
                    type="button"
                    wire:click="mountAction('applyCalculatedSl')"
                    class="vestix-stat-card__apply-btn"
                    x-tooltip="{
                        content: 'SL bijwerken',
                        theme: $store.theme,
                    }"
                    @class([
                        'animate-pulse' => $valuePulse,
                    ])
                >
                    {{ \Filament\Support\generate_icon_html('heroicon-m-arrow-up', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm'])) }}
                </button>
            @endif
        </div>

        <div class="vestix-stat-card__meta-row">
            @if (filled($description))
                <div {{ (new ComponentAttributeBag)->color(DescriptionComponent::class, $descriptionColor)->class(['fi-wi-stats-overview-stat-description']) }}>
                    <span class="whitespace-nowrap">{{ $description }}</span>
                </div>
            @endif
        </div>
    </div>
</div>
