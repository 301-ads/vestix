<?php

namespace App\Enums;

enum ScoutSource: string
{
    case SniperScan = 'sniper_scan';

    public function label(): string
    {
        return match ($this) {
            self::SniperScan => 'Sniper scan',
        };
    }
}
