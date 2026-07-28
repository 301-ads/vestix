<?php

namespace App\Enums;

enum GapHerplanAction: string
{
    case Reprice = 'reprice';
    case Skip = 'skip';
    case Wait = 'wait';

    public function label(): string
    {
        return match ($this) {
            self::Reprice => 'Herprijs entry',
            self::Skip => 'Skip vandaag',
            self::Wait => 'Wacht op reclaim',
        };
    }
}
