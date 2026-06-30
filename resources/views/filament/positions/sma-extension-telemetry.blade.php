@php
    $extension = $extension ?? null;

    if ($extension === null) {
        $formatted = '—';
    } else {
        $prefix = $extension >= 0 ? '+' : '−';
        $formatted = $prefix.number_format(abs($extension), 2, ',', '').'% t.o.v. SMA20';
    }
@endphp

<div class="vestix-schild-status-telemetry">
    <span class="vestix-schild-status-telemetry__value">{{ $formatted }}</span>
</div>
