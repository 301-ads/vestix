<?php

namespace App\Enums;

enum SquadActivityType: string
{
    case Shared = 'shared';
    case Cloned = 'cloned';
    case Opened = 'opened';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Gedeeld',
            self::Cloned => 'Gekloond',
            self::Opened => 'Geopend',
            self::Closed => 'Gesloten',
        };
    }
}
