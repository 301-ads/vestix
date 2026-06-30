<?php

namespace App\Enums;

enum TrailingStopMode: string
{
    case Standard = 'standard';
    case AggressiveOverbought = 'aggressive_overbought';
    case AggressivePreEarnings = 'aggressive_pre_earnings';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standaard trailing',
            self::AggressiveOverbought => 'Oververhit: agressief ATR trailing',
            self::AggressivePreEarnings => 'Pre-earnings escalatie',
        };
    }
}
