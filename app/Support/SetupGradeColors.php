<?php

namespace App\Support;

/**
 * Shared setup-grade badge tones + chart colors.
 * Badge CSS: `.scout-scorecard-hud-grade-badge--{tone}` in theme.css (scorecard HUD).
 */
final class SetupGradeColors
{
    /** Matches `.scout-scorecard-hud-grade-badge--a-plus` */
    public const A_PLUS = '#00d492';

    /** Matches `.scout-scorecard-hud-grade-badge--a` (dark) */
    public const A = '#00aa75';

    /** Matches `.scout-scorecard-hud-grade-badge--b` (dark amber) */
    public const B = '#fbbf24';

    /** Matches `.scout-scorecard-hud-grade-badge--c` */
    public const C = '#a1a1aa';

    /** Matches `.scout-scorecard-hud-grade-badge--no-trade` */
    public const NO_TRADE = '#fb7185';

    public const MUTED = '#a1a1aa';

    /**
     * Tone suffix for `.scout-scorecard-hud-grade-badge--{tone}`.
     */
    public static function badgeTone(string $grade): string
    {
        return match ($grade) {
            'A++' => 'a-plus',
            'A' => 'a',
            'B' => 'b',
            'C' => 'c',
            'NO TRADE' => 'no-trade',
            default => 'c',
        };
    }

    /**
     * Apex category label color for a grade key (A++, A, B, …).
     */
    public static function chartLabel(string $grade): string
    {
        return match ($grade) {
            'A++' => self::A_PLUS,
            'A' => self::A,
            'B' => self::B,
            'C' => self::C,
            'NO TRADE' => self::NO_TRADE,
            default => self::MUTED,
        };
    }
}
