<?php

namespace App\Support;

use App\Models\Position;

class EntrySetupSnapshot
{
    public const SOURCE_LIVE = 'live';

    public const SOURCE_LEGACY_BUY_STOP_REVIEW = 'legacy_buy_stop_review';

    public const SOURCE_LEGACY_LAST_SETUP_SCORE = 'legacy_last_setup_score';

    /**
     * Capture live scorecard + promotions for an entry freeze.
     *
     * @return array{
     *     entry_setup_score: int,
     *     entry_setup_grade: string,
     *     entry_setup_promoted_a: bool,
     *     entry_setup_promoted_a_plus: bool,
     *     entry_setup_captured_at: \Illuminate\Support\Carbon,
     *     entry_setup_source: string,
     * }
     */
    public static function attributesFromLivePosition(Position $position, string $source = self::SOURCE_LIVE): array
    {
        $scorecard = $position->evaluateSetupScore();

        return [
            'entry_setup_score' => (int) $scorecard['totalPoints'],
            'entry_setup_grade' => (string) $scorecard['grade'],
            'entry_setup_promoted_a' => (bool) $position->trader_promoted_a,
            'entry_setup_promoted_a_plus' => (bool) $position->trader_promoted_a_plus,
            'entry_setup_captured_at' => now(),
            'entry_setup_source' => $source,
        ];
    }

    /**
     * Legacy backfill using buy-stop review grade or last_setup_score with live grade bands.
     *
     * @return array{
     *     entry_setup_score: int|null,
     *     entry_setup_grade: string,
     *     entry_setup_promoted_a: bool,
     *     entry_setup_promoted_a_plus: bool,
     *     entry_setup_captured_at: \Illuminate\Support\Carbon,
     *     entry_setup_source: string,
     * }|null
     */
    public static function attributesFromLegacyPosition(Position $position): ?array
    {
        $reviewGrade = $position->buy_stop_review_setup_grade;

        if (is_string($reviewGrade) && $reviewGrade !== '') {
            return [
                'entry_setup_score' => $position->buy_stop_review_setup_score !== null
                    ? (int) $position->buy_stop_review_setup_score
                    : $position->last_setup_score,
                'entry_setup_grade' => $reviewGrade,
                'entry_setup_promoted_a' => (bool) $position->trader_promoted_a,
                'entry_setup_promoted_a_plus' => (bool) $position->trader_promoted_a_plus,
                'entry_setup_captured_at' => now(),
                'entry_setup_source' => self::SOURCE_LEGACY_BUY_STOP_REVIEW,
            ];
        }

        if ($position->last_setup_score === null) {
            return null;
        }

        $score = (int) $position->last_setup_score;
        $promotedA = (bool) $position->trader_promoted_a;
        $promotedAPlus = (bool) $position->trader_promoted_a_plus;

        return [
            'entry_setup_score' => $score,
            'entry_setup_grade' => self::gradeFromScoreLiveRules($score, $promotedA, $promotedAPlus),
            'entry_setup_promoted_a' => $promotedA,
            'entry_setup_promoted_a_plus' => $promotedAPlus,
            'entry_setup_captured_at' => now(),
            'entry_setup_source' => self::SOURCE_LEGACY_LAST_SETUP_SCORE,
        ];
    }

    /**
     * Live Radar grade bands (not the old analytics auto-A++ on perfect score).
     */
    public static function gradeFromScoreLiveRules(int $score, bool $promotedA, bool $promotedAPlus): string
    {
        $maxPoints = ScoutSetupScorecard::maxPoints();

        if ($promotedAPlus && $score >= $maxPoints) {
            return 'A++';
        }

        if ($score >= $maxPoints - 1) {
            return 'A';
        }

        if ($promotedA && $score >= 8) {
            return 'A';
        }

        if ($score >= 7) {
            return 'B';
        }

        if ($score >= 5) {
            return 'C';
        }

        return 'NO TRADE';
    }

    public static function alreadyCaptured(Position $position): bool
    {
        return $position->entry_setup_captured_at !== null
            || (is_string($position->entry_setup_grade) && $position->entry_setup_grade !== '');
    }
}
