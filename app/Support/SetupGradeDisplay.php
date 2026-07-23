<?php

namespace App\Support;

use App\Models\Position;
use Illuminate\Support\HtmlString;

class SetupGradeDisplay
{
    /**
     * @return array{totalPoints: int, maxPoints: int, grade: string, gradeLabel: string, hardFailReasons: array<int, string>}|null
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

    public static function score(Position $record): ?string
    {
        $score = self::resolveScore($record);

        if ($score === null) {
            return null;
        }

        return $score['totalPoints'].'/'.$score['maxPoints'];
    }

    public static function gradeLetter(Position $record): ?string
    {
        $score = self::resolveScore($record);

        if ($score === null) {
            return null;
        }

        return match ($score['grade']) {
            'A++', 'A' => 'A',
            'B' => 'B',
            'C' => 'C',
            default => 'N',
        };
    }

    public static function tooltip(Position $record): ?string
    {
        return self::label($record);
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
            'C' => 'gray',
            default => 'danger',
        };
    }

    public static function description(Position $record): ?string
    {
        return $record->signalCandleStaleLabel();
    }

    public static function html(Position $record): ?HtmlString
    {
        $score = self::score($record);
        $gradeLetter = self::gradeLetter($record);

        if ($score === null || $gradeLetter === null) {
            return null;
        }

        return new HtmlString(view('components.filament.positions.setup-grade', [
            'score' => $score,
            'gradeLetter' => $gradeLetter,
            'color' => self::color($record),
            'gradeLabel' => self::label($record),
            'staleLabel' => self::description($record),
        ])->render());
    }
}
