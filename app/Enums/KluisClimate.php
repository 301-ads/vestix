<?php

namespace App\Enums;

enum KluisClimate: string
{
    case Overheat = 'overheat';
    case Neutral = 'neutral';
    case Dip = 'dip';
    case Crash = 'crash';

    public function label(): string
    {
        return match ($this) {
            self::Overheat => 'Oververhit',
            self::Neutral => 'Neutraal',
            self::Dip => 'Korting / Dip',
            self::Crash => 'Bloedbad / Crash',
        };
    }

    public function codeLabel(): string
    {
        return match ($this) {
            self::Overheat => 'Code Rood',
            self::Neutral => 'Code Groen',
            self::Dip => 'Code Geel',
            self::Crash => 'Code Zwart',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Overheat => 'danger',
            self::Neutral => 'success',
            self::Dip => 'warning',
            self::Crash => 'gray',
        };
    }

    public function orderMessage(float $deviationPct): string
    {
        $formatted = sprintf('%+.1f%%', $deviationPct);

        return match ($this) {
            self::Overheat => "Markt is oververhit ({$formatted}). Defensieve opstelling.",
            self::Neutral => "Klimaat is stabiel ({$formatted}). Standaard executie.",
            self::Dip => "Markt in de uitverkoop ({$formatted}). Open de aanval.",
            self::Crash => "Bloed op de straten ({$formatted}). Maximale inzet.",
        };
    }
}
