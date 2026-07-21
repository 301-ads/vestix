@php
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\View\ComponentAttributeBag;

    $etf = filled($etf ?? null) ? strtoupper(trim((string) $etf)) : null;
    $trendPositive = $trendPositive ?? null;
    $hasTrend = $trendPositive !== null && $etf !== null;

    if ($hasTrend && (bool) $trendPositive) {
        $badgeColor = 'success';
        $badgeLabel = 'Meewind';
        $valueClass = 'vestix-schild-status-telemetry__value--neutral';
        $tooltip = 'Sector-ETF boven SMA 50 — meewind.';
    } elseif ($hasTrend) {
        $badgeColor = 'warning';
        $badgeLabel = 'Tegenwind';
        $valueClass = 'vestix-schild-status-telemetry__value--warning';
        $tooltip = 'Sector-ETF onder SMA 50 — tegenwind.';
    } else {
        $badgeColor = 'gray';
        $badgeLabel = $etf !== null ? 'Onbekend' : 'Ontbreekt';
        $valueClass = 'vestix-schild-status-telemetry__value--neutral';
        $tooltip = $etf !== null
            ? 'Sector-ETF bekend, trenddata ontbreekt. Sync marktdata om trend te laden.'
            : 'Nog geen sector-ETF. Sync marktdata of zet een override op de scout.';
    }
@endphp

<div class="vestix-schild-status-telemetry">
    <div class="vestix-schild-status-telemetry__value-row">
        <span @class(['vestix-schild-status-telemetry__value', $valueClass])>{{ $etf ?? '—' }}</span>
        <span
            {{ (new ComponentAttributeBag)->color(BadgeComponent::class, $badgeColor)->class(['fi-badge', 'fi-size-sm', 'vestix-rsi-telemetry__badge']) }}
            x-tooltip="{
                content: @js($tooltip),
                theme: $store.theme,
            }"
        >
            <span class="fi-badge-label-ctn">
                <span class="fi-badge-label">{{ $badgeLabel }}</span>
            </span>
        </span>
    </div>
</div>
