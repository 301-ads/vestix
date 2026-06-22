<?php

namespace App\Enums;

enum PremarketScanResult: string
{
    case GapRisk = 'gap_risk';
    case Reclamation = 'reclamation';
    case Landing = 'landing';
    case Ok = 'ok';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::GapRisk => 'Gap-up risico',
            self::Reclamation => 'Reclamation',
            self::Landing => 'Landing',
            self::Ok => 'OK',
            self::Unavailable => 'Geen data',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::GapRisk => 'danger',
            self::Reclamation => 'success',
            self::Landing => 'warning',
            self::Ok => 'success',
            self::Unavailable => 'gray',
        };
    }

    public function summaryKey(): string
    {
        return match ($this) {
            self::GapRisk => 'gap_up',
            self::Reclamation => 'reclamation',
            self::Landing => 'landing',
            self::Ok => 'ok',
            self::Unavailable => 'unavailable',
        };
    }
}
