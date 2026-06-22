<?php

namespace App\Enums;

enum PremarketGapStatus: string
{
    case Ok = 'ok';
    case GapUp = 'gap_up';
    case GapDown = 'gap_down';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::GapUp => 'Gap-up risico',
            self::GapDown => 'Onder trigger',
            self::Unavailable => 'Geen data',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::GapUp => 'danger',
            self::GapDown => 'warning',
            self::Unavailable => 'gray',
        };
    }
}
