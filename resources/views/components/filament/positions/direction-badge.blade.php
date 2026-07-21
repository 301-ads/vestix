@props([
    'direction',
])

@php
    use App\Enums\TradeDirection;

    $directionEnum = $direction instanceof TradeDirection
        ? $direction
        : TradeDirection::tryFrom((string) ($direction ?? ''));

    $directionEnum ??= TradeDirection::Long;
@endphp

<span
    @class([
        'ticker-direction-badge',
        'ticker-direction-badge--long' => $directionEnum->isLong(),
        'ticker-direction-badge--short' => $directionEnum->isShort(),
    ])
    title="{{ $directionEnum->label() }}"
>{{ $directionEnum->label() }}</span>
