<?php

namespace App\Enums;

enum EarningsReleaseHour: string
{
    case Bmo = 'bmo';
    case Amc = 'amc';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Bmo => 'BMO',
            self::Amc => 'AMC',
            self::Unknown => 'Onbekend',
        };
    }

    public static function tryFromApi(?string $hour): self
    {
        return match (strtolower((string) $hour)) {
            'bmo' => self::Bmo,
            'amc' => self::Amc,
            default => self::Unknown,
        };
    }
}
