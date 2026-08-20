<?php

namespace App\Support;

use App\Enums\TradeDirection;

class ScoutSetupScorecard
{
    public static function maxPoints(): int
    {
        return (int) config('vestix.sniper_scorecard.max_points', 10);
    }

    /**
     * @param  array{
     *     direction?: TradeDirection|string|null,
     *     signal_low?: float|null,
     *     signal_high?: float|null,
     *     entry_price?: float|null,
     *     latest_atr_14?: float|null,
     *     latest_open_price?: float|null,
     *     latest_close_price?: float|null,
     *     post_signal_high?: float|null,
     *     post_signal_low?: float|null,
     *     previous_close?: float|null,
     *     latest_sma_20?: float|null,
     *     sma_20_five_days_ago?: float|null,
     *     sma_20_ten_days_ago?: float|null,
     *     latest_sma_50?: float|null,
     *     scout_rsi?: float|null,
     *     bounce_volume_above_average?: bool|null,
     *     relative_volume?: float|null,
     *     bounce_day_volume?: int|null,
     *     volume_sma_20?: int|null,
     *     sector_etf?: string|null,
     *     sector_trend_positive?: bool|null,
     *     pre_bounce_extension_atr?: float|null,
     *     days_until_earnings?: int|null,
     *     in_earnings_quarantine?: bool|null,
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
        $criteria = self::isShort($inputs)
            ? [
                self::scoreTrampolineShort($inputs),
                self::scoreSmaDirectionShort($inputs),
                self::scoreRsi($inputs),
                self::scoreVolumeShort($inputs),
                self::scoreSectorShort($inputs),
                self::scoreExtension($inputs),
            ]
            : [
                self::scoreTrampoline($inputs),
                self::scoreSmaDirection($inputs),
                self::scoreRsi($inputs),
                self::scoreVolume($inputs),
                self::scoreSector($inputs),
                self::scoreExtension($inputs),
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
     */
    private static function isShort(array $inputs): bool
    {
        $direction = $inputs['direction'] ?? TradeDirection::Long;

        if ($direction instanceof TradeDirection) {
            return $direction->isShort();
        }

        return TradeDirection::tryFrom((string) $direction) === TradeDirection::Short;
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

        $approachFail = self::longApproachFailReason($inputs);

        if ($approachFail !== null) {
            return self::criterion('trampoline', 'Trampoline-afstand', 0, 2, 'fail', $approachFail);
        }

        if ($close < $sma) {
            if (self::isTrampolineNearMiss($inputs)) {
                $belowPct = (($sma - $close) / $sma) * 100;

                return self::criterion(
                    'trampoline',
                    'Trampoline-afstand',
                    1,
                    2,
                    'warn',
                    sprintf('Voordeel van de twijfel — close %.2f%% onder SMA 20 (1/2 pt)', $belowPct),
                );
            }

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
    private static function scoreTrampolineShort(array $inputs): array
    {
        $close = self::resolveClosePrice($inputs);
        $sma = self::toFloat($inputs['latest_sma_20'] ?? null);

        if ($sma === null || $sma <= 0) {
            return self::criterion('trampoline', 'SMA-afwijzing', 0, 2, 'fail', 'Data ontbreekt');
        }

        if ($close === null) {
            return self::criterion('trampoline', 'SMA-afwijzing', 0, 2, 'fail', 'Wacht op slotkoers (Close)');
        }

        if ($close > $sma) {
            return self::criterion('trampoline', 'SMA-afwijzing', 0, 2, 'fail', 'Close boven SMA 20 — geen short-trampoline');
        }

        $rejectionFail = self::shortRejectionFailReason($inputs);

        if ($rejectionFail !== null) {
            return self::criterion('trampoline', 'SMA-afwijzing', 0, 2, 'fail', $rejectionFail);
        }

        $distance = (($sma - $close) / $sma) * 100;

        if ($distance <= 1.5) {
            return self::criterion(
                'trampoline',
                'SMA-afwijzing',
                2,
                2,
                'pass',
                sprintf('Rejection + lange lont — Close %.2f%% onder SMA', $distance),
            );
        }

        if ($distance <= 3.0) {
            return self::criterion(
                'trampoline',
                'SMA-afwijzing',
                1,
                2,
                'warn',
                sprintf('Rejection bevestigd maar %.2f%% onder SMA — suboptimaal', $distance),
            );
        }

        return self::criterion(
            'trampoline',
            'SMA-afwijzing',
            0,
            2,
            'fail',
            sprintf('Rejection maar te ver onder SMA (%.2f%%)', $distance),
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreSmaDirection(array $inputs): array
    {
        $latest = self::toFloat($inputs['latest_sma_20'] ?? null);
        $lookbackDays = self::smaSlopeLookbackDays();
        $tenDaysAgo = self::toFloat($inputs['sma_20_ten_days_ago'] ?? null);
        $sma50 = self::toFloat($inputs['latest_sma_50'] ?? null);
        $minSlopePct = self::smaSlopeMinPct();
        $fiveDayWarn = self::longSlopeWarnReason($inputs);

        if ($latest === null || $tenDaysAgo === null || $sma50 === null) {
            $detail = match (true) {
                $latest !== null && $tenDaysAgo === null => sprintf('Haal marktdata op voor %d-daagse SMA-helling', $lookbackDays),
                $latest !== null && $sma50 === null => 'Haal marktdata op voor SMA 50',
                default => 'Data ontbreekt',
            };

            return self::criterion('sma_direction', 'SMA trend (20/50)', 0, 2, 'fail', $detail);
        }

        if ($latest <= $sma50) {
            return self::criterion('sma_direction', 'SMA trend (20/50)', 0, 2, 'fail', 'SMA 20 onder SMA 50 — korte trend doorboort lange trend');
        }

        if ($tenDaysAgo <= 0) {
            return self::criterion('sma_direction', 'SMA trend (20/50)', 0, 2, 'fail', 'Data ontbreekt');
        }

        $deltaPct = (($latest - $tenDaysAgo) / $tenDaysAgo) * 100;

        if ($deltaPct < $minSlopePct) {
            $detail = $deltaPct < 0
                ? sprintf('Dalende SMA over %d dagen — Δ %.2f%%', $lookbackDays, $deltaPct)
                : sprintf(
                    'SMA-helling te vlak over %d dagen — Δ %.2f%% (min %.2f%%)',
                    $lookbackDays,
                    $deltaPct,
                    $minSlopePct,
                );

            return self::criterion('sma_direction', 'SMA trend (20/50)', 0, 2, 'fail', $detail);
        }

        if ($fiveDayWarn !== null) {
            return self::criterion(
                'sma_direction',
                'SMA trend (20/50)',
                2,
                2,
                'warn',
                sprintf(
                    'Stijgende trend +%.2f%% over %dd + SMA 20 > SMA 50 — %s (visuele check)',
                    $deltaPct,
                    $lookbackDays,
                    $fiveDayWarn,
                ),
            );
        }

        return self::criterion(
            'sma_direction',
            'SMA trend (20/50)',
            2,
            2,
            'pass',
            sprintf('Stijgende trend +%.2f%% over %dd + SMA 20 > SMA 50', $deltaPct, $lookbackDays),
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreSmaDirectionShort(array $inputs): array
    {
        $waterfallWarn = self::shortWaterfallWarnReason($inputs);

        if ($waterfallWarn !== null) {
            return self::criterion('sma_direction', 'SMA-waterval', 0, 2, 'warn', $waterfallWarn);
        }

        $latest = self::toFloat($inputs['latest_sma_20'] ?? null);
        $fiveDaysAgo = self::toFloat($inputs['sma_20_five_days_ago'] ?? null);
        $tenDaysAgo = self::toFloat($inputs['sma_20_ten_days_ago'] ?? null);

        return self::criterion(
            'sma_direction',
            'SMA-waterval',
            2,
            2,
            'pass',
            sprintf(
                'Glijbaan bevestigd — SMA 20 %.2f < 5d %.2f < 10d %.2f + SMA 20 < SMA 50',
                $latest,
                $fiveDaysAgo,
                $tenDaysAgo,
            ),
        );
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

        if (self::isShort($inputs)) {
            if ($rsi < 30) {
                return self::criterion('rsi', 'RSI sweet spot', 0, 2, 'fail', sprintf('RSI %.1f — oversold (<30)', $rsi));
            }

            if ($rsi >= 40 && $rsi <= 55) {
                return self::criterion('rsi', 'RSI sweet spot', 2, 2, 'pass', sprintf('RSI %.1f — ultieme cooldown zone', $rsi));
            }

            if ($rsi > 55 && $rsi <= 65) {
                return self::criterion('rsi', 'RSI sweet spot', 1, 2, 'warn', sprintf('RSI %.1f — nog momentum', $rsi));
            }

            return self::criterion('rsi', 'RSI sweet spot', 0, 2, 'fail', sprintf('RSI %.1f — buiten sweet spot', $rsi));
        }

        if ($rsi > 70) {
            return self::criterion('rsi', 'RSI sweet spot', 0, 2, 'fail', sprintf('RSI %.1f — oververhit (>70)', $rsi));
        }

        if ($rsi >= 40 && $rsi <= 55) {
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
        $rvol = self::toFloat(RelativeVolumeCalculator::normalizeRatio($inputs['relative_volume'] ?? null));
        $open = self::resolveOpenPrice($inputs);
        $close = self::resolveClosePrice($inputs);

        if ($open === null || $close === null) {
            return self::criterion('volume', 'Volume-overtuiging', 0, 1, 'fail', 'Open/slotkoers ontbreekt voor volume-check');
        }

        if (self::isFallingKnife($inputs, $open, $close)) {
            return self::criterion('volume', 'Volume-overtuiging', 0, 1, 'fail', 'Vallend mes: hoog volume maar slotkoers onder openingskoers');
        }

        $buyerDominant = $close >= $open || self::hasBuyerDominantClose($inputs);

        if ($buyerDominant) {
            if ($rvol === null) {
                return self::criterion(
                    'volume',
                    'Volume-overtuiging',
                    0,
                    1,
                    'warn',
                    'RVol ontbreekt — wacht op data (geen institutionele bevestiging)',
                );
            }

            $threshold = RelativeVolumeCalculator::rvolThreshold();

            if ($rvol < $threshold) {
                return self::criterion(
                    'volume',
                    'Volume-overtuiging',
                    0,
                    1,
                    'warn',
                    sprintf(
                        'RVol %s — onder drempel (%s); geen institutionele bevestiging',
                        RelativeVolumeCalculator::formatPercent($rvol) ?? '—',
                        RelativeVolumeCalculator::formatThresholdPercent(),
                    ),
                );
            }

            $detail = $close >= $open
                ? sprintf(
                    'RVol %s — geen institutionele dump (groene kaars)',
                    RelativeVolumeCalculator::formatPercent($rvol) ?? '—',
                )
                : sprintf(
                    'RVol %s — absorptie op rode hamer (sterke close)',
                    RelativeVolumeCalculator::formatPercent($rvol) ?? '—',
                );

            return self::criterion(
                'volume',
                'Volume-overtuiging',
                1,
                1,
                'pass',
                $detail,
            );
        }

        return self::criterion('volume', 'Volume-overtuiging', 0, 1, 'warn', 'Rode kaars — geen volume-bevestiging');
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreVolumeShort(array $inputs): array
    {
        $rvol = self::toFloat(RelativeVolumeCalculator::normalizeRatio($inputs['relative_volume'] ?? null));
        $open = self::resolveOpenPrice($inputs);
        $close = self::resolveClosePrice($inputs);

        if ($open === null || $close === null) {
            return self::criterion('volume', 'Volume-overtuiging', 0, 1, 'fail', 'Open/slotkoers ontbreekt voor volume-check');
        }

        if (self::isRisingRocket($inputs, $open, $close)) {
            return self::criterion('volume', 'Volume-overtuiging', 0, 1, 'fail', 'Stijgende raket: hoog volume maar slotkoers boven openingskoers');
        }

        // Red candle, or green shooting star with seller-dominant close (Röntgenfoto).
        $sellerDominant = $close <= $open || self::hasBuyerDominantClose($inputs);

        if ($sellerDominant) {
            if ($rvol === null) {
                return self::criterion(
                    'volume',
                    'Volume-overtuiging',
                    0,
                    1,
                    'warn',
                    'RVol ontbreekt — wacht op data (geen institutionele bevestiging)',
                );
            }

            $threshold = RelativeVolumeCalculator::rvolThreshold();

            if ($rvol < $threshold) {
                return self::criterion(
                    'volume',
                    'Volume-overtuiging',
                    0,
                    1,
                    'warn',
                    sprintf(
                        'RVol %s — onder drempel (%s); geen institutionele bevestiging',
                        RelativeVolumeCalculator::formatPercent($rvol) ?? '—',
                        RelativeVolumeCalculator::formatThresholdPercent(),
                    ),
                );
            }

            $detail = $close <= $open
                ? sprintf(
                    'RVol %s — geen institutionele koop (rode kaars)',
                    RelativeVolumeCalculator::formatPercent($rvol) ?? '—',
                )
                : sprintf(
                    'RVol %s — distributie op groene shooting star (sterke close)',
                    RelativeVolumeCalculator::formatPercent($rvol) ?? '—',
                );

            return self::criterion(
                'volume',
                'Volume-overtuiging',
                1,
                1,
                'pass',
                $detail,
            );
        }

        return self::criterion('volume', 'Volume-overtuiging', 0, 1, 'warn', 'Groene kaars — geen volume-bevestiging voor short');
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreSector(array $inputs): array
    {
        $etf = $inputs['sector_etf'] ?? null;
        $trendPositive = (bool) ($inputs['sector_trend_positive'] ?? false);

        if (! is_string($etf) || trim($etf) === '') {
            return self::criterion('sector', 'Sector-synchronisatie', 0, 2, 'fail', 'Sector ETF ontbreekt — haal marktdata op');
        }

        if ($trendPositive) {
            return self::criterion(
                'sector',
                'Sector-synchronisatie',
                2,
                2,
                'pass',
                sprintf('Sector Windkracht: Mee (%s > SMA 50)', strtoupper($etf)),
            );
        }

        return self::criterion(
            'sector',
            'Sector-synchronisatie',
            0,
            2,
            'fail',
            sprintf('Tegenwind (%s < SMA 50)', strtoupper($etf)),
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreSectorShort(array $inputs): array
    {
        $etf = $inputs['sector_etf'] ?? null;
        $trendPositive = (bool) ($inputs['sector_trend_positive'] ?? false);

        if (! is_string($etf) || trim($etf) === '') {
            return self::criterion('sector', 'Sector-synchronisatie', 0, 2, 'fail', 'Sector ETF ontbreekt — haal marktdata op');
        }

        if (! $trendPositive) {
            return self::criterion(
                'sector',
                'Sector-synchronisatie',
                2,
                2,
                'pass',
                sprintf('Sector Tegenwind: bearish (%s < SMA 50)', strtoupper($etf)),
            );
        }

        return self::criterion(
            'sector',
            'Sector-synchronisatie',
            0,
            2,
            'fail',
            sprintf('Meewind (%s > SMA 50)', strtoupper($etf)),
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}
     */
    private static function scoreExtension(array $inputs): array
    {
        $extension = self::toFloat($inputs['pre_bounce_extension_atr'] ?? null);
        $threshold = PreBounceExtensionCalculator::extensionThreshold();

        if ($extension === null) {
            return self::criterion('extension', 'Elastiek-extensie', 0, 1, 'fail', 'Data ontbreekt');
        }

        if (PreBounceExtensionCalculator::meetsThreshold($extension)) {
            return self::criterion(
                'extension',
                'Elastiek-extensie',
                1,
                1,
                'pass',
                sprintf('Rekkracht: +%.1f ATR — hoge veer-potentie', $extension),
            );
        }

        return self::criterion(
            'extension',
            'Elastiek-extensie',
            0,
            1,
            'fail',
            sprintf('Rekkracht: +%.1f ATR — onvoldoende spanning (min %.1f)', $extension, $threshold),
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<int, string>
     */
    private static function resolveHardFailReasons(array $inputs): array
    {
        if (self::isShort($inputs)) {
            return self::resolveHardFailReasonsShort($inputs);
        }

        $reasons = [];

        $rsi = self::toFloat($inputs['scout_rsi'] ?? null);

        if ($rsi !== null && $rsi > 70) {
            $reasons[] = 'RSI oververhit (>70) — geen A-setup mogelijk';
        }

        $open = self::resolveOpenPrice($inputs);
        $close = self::resolveClosePrice($inputs);
        $sma = self::toFloat($inputs['latest_sma_20'] ?? null);

        if ($close !== null && $sma !== null && $close < $sma && ! self::isTrampolineNearMiss($inputs)) {
            $reasons[] = 'Close onder SMA 20 — trampoline gebroken';
        }

        $approachFail = self::longApproachFailReason($inputs);

        if ($approachFail !== null) {
            $reasons[] = $approachFail;
        }

        if ($open !== null && $close !== null && self::isFallingKnife($inputs, $open, $close)) {
            $reasons[] = 'Vallend mes — hoog volume maar slotkoers onder openingskoers';
        }

        $breakoutFail = self::failedBreakoutFailReason($inputs);

        if ($breakoutFail !== null) {
            $reasons[] = $breakoutFail;
        }

        // SMA-helling is operator domein (Protocol Visuele Eindregie) — geen hard-fail.

        return array_merge(
            $reasons,
            self::resolveCandleAnatomyHardFail($inputs),
            self::resolveFreeAirspaceHardFail($inputs),
            self::resolveEarningsHardFail($inputs),
        );
    }

    /**
     * Long trampoline approach: Open near/above SMA20, or previous close above SMA20.
     * Otherwise the candle pierced the SMA from below (sloopkogel), not a bounce from above.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function longApproachFailReason(array $inputs): ?string
    {
        if (! self::trampolineApproachHardFailEnabled()) {
            return null;
        }

        $sma = self::toFloat($inputs['latest_sma_20'] ?? null);
        $open = self::resolveOpenPrice($inputs);

        if ($sma === null || $sma <= 0) {
            return null;
        }

        if ($open === null) {
            return 'Openingskoers ontbreekt voor trampoline-aanvlieg check';
        }

        $openFloor = $sma * (1 - (self::trampolineOpenTolerancePct() / 100));

        if ($open >= $openFloor) {
            return null;
        }

        $previousClose = self::toFloat($inputs['previous_close'] ?? null);

        if ($previousClose !== null && $previousClose >= $sma) {
            return null;
        }

        if ($previousClose !== null) {
            return sprintf(
                'Sloopkogel — Open $%.2f onder SMA 20 $%.2f (vorige close $%.2f ook onder; doorboring van onderaf)',
                $open,
                $sma,
                $previousClose,
            );
        }

        return sprintf(
            'Sloopkogel — Open $%.2f onder SMA 20 $%.2f (doorboring van onderaf)',
            $open,
            $sma,
        );
    }

    /**
     * Signal already traded through the planned stop, then closed back on the origin side.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function failedBreakoutFailReason(array $inputs): ?string
    {
        if (! self::failedBreakoutHardFailEnabled()) {
            return null;
        }

        $entry = self::resolvePlannedEntry($inputs);
        $close = self::resolveClosePrice($inputs);

        if ($entry === null || $close === null) {
            return null;
        }

        if (self::isShort($inputs)) {
            $low = self::resolvePostSignalLow($inputs);

            if ($low === null || $low > $entry || $close <= $entry) {
                return null;
            }

            return sprintf(
                'Breakdown gefaald — sell-stop $%s al geraakt (low $%s), close $%s terug boven entry',
                number_format($entry, 2),
                number_format($low, 2),
                number_format($close, 2),
            );
        }

        $high = self::resolvePostSignalHigh($inputs);

        if ($high === null || $high < $entry || $close >= $entry) {
            return null;
        }

        return sprintf(
            'Breakout gefaald — buy-stop $%s al geraakt (high $%s), close $%s terug onder entry',
            number_format($entry, 2),
            number_format($high, 2),
            number_format($close, 2),
        );
    }

    public static function failedBreakoutHardFailEnabled(): bool
    {
        return (bool) config('vestix.sniper_scorecard.failed_breakout_hard_fail', true);
    }

    /**
     * Long 5d SMA-helling waarschuwing (geen veto — Protocol Visuele Eindregie).
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function longSlopeWarnReason(array $inputs): ?string
    {
        $latest = self::toFloat($inputs['latest_sma_20'] ?? null);
        $fiveDaysAgo = self::toFloat($inputs['sma_20_five_days_ago'] ?? null);
        $lookbackDays = self::smaSlopeHardFailLookbackDays();
        $minPct = self::smaSlopeHardFailMinPct();

        if ($latest === null || $fiveDaysAgo === null) {
            return match (true) {
                $latest !== null && $fiveDaysAgo === null => sprintf(
                    'Haal marktdata op voor %d-daagse SMA-helling',
                    $lookbackDays,
                ),
                default => null,
            };
        }

        if ($fiveDaysAgo <= 0) {
            return 'SMA-helling data ontbreekt';
        }

        $deltaPct = (($latest - $fiveDaysAgo) / $fiveDaysAgo) * 100;

        if ($deltaPct > $minPct) {
            return null;
        }

        if ($deltaPct < 0) {
            return sprintf(
                'SMA-helling dalend over %d dagen — Δ %.2f%%',
                $lookbackDays,
                $deltaPct,
            );
        }

        if ($minPct <= 0.0) {
            return sprintf(
                'SMA-helling te vlak over %d dagen — Δ %.2f%%',
                $lookbackDays,
                $deltaPct,
            );
        }

        return sprintf(
            'SMA-helling te vlak over %d dagen — Δ %.2f%% (min %.2f%%)',
            $lookbackDays,
            $deltaPct,
            $minPct,
        );
    }

    /**
     * @deprecated Use longSlopeWarnReason — 5d helling is geen hard-fail meer.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function longSlopeFailReason(array $inputs): ?string
    {
        if (! self::smaSlopeHardFailEnabled()) {
            return null;
        }

        return self::longSlopeWarnReason($inputs);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<int, string>
     */
    private static function resolveHardFailReasonsShort(array $inputs): array
    {
        $reasons = [];

        $rsi = self::toFloat($inputs['scout_rsi'] ?? null);

        if ($rsi !== null && $rsi < 30) {
            $reasons[] = 'RSI oversold (<30) — geen A-setup mogelijk';
        }

        $open = self::resolveOpenPrice($inputs);
        $close = self::resolveClosePrice($inputs);
        $sma = self::toFloat($inputs['latest_sma_20'] ?? null);

        // Close above SMA is always a veto for shorts — no near-miss escape.
        if ($close !== null && $sma !== null && $close > $sma) {
            $reasons[] = 'Close boven SMA 20 — geen short-trampoline';
        }

        // SMA-waterval is operator domein (Protocol Visuele Eindregie) — geen hard-fail.

        // Only evaluate rejection when close is not already above SMA (separate veto).
        if ($close === null || $sma === null || $close <= $sma) {
            $rejectionFail = self::shortRejectionFailReason($inputs);

            if ($rejectionFail !== null) {
                $reasons[] = $rejectionFail;
            }
        }

        if ($open !== null && $close !== null && self::isRisingRocket($inputs, $open, $close)) {
            $reasons[] = 'Stijgende raket — hoog volume maar slotkoers boven openingskoers';
        }

        $breakoutFail = self::failedBreakoutFailReason($inputs);

        if ($breakoutFail !== null) {
            $reasons[] = $breakoutFail;
        }

        return array_merge(
            $reasons,
            self::resolveCandleAnatomyHardFail($inputs),
            self::resolveFreeAirspaceHardFail($inputs),
            self::resolveEarningsHardFail($inputs),
        );
    }

    /**
     * Short SMA-waterval waarschuwing (geen veto — Protocol Visuele Eindregie).
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function shortWaterfallWarnReason(array $inputs): ?string
    {
        $latest = self::toFloat($inputs['latest_sma_20'] ?? null);
        $fiveDaysAgo = self::toFloat($inputs['sma_20_five_days_ago'] ?? null);
        $tenDaysAgo = self::toFloat($inputs['sma_20_ten_days_ago'] ?? null);
        $sma50 = self::toFloat($inputs['latest_sma_50'] ?? null);

        if ($latest === null || $fiveDaysAgo === null || $tenDaysAgo === null || $sma50 === null) {
            return match (true) {
                $latest !== null && $fiveDaysAgo === null => 'Haal marktdata op voor 5-daagse SMA-waterval',
                $latest !== null && $tenDaysAgo === null => 'Haal marktdata op voor 10-daagse SMA-waterval',
                $latest !== null && $sma50 === null => 'Haal marktdata op voor SMA 50',
                default => 'SMA-waterval data ontbreekt',
            };
        }

        if ($latest >= $sma50) {
            return 'SMA 20 boven SMA 50 — geen bearish trendstructuur';
        }

        if (! ($latest < $fiveDaysAgo && $fiveDaysAgo < $tenDaysAgo)) {
            return 'SMA-waterval ontbreekt — geen glijbaan (chop-risico)';
        }

        return null;
    }

    /**
     * @deprecated Use shortWaterfallWarnReason — waterval is geen hard-fail meer.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function shortWaterfallFailReason(array $inputs): ?string
    {
        if (! self::waterfallRequired()) {
            return null;
        }

        return self::shortWaterfallWarnReason($inputs);
    }

    /**
     * Short plafond + upper-wick rejection (red candle or green shooting star).
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function shortRejectionFailReason(array $inputs): ?string
    {
        $high = self::toFloat($inputs['signal_high'] ?? null);
        $open = self::resolveOpenPrice($inputs);
        $close = self::resolveClosePrice($inputs);
        $sma = self::toFloat($inputs['latest_sma_20'] ?? null);

        if ($high === null || $open === null || $close === null || $sma === null || $sma <= 0) {
            return match (true) {
                $high === null => 'Signal High ontbreekt voor SMA-afwijzing',
                $open === null => 'Openingskoers ontbreekt voor wick-check',
                $close === null => 'Slotkoers ontbreekt voor SMA-afwijzing',
                default => 'SMA data ontbreekt voor afwijzing',
            };
        }

        if ($high < $sma) {
            return 'Geen SMA-afwijzing — High raakt plafond niet';
        }

        if ($close >= $sma) {
            return 'Geen SMA-afwijzing — Close moet onder SMA 20';
        }

        if (! self::hasSufficientUpperWickShort($high, $open, $close)) {
            return 'Upper wick te kort — geen institutionele afstraffing';
        }

        return null;
    }

    public static function hasSufficientUpperWickShort(float $high, float $open, float $close): bool
    {
        $body = abs($open - $close);
        $bodyFloor = max($body, abs($close) * (self::upperWickBodyFloorPct() / 100));

        if ($bodyFloor <= 0) {
            return false;
        }

        // Wick above the body top — works for both red (open) and green (close) candles.
        $upperWick = $high - max($open, $close);

        return $upperWick >= self::upperWickMinBodyRatio() * $bodyFloor;
    }

    public static function waterfallRequired(): bool
    {
        return (bool) config('vestix.sniper_scorecard.waterfall_required', true);
    }

    public static function upperWickMinBodyRatio(): float
    {
        return (float) config('vestix.sniper_scorecard.upper_wick_min_body_ratio', 1.5);
    }

    public static function upperWickBodyFloorPct(): float
    {
        return (float) config('vestix.sniper_scorecard.upper_wick_body_floor_pct', 0.1);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<int, string>
     */
    private static function resolveCandleAnatomyHardFail(array $inputs): array
    {
        if (! CandleAnatomy::hardFail()) {
            return [];
        }

        $reason = CandleAnatomy::failReason($inputs);

        return $reason !== null ? [$reason] : [];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<int, string>
     */
    private static function resolveFreeAirspaceHardFail(array $inputs): array
    {
        if (! FreeAirspaceScanner::hardFail()) {
            return [];
        }

        $reason = FreeAirspaceScanner::blockadeReason($inputs);

        return $reason !== null ? [$reason] : [];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<int, string>
     */
    private static function resolveEarningsHardFail(array $inputs): array
    {
        if (($inputs['in_earnings_quarantine'] ?? false) === true) {
            return [EarningsExitSchedule::entryQuarantineHardFailMessage()];
        }

        $daysUntil = array_key_exists('days_until_earnings', $inputs)
            ? $inputs['days_until_earnings']
            : null;

        if ($daysUntil === null) {
            return [];
        }

        $windowDays = (int) config('vestix.pre_earnings_trailing.window_days', EarningsExitDisplay::ALERT_WINDOW_DAYS);

        if ($daysUntil >= 0 && $daysUntil <= $windowDays) {
            return ["Earnings over {$daysUntil} dagen — te weinig runway voor entry"];
        }

        return [];
    }

    /**
     * Green candle, or red hammer with buyer-dominant close (Röntgenfoto), closing
     * only a fraction below SMA 20 — benefit of the doubt.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function isTrampolineNearMiss(array $inputs): bool
    {
        $close = self::resolveClosePrice($inputs);
        $open = self::resolveOpenPrice($inputs);
        $sma = self::toFloat($inputs['latest_sma_20'] ?? null);
        $threshold = self::trampolineNearMissPct();

        if ($close === null || $sma === null || $sma <= 0 || $threshold <= 0) {
            return false;
        }

        if ($close >= $sma) {
            return false;
        }

        if ($open === null) {
            return false;
        }

        if ($close < $open && ! self::hasBuyerDominantClose($inputs)) {
            return false;
        }

        $belowPct = (($sma - $close) / $sma) * 100;

        return $belowPct <= $threshold;
    }

    /**
     * Red candle that closes only a fraction above SMA 20 — benefit of the doubt for shorts.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function isTrampolineNearMissShort(array $inputs): bool
    {
        $close = self::resolveClosePrice($inputs);
        $open = self::resolveOpenPrice($inputs);
        $sma = self::toFloat($inputs['latest_sma_20'] ?? null);
        $threshold = self::trampolineNearMissPct();

        if ($close === null || $sma === null || $sma <= 0 || $threshold <= 0) {
            return false;
        }

        if ($close <= $sma) {
            return false;
        }

        if ($open === null || $close >= $open) {
            return false;
        }

        $abovePct = (($close - $sma) / $sma) * 100;

        return $abovePct <= $threshold;
    }

    public static function trampolineNearMissPct(): float
    {
        return (float) config('vestix.sniper_scorecard.trampoline_near_miss_pct', 0.25);
    }

    public static function trampolineApproachHardFailEnabled(): bool
    {
        return (bool) config('vestix.sniper_scorecard.trampoline_approach_hard_fail_enabled', true);
    }

    public static function trampolineOpenTolerancePct(): float
    {
        return (float) config('vestix.sniper_scorecard.trampoline_open_tolerance_pct', 0.2);
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
    private static function isRejectionBounceShort(array $inputs, float $sma): bool
    {
        $high = self::toFloat($inputs['signal_high'] ?? null);
        $close = self::resolveClosePrice($inputs);

        return $high !== null
            && $close !== null
            && $high >= $sma
            && $close < $sma;
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
     * 1 = A++, 2 = A, 3 = B, 4 = C, 5 = NO TRADE, 6 = incomplete data.
     */
    public static function setupGradeSortRankSql(): string
    {
        $maxPoints = self::maxPoints();
        $nearMissFactor = 1 - (self::trampolineNearMissPct() / 100);
        $minClosingPct = CandleAnatomy::minClosingPct();

        // Prefer persisted last_setup_grade (matches live scorecard hard-fails).
        // Fallback heuristics remain for rows not yet re-synced after the grade column was added.
        return <<<SQL
CASE
    WHEN last_setup_grade = 'A++' THEN 1
    WHEN last_setup_grade = 'A' THEN 2
    WHEN last_setup_grade = 'B' THEN 3
    WHEN last_setup_grade = 'C' THEN 4
    WHEN last_setup_grade = 'NO TRADE' THEN 5
    WHEN (signal_low IS NULL AND latest_close_price IS NULL) OR latest_sma_20 IS NULL OR scout_rsi IS NULL THEN 6
    WHEN direction = 'short' AND scout_rsi < 30 THEN 5
    WHEN scout_rsi > 70 AND (direction IS NULL OR direction = 'long') THEN 5
    WHEN direction = 'short'
        AND latest_close_price IS NOT NULL AND latest_sma_20 IS NOT NULL
        AND latest_close_price > latest_sma_20
        THEN 5
    WHEN (direction IS NULL OR direction = 'long')
        AND latest_close_price IS NOT NULL AND latest_sma_20 IS NOT NULL
        AND latest_close_price < latest_sma_20
        AND NOT (
            latest_open_price IS NOT NULL
            AND latest_close_price >= latest_sma_20 * {$nearMissFactor}
            AND (
                latest_close_price >= latest_open_price
                OR (
                    signal_high IS NOT NULL
                    AND signal_low IS NOT NULL
                    AND signal_high > signal_low
                    AND ((latest_close_price - signal_low) / (signal_high - signal_low)) * 100 >= {$minClosingPct}
                )
            )
        )
        THEN 5
    WHEN bounce_volume_above_average = 1
        AND latest_open_price IS NOT NULL
        AND latest_close_price IS NOT NULL
        AND latest_close_price < latest_open_price
        AND (direction IS NULL OR direction = 'long')
        AND (
            signal_high IS NULL
            OR signal_low IS NULL
            OR signal_high <= signal_low
            OR ((latest_close_price - signal_low) / (signal_high - signal_low)) * 100 < {$minClosingPct}
        )
        THEN 5
    WHEN bounce_volume_above_average = 1 AND latest_open_price IS NOT NULL AND latest_close_price IS NOT NULL AND latest_close_price > latest_open_price AND direction = 'short' THEN 5
    WHEN last_setup_score = {$maxPoints} AND trader_promoted_a_plus = 1 THEN 1
    WHEN last_setup_score >= {$maxPoints} - 1 THEN 2
    WHEN last_setup_score >= 8 AND trader_promoted_a = 1 THEN 2
    WHEN last_setup_score >= 7 THEN 3
    WHEN last_setup_score >= 5 THEN 4
    ELSE 5
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
            return ['NO TRADE', 'NO TRADE'];
        }

        if ($totalPoints >= self::maxPoints() - 1) {
            return ['A', 'A SETUP'];
        }

        if ($totalPoints >= 7) {
            return ['B', 'B SETUP'];
        }

        if ($totalPoints >= 5) {
            return ['C', 'C SETUP'];
        }

        return ['NO TRADE', 'NO TRADE'];
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

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function resolvePlannedEntry(array $inputs): ?float
    {
        $entry = self::toFloat($inputs['entry_price'] ?? null);

        if ($entry !== null && $entry > 0) {
            return $entry;
        }

        $atr = self::toFloat($inputs['latest_atr_14'] ?? null);

        if ($atr === null || $atr <= 0) {
            return null;
        }

        if (self::isShort($inputs)) {
            $low = self::toFloat($inputs['signal_low'] ?? null);

            if ($low === null || $low <= 0) {
                return null;
            }

            return round(max(0.01, $low - (0.10 * $atr)), 2);
        }

        $high = self::toFloat($inputs['signal_high'] ?? null);

        if ($high === null || $high <= 0) {
            return null;
        }

        return round($high + (0.10 * $atr), 2);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function resolvePostSignalHigh(array $inputs): ?float
    {
        $high = self::toFloat($inputs['post_signal_high'] ?? null);
        $open = self::resolveOpenPrice($inputs);

        if ($high === null) {
            return $open;
        }

        if ($open === null) {
            return $high;
        }

        return max($high, $open);
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function resolvePostSignalLow(array $inputs): ?float
    {
        $low = self::toFloat($inputs['post_signal_low'] ?? null);
        $open = self::resolveOpenPrice($inputs);

        if ($low === null) {
            return $open;
        }

        if ($open === null) {
            return $low;
        }

        return min($low, $open);
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    public static function smaSlopeLookbackDays(): int
    {
        return (int) config('vestix.sniper_scorecard.sma_slope_lookback_days', 10);
    }

    public static function smaSlopeMinPct(): float
    {
        return (float) config('vestix.sniper_scorecard.sma_slope_min_pct', 0.3);
    }

    public static function smaSlopeHardFailEnabled(): bool
    {
        return (bool) config('vestix.sniper_scorecard.sma_slope_hard_fail_enabled', true);
    }

    public static function smaSlopeHardFailLookbackDays(): int
    {
        return (int) config('vestix.sniper_scorecard.sma_slope_hard_fail_lookback_days', 5);
    }

    public static function smaSlopeHardFailMinPct(): float
    {
        return (float) config('vestix.sniper_scorecard.sma_slope_hard_fail_min_pct', 0.0);
    }

    /**
     * True when Röntgenfoto proves a buyer-dominant close (pct known and ≥ min).
     * Missing OHLC does not count as dominant.
     *
     * @param  array<string, mixed>  $inputs
     */
    private static function hasBuyerDominantClose(array $inputs): bool
    {
        $pct = CandleAnatomy::closingStrengthPct($inputs);

        if ($pct === null) {
            return false;
        }

        return $pct + 1e-9 >= CandleAnatomy::minClosingPct();
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function isFallingKnife(array $inputs, float $open, float $close): bool
    {
        if ($close >= $open) {
            return false;
        }

        // Strong close in range = absorption / hammer, not a falling knife.
        if (self::hasBuyerDominantClose($inputs)) {
            return false;
        }

        $volume = isset($inputs['bounce_day_volume']) ? (int) $inputs['bounce_day_volume'] : null;
        $volumeSma20 = isset($inputs['volume_sma_20']) ? (int) $inputs['volume_sma_20'] : null;

        if ($volume === null || $volumeSma20 === null || $volumeSma20 <= 0) {
            $rvol = self::toFloat(RelativeVolumeCalculator::normalizeRatio($inputs['relative_volume'] ?? null));
            $confirmed = (bool) ($inputs['bounce_volume_above_average'] ?? false);

            return $confirmed || ($rvol !== null && $rvol >= RelativeVolumeCalculator::rvolThreshold());
        }

        return $volume > $volumeSma20;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function isRisingRocket(array $inputs, float $open, float $close): bool
    {
        if ($close <= $open) {
            return false;
        }

        return self::isFallingKnife($inputs, $close, $open);
    }
}
