<?php

namespace App\Enums;

enum SquadRole: string
{
    case Commander = 'commander';
    case Sniper = 'sniper';
    case Scout = 'scout';

    public function label(): string
    {
        return match ($this) {
            self::Commander => 'Commander',
            self::Sniper => 'Sniper',
            self::Scout => 'Scout',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Commander->value => self::Commander->label(),
            self::Sniper->value => self::Sniper->label(),
            self::Scout->value => self::Scout->label(),
        ];
    }
}
