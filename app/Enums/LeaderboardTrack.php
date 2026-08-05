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
            self::Executor => 'Persoonlijke closed trades — Elite ranking op total R met Discipline-poort (≥85%).',
            self::Analyst => 'Uitkomsten van clones op jouw gedeelde setups — zelfde R/discipline-metrics, geen dollarbedragen.',
        };
    }
}
