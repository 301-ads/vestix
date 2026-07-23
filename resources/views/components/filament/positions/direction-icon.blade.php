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
>{{ $isLong ? 'L' : 'S' }}</span>
