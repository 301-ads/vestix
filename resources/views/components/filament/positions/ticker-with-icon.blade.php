@props([
    'ticker',
    'iconUrl' => null,
])

<span {{ $attributes->class(['ticker-with-icon']) }}>
    @if (filled($iconUrl))
        <span class="ticker-with-icon__logo">
            <img src="{{ $iconUrl }}" alt="" class="ticker-with-icon__image" />
        </span>
    @endif

    <span class="ticker-with-icon__label">{{ $ticker }}</span>
</span>
