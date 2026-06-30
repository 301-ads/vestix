@php
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\View\ComponentAttributeBag;

    $rsi = $rsi ?? null;
    $threshold = $threshold ?? 70;
    $isOverbought = $rsi !== null && $rsi !== '' && (float) $rsi >= (float) $threshold;
    $rsiFormatted = $rsi !== null && $rsi !== '' ? number_format((float) $rsi, 1, ',', '') : '—';
    $badgeColor = $isOverbought ? 'warning' : 'gray';
    $badgeLabel = $isOverbought ? 'Oververhit' : 'Neutraal';
    $valueClass = $isOverbought ? 'vestix-schild-status-telemetry__value--warning' : 'vestix-schild-status-telemetry__value--neutral';
    $tooltip = $isOverbought
        ? "Boven drempelwaarde ({$threshold}). Agressief schild geactiveerd."
        : "Onder drempelwaarde ({$threshold}). Standaard trailing actief.";
@endphp

<div class="vestix-schild-status-telemetry">
    <div class="vestix-schild-status-telemetry__value-row">
        <span @class(['vestix-schild-status-telemetry__value', $valueClass])>{{ $rsiFormatted }}</span>
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
