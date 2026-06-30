@php
    $extension = $extension ?? null;

    if ($extension === null) {
        $pct = '—';
        $suffix = null;
    } else {
        $prefix = $extension >= 0 ? '+' : '−';
        $pct = $prefix.number_format(abs($extension), 2, ',', '').'%';
        $suffix = 't.o.v. SMA20';
    }
@endphp

<div class="vestix-schild-status-telemetry vestix-sma-extension-telemetry">
    <span class="vestix-schild-status-telemetry__value">{{ $pct }}</span>
    @if ($suffix !== null)
        <span class="vestix-sma-extension-telemetry__suffix">{{ $suffix }}</span>
    @endif
</div>
