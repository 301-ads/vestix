<?php

namespace App\Enums;

enum TrailingStopMode: string
{
    case Standard = 'standard';
    case AggressivePreEarnings = 'aggressive_pre_earnings';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standaard trailing',
            self::AggressivePreEarnings => 'Agressief trailing (pre-earnings)',
        };
    }
}
