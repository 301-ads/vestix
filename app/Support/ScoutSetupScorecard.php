<?php

namespace App\Support;

class ScoutSetupScorecard
{
    /**
     * @param  array{
     *     signal_low?: float|null,
     *     latest_open_price?: float|null,
     *     latest_close_price?: float|null,
     *     latest_sma_20?: float|null,
     *     sma_20_five_days_ago?: float|null,
     *     latest_sma_50?: float|null,
     *     scout_rsi?: float|null,
     *     bounce_volume_above_average?: bool|null,
     * }  $inputs
     * @return array{
     *     totalPoints: int,
     *     maxPoints: int,
     *     grade: string,
     *     gradeLabel: string,
     *     hardFailReasons: array<int, string>,
     *     criteria: array<int, array{
     *         key: string,
     *         label: string,
     *         points: int,
     *         maxPoints: int,
     *         status: string,
     *         detail: string,
     *     }>,
     * }
     */
    public static function evaluate(array $inputs): array
    {
        $criteria = [
            self::scoreTrampoline($inputs),
            self::scoreSmaDirection($inputs),
            self::scoreRsi($inputs),
            self::scoreVolume($inputs),
        ];

        $totalPoints = array_sum(array_column($criteria, 'points'));
        $maxPoints = array_sum(array_column($criteria, 'maxPoints'));

        $hardFailReasons = self::resolveHardFailReasons($inputs);

        [$grade, $gradeLabel] = self::resolveGrade($totalPoints, $hardFailReasons);

        return [
            'totalPoints' => $totalPoints,
            'maxPoints' => $maxPoints,
            'grade' => $grade,
            'gradeLabel' => $gradeLabel,
            'hardFailReasons' => $hardFailReasons,
            'criteria' => $criteria,
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreTrampoline(array $inputs): array
    {
        $close = self::resolveClosePrice($inputs);
        $sma = self::toFloat($inputs['latest_sma_20'] ?? null);

        if ($sma === null || $sma <= 0) {
            return self::criterion('trampoline', 'Trampoline-afstand', 0, 2, 'fail', 'Data ontbreekt');
        }

        if ($close === null) {
            return self::criterion('trampoline', 'Trampoline-afstand', 0, 2, 'fail', 'Wacht op slotkoers (Close)');
        }

        if ($close < $sma) {
            return self::criterion('trampoline', 'Trampoline-afstand', 0, 2, 'fail', 'Close onder SMA 20 — trampoline gebroken');
        }

        $distance = (($close - $sma) / $sma) * 100;
        $rejectionBounce = self::isRejectionBounce($inputs, $sma);

        if ($distance <= 1.5) {
            $detail = $rejectionBounce
                ? sprintf('Rejection bounce — Low onder SMA, Close hersteld (%.2f%% boven SMA)', $distance)
                : sprintf('%.2f%% van SMA — perfecte landing', $distance);

            return self::criterion('trampoline', 'Trampoline-afstand', 2, 2, 'pass', $detail);
        }

        if ($distance <= 3.0) {
            $detail = $rejectionBounce
                ? sprintf('Rejection bounce — Low onder SMA, Close hersteld (%.2f%% boven SMA)', $distance)
                : sprintf('%.2f%% van SMA — suboptimaal', $distance);

            return self::criterion('trampoline', 'Trampoline-afstand', 1, 2, 'warn', $detail);
        }

        $detail = $rejectionBounce
            ? sprintf('Rejection bounce maar ver weggeschoten (%.2f%% boven SMA)', $distance)
            : sprintf('%.2f%% van SMA — te ver weggeschoten', $distance);

        return self::criterion('trampoline', 'Trampoline-afstand', 0, 2, 'fail', $detail);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreSmaDirection(array $inputs): array
    {
        $latest = self::toFloat($inputs['latest_sma_20'] ?? null);
        $fiveDaysAgo = self::toFloat($inputs['sma_20_five_days_ago'] ?? null);
        $sma50 = self::toFloat($inputs['latest_sma_50'] ?? null);

        if ($latest === null || $fiveDaysAgo === null || $sma50 === null) {
            $detail = match (true) {
                $latest !== null && $fiveDaysAgo === null => 'Haal marktdata op voor 5-daagse SMA',
                $latest !== null && $sma50 === null => 'Haal marktdata op voor SMA 50',
                default => 'Data ontbreekt',
            };

            return self::criterion('sma_direction', 'SMA trend (20/50)', 0, 2, 'fail', $detail);
        }

        if ($latest < $fiveDaysAgo) {
            return self::criterion('sma_direction', 'SMA trend (20/50)', 0, 2, 'fail', 'Dalende SMA over 5 dagen — geen opwaartse trend');
        }

        if ($latest <= $sma50) {
            return self::criterion('sma_direction', 'SMA trend (20/50)', 0, 2, 'fail', 'SMA 20 onder SMA 50 — korte trend doorboort lange trend');
        }

        if ($latest === $fiveDaysAgo) {
            return self::criterion('sma_direction', 'SMA trend (20/50)', 2, 2, 'pass', 'Flat over 5 dagen + boven SMA 50');
        }

        return self::criterion('sma_direction', 'SMA trend (20/50)', 2, 2, 'pass', 'Stijgende trend + SMA 20 > SMA 50');
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreRsi(array $inputs): array
    {
        $rsi = self::toFloat($inputs['scout_rsi'] ?? null);

        if ($rsi === null) {
            return self::criterion('rsi', 'RSI sweet spot', 0, 2, 'fail', 'Data ontbreekt');
        }

        if ($rsi > 70) {
            return self::criterion('rsi', 'RSI sweet spot', 0, 2, 'fail', sprintf('RSI %.1f — oververhit (>70)', $rsi));
        }

        if ($rsi >= 45 && $rsi <= 55) {
            return self::criterion('rsi', 'RSI sweet spot', 2, 2, 'pass', sprintf('RSI %.1f — ultieme cooldown zone', $rsi));
        }

        if ($rsi > 55 && $rsi <= 65) {
            return self::criterion('rsi', 'RSI sweet spot', 1, 2, 'warn', sprintf('RSI %.1f — nog momentum', $rsi));
        }

        return self::criterion('rsi', 'RSI sweet spot', 0, 2, 'fail', sprintf('RSI %.1f — buiten sweet spot', $rsi));
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreVolume(array $inputs): array
    {
        $confirmed = (bool) ($inputs['bounce_volume_above_average'] ?? false);

        if (! $confirmed) {
            return self::criterion('volume', 'Volume bevestiging', 0, 1, 'fail', 'Nog niet bevestigd');
        }

        $open = self::resolveOpenPrice($inputs);
        $close = self::resolveClosePrice($inputs);

        if ($open === null || $close === null) {
            return self::criterion('volume', 'Volume bevestiging', 0, 1, 'fail', 'Open/slotkoers ontbreekt voor volume-check');
        }

        if ($close < $open) {
            return self::criterion('volume', 'Volume bevestiging', 0, 1, 'fail', 'Vallend mes: hoog volume maar slotkoers onder openingskoers');
        }

        return self::criterion('volume', 'Volume bevestiging', 1, 1, 'pass', 'Echte bounce: hoog volume en koers gesloten in het groen');
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<int, string>
     */
    private static function resolveHardFailReasons(array $inputs): array
    {
        $reasons = [];

        $rsi = self::toFloat($inputs['scout_rsi'] ?? null);

        if ($rsi !== null && $rsi > 70) {
            $reasons[] = 'RSI oververhit (>70) — geen A-setup mogelijk';
        }

        $open = self::resolveOpenPrice($inputs);
        $close = self::resolveClosePrice($inputs);
        $sma = self::toFloat($inputs['latest_sma_20'] ?? null);

        if ($close !== null && $sma !== null && $close < $sma) {
            $reasons[] = 'Close onder SMA 20 — trampoline gebroken';
        }

        $confirmed = (bool) ($inputs['bounce_volume_above_average'] ?? false);

        if ($confirmed && $open !== null && $close !== null && $close < $open) {
            $reasons[] = 'Vallend mes — hoog volume maar slotkoers onder openingskoers';
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function isRejectionBounce(array $inputs, float $sma): bool
    {
        $low = self::toFloat($inputs['signal_low'] ?? null);
        $close = self::resolveClosePrice($inputs);

        return $low !== null
            && $close !== null
            && $low < $sma
            && $close > $sma;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function resolveClosePrice(array $inputs): ?float
    {
        return self::toFloat($inputs['latest_close_price'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function resolveOpenPrice(array $inputs): ?float
    {
        return self::toFloat($inputs['latest_open_price'] ?? null);
    }

    /**
     * SQL rank for setup grade sorting:
     * 1 = A+, 2 = A-, 3 = B/C (zwakke score), 4 = B/C (hard fail), 5 = incomplete data.
     */
    public static function setupGradeSortRankSql(): string
    {
        return <<<'SQL'
CASE
    WHEN (signal_low IS NULL AND latest_close_price IS NULL) OR latest_sma_20 IS NULL OR scout_rsi IS NULL THEN 5
    WHEN scout_rsi > 70 THEN 4
    WHEN latest_close_price IS NOT NULL AND latest_sma_20 IS NOT NULL AND latest_close_price < latest_sma_20 THEN 4
    WHEN bounce_volume_above_average = 1 AND latest_open_price IS NOT NULL AND latest_close_price IS NOT NULL AND latest_close_price < latest_open_price THEN 4
    WHEN last_setup_score = 7 THEN 1
    WHEN last_setup_score >= 5 THEN 2
    ELSE 3
END
SQL;
    }

    /**
     * @param  array<int, string>  $hardFailReasons
     * @return array{0: string, 1: string}
     */
    private static function resolveGrade(int $totalPoints, array $hardFailReasons): array
    {
        if ($hardFailReasons !== []) {
            return ['B/C', 'B/C Setup'];
        }

        if ($totalPoints === 7) {
            return ['A+', 'A+ SETUP'];
        }

        if ($totalPoints >= 5) {
            return ['A-', 'A- Setup'];
        }

        return ['B/C', 'B/C Setup'];
    }

    /**
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function criterion(
        string $key,
        string $label,
        int $points,
        int $maxPoints,
        string $status,
        string $detail,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'points' => $points,
            'maxPoints' => $maxPoints,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
