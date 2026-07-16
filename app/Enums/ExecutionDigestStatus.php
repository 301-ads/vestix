<?php

namespace App\Enums;

enum ExecutionDigestStatus: string
{
    case Safe = 'safe';
    case CancelledGapUp = 'cancelled_gap_up';
    case CancelledTrendBreak = 'cancelled_trend_break';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Safe => 'Veilig',
            self::CancelledGapUp => 'Niet plaatsen — gap up',
            self::CancelledTrendBreak => 'Niet plaatsen — trend break',
            self::Unavailable => 'Geen openingsprijs',
        };
    }

    public function isCancelled(): bool
    {
        return $this === self::CancelledGapUp || $this === self::CancelledTrendBreak;
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Safe => 'success',
            self::CancelledGapUp, self::CancelledTrendBreak => 'danger',
            self::Unavailable => 'gray',
        };
    }
}
