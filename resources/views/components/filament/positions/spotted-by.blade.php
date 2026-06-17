@props([
    'name',
])

@php
    $initial = strtoupper(substr(trim((string) $name), 0, 1)) ?: '?';
    $hue = abs(crc32((string) $name)) % 360;
@endphp

<span {{ $attributes->class(['spotted-by']) }}>
    <span
        class="spotted-by__avatar"
        style="background-color: hsl({{ $hue }}, 45%, 38%);"
        aria-hidden="true"
    >{{ $initial }}</span>
    <span class="spotted-by__name">{{ $name }}</span>
</span>
