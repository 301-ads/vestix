@props([
    'direction',
])

@php
    use App\Enums\TradeDirection;

    $directionEnum = $direction instanceof TradeDirection
        ? $direction
        : TradeDirection::tryFrom((string) ($direction ?? ''));

    $directionEnum ??= TradeDirection::Long;
    $isLong = $directionEnum->isLong();
@endphp

<span
    @class([
        'ticker-direction-icon',
        'ticker-direction-icon--long' => $isLong,
        'ticker-direction-icon--short' => ! $isLong,
    ])
    title="{{ $directionEnum->label() }}"
    aria-label="{{ $directionEnum->label() }}"
>
    @if ($isLong)
        <svg class="ticker-direction-icon__arrow" viewBox="0 0 10 10" fill="none" aria-hidden="true">
            <path d="M5 1.5L8.5 6.5H1.5L5 1.5Z" fill="currentColor"/>
        </svg>
    @else
        <svg class="ticker-direction-icon__arrow" viewBox="0 0 10 10" fill="none" aria-hidden="true">
            <path d="M5 8.5L1.5 3.5H8.5L5 8.5Z" fill="currentColor"/>
        </svg>
    @endif
</span>
