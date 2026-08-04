<?php

namespace App\Enums;

enum LeaderboardTrack: string
{
    case Executor = 'executor';
    case Analyst = 'analyst';

    public function label(): string
    {
        return match ($this) {
            self::Executor => 'Executor',
            self::Analyst => 'Analyst',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Executor => 'Persoonlijke closed trades — win rate, freerides en ROI %.',
            self::Analyst => 'Uitkomsten van clones op jouw gedeelde setups — zelfde metrics, geen dollarbedragen.',
        };
    }
}
