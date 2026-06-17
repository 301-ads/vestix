@props([
    'ticker',
    'iconUrl' => null,
])

@php
    $letter = strtoupper(substr((string) $ticker, 0, 1));
    $hue = abs(crc32((string) $ticker)) % 360;
@endphp

<span {{ $attributes->class(['ticker-with-icon']) }}>
    @if (filled($iconUrl))
        <span class="ticker-with-icon__logo">
            <img src="{{ $iconUrl }}" alt="" class="ticker-with-icon__image" />
        </span>
    @else
        <span
            class="ticker-letter-avatar"
            style="background-color: hsl({{ $hue }}, 55%, 42%);"
            aria-hidden="true"
        >{{ $letter }}</span>
    @endif

    <span class="ticker-with-icon__label font-bold">{{ $ticker }}</span>
</span>
