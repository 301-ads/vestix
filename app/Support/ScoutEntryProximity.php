<?php

namespace App\Support;

class ScoutEntryProximity
{
    public static function isNearEntry(float $live, float $entry, float $marginPercent): bool
    {
        if ($entry <= 0) {
            return false;
        }

        $distancePercent = abs($live - $entry) / $entry * 100;

        return $distancePercent <= $marginPercent;
    }
}
