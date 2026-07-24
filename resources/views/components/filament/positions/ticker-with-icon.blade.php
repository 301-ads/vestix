@props([
    'ticker',
    'iconUrl' => null,
    'iconLoading' => false,
    'statusDotColor' => null,
    'statusDotLabel' => null,
    'direction' => null,
    'showDirectionBadge' => false,
    'showDirectionIcon' => false,
])

@php
    use App\Enums\TradeDirection;

    $letter = strtoupper(substr((string) $ticker, 0, 1));
    $hue = abs(crc32((string) $ticker)) % 360;
    $hasStatusDot = filled($statusDotColor) && filled($statusDotLabel);

    $directionEnum = null;

    if ($showDirectionBadge || $showDirectionIcon) {
        $directionEnum = $direction instanceof TradeDirection
            ? $direction
            : TradeDirection::tryFrom((string) ($direction ?? ''));

        $directionEnum ??= TradeDirection::Long;
    }
@endphp

<span {{ $attributes->class(['ticker-with-icon']) }}>
    <span @class([
        'ticker-with-icon__mark',
        'ticker-with-icon__mark--has-dot' => $hasStatusDot,
    ])>
        @if (filled($iconUrl))
            <span class="ticker-with-icon__logo">
                <img src="{{ $iconUrl }}" alt="" class="ticker-with-icon__image" />
            </span>
        @elseif ($iconLoading)
            <span
                class="ticker-with-icon__logo ticker-with-icon__logo--loading"
                title="Logo wordt geladen…"
                aria-label="Logo wordt geladen"
            >
                <span class="ticker-with-icon__spinner" aria-hidden="true"></span>
            </span>
        @else
            <span
                class="ticker-letter-avatar"
                style="background-color: hsl({{ $hue }}, 55%, 42%);"
                aria-hidden="true"
            >{{ $letter }}</span>
        @endif

        @if ($hasStatusDot)
            <span
                @class([
                    'ticker-with-icon__status-dot',
                    'ticker-with-icon__status-dot--'.$statusDotColor,
                ])
                title="{{ $statusDotLabel }}"
                aria-label="{{ $statusDotLabel }}"
                x-data
                x-tooltip="{ content: @js($statusDotLabel), theme: $store.theme, trigger: 'mouseenter' }"
            ></span>
        @endif
    </span>

    <span class="ticker-with-icon__label font-bold">{{ $ticker }}</span>

    @if ($showDirectionIcon && $directionEnum && auth()->user()?->canUseShort())
        <x-filament.positions.direction-icon :direction="$directionEnum" />
    @elseif ($showDirectionBadge && $directionEnum)
        <x-filament.positions.direction-badge :direction="$directionEnum" />
    @endif
</span>
