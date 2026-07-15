<?php

namespace App\Enums;

enum SquadRole: string
{
    case Commander = 'commander';
    case Sniper = 'sniper';
    case Scout = 'scout';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Commander->value => 'Commander',
            self::Sniper->value => 'Sniper',
            self::Scout->value => 'Scout',
        ];
    }
}
