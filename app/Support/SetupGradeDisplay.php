<?php

namespace App\Support;

use App\Models\Position;

class SetupGradeDisplay
{
    /**
     * @return array{grade: string, gradeLabel: string, hardFailReasons: array<int, string>}|null
     */
    public static function resolveScore(Position $record): ?array
    {
        if (
            ($record->signal_low === null && $record->latest_close_price === null)
            || $record->latest_sma_20 === null
            || $record->scout_rsi === null
        ) {
            return null;
        }

        return $record->evaluateSetupScore();
    }

    public static function label(Position $record): ?string
    {
        return self::resolveScore($record)['gradeLabel'] ?? null;
    }

    public static function color(Position $record): string
    {
        $score = self::resolveScore($record);

        if ($score === null) {
            return 'gray';
        }

        if ($score['hardFailReasons'] !== []) {
            return 'danger';
        }

        return match ($score['grade']) {
            'A++' => 'success',
            'A' => 'success',
            'B' => 'warning',
            default => 'gray',
        };
    }
}
