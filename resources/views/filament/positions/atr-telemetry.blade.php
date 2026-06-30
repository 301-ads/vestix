@php
    $atr = $atr ?? null;
    $atrFormatted = $atr !== null && $atr !== '' ? '$'.number_format((float) $atr, 2, ',', '') : '—';
@endphp

<div class="vestix-schild-status-telemetry">
    <span class="vestix-schild-status-telemetry__value">{{ $atrFormatted }}</span>
</div>
