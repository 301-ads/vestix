<?php

namespace App\Services;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Enums\BrokerOrderStatus;
use App\Enums\PositionVisibility;
use App\Enums\ScoutReviewStatus;
use App\Enums\ScoutSource;
use App\Enums\TradeDirection;
use App\Models\Position;
use App\Models\SniperLiquidityCache;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Support\EarningsExitSchedule;
use App\Support\SniperLocalIndicators;
use App\Support\SniperRejectReasons;
use App\Support\SniperSetupFilter;
use App\Support\UsMarketSession;
use Illuminate\Support\Facades\Log;
use Throwable;

class SniperScanService
{
    public function __construct(
        private readonly SniperGroupedDailyIngestService $ingest,
        private readonly EarningsCalendarSyncService $earningsSync,
        private readonly AssetSyncService $assetSync,
        private readonly SniperLocalIndicators $indicators,
        private readonly MarketDataFetcher $marketDataFetcher,
        private readonly AlertDispatcher $alerts,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     dry_run: bool,
     *     date: string|null,
     *     scanned: int,
     *     liquid: int,
     *     math_hits: int,
     *     earnings_blocked: int,
     *     earnings_capped: int,
     *     created: int,
     *     enriched: int,
     *     notified: int,
     *     deduped: int,
     *     splits_purged: int,
     *     coverage: array{bars_ready: int, with_cap: int, cache_rows: int},
     *     reason?: string,
     * }
     */
    public function run(bool $dryRun = false, ?string $date = null, bool $skipIngest = false): array
    {
        if (! (bool) config('vestix.sniper_scanner.enabled')) {
            return $this->emptyResult(enabled: false, dryRun: $dryRun, reason: 'disabled');
        }

        $ownerId = (int) config('vestix.sniper_scanner.owner_user_id');
        $owner = User::query()->find($ownerId);

        if (! $owner instanceof User) {
            return $this->emptyResult(enabled: true, dryRun: $dryRun, reason: 'owner_missing');
        }

        $splitsPurged = 0;
        $sessionDate = $date ?? UsMarketSession::expectedLastCompletedSessionDate()->toDateString();

        if (! $skipIngest) {
            $ingest = $this->ingest->ingestDate($sessionDate, refreshMetrics: false);
            $sessionDate = $ingest['date'];
            $splitsPurged = $ingest['splits_purged'];

            if ($ingest['skipped']) {
                return $this->emptyResult(
                    enabled: true,
                    dryRun: $dryRun,
                    date: $sessionDate,
                    splitsPurged: $splitsPurged,
                    reason: $ingest['reason'] ?? 'ingest_skipped',
                );
            }

            // Full-history bars_ready after ingest (avoid short-window false negatives).
            $this->ingest->recomputeLiquidityMetrics($sessionDate);
        }

        $minVolume = (int) config('vestix.sniper_scanner.min_volume');
        $minAvg = (int) config('vestix.sniper_scanner.min_avg_volume_30d');
        $minCap = (float) config('vestix.sniper_scanner.min_market_cap');
        $allowlist = array_map('strtoupper', config('vestix.sniper_scanner.etf_allowlist', []));

        $cacheQuery = SniperLiquidityCache::query()
            ->where('enabled', true)
            ->where('bars_ready', true)
            ->where('last_volume', '>', $minVolume)
            ->where('avg_volume_30d', '>', $minAvg)
            ->where(function ($query) use ($allowlist, $minCap): void {
                // Allowlist ETFs (SPY/QQQ/…) mogen zonder Finnhub market cap.
                $query->where(function ($inner) use ($allowlist): void {
                    $inner->whereIn('ticker', $allowlist);
                })->orWhere(function ($inner) use ($minCap): void {
                    $inner->where('asset_type', 'CS')
                        ->where('market_cap', '>', $minCap);
                });
            });

        $liquidRows = $cacheQuery->get();
        $scanned = SniperLiquidityCache::query()->count();
        $mathHits = [];
        $rejectSamples = [];

        foreach ($liquidRows as $row) {
            $indicators = $this->indicators->forTicker($row->ticker);

            if ($indicators === null) {
                continue;
            }

            $direction = SniperSetupFilter::evaluate($indicators);

            if ($direction === null) {
                if (count($rejectSamples) < 25) {
                    $reasons = SniperRejectReasons::forInputs($indicators);
                    if ($reasons !== []) {
                        $rejectSamples[] = [
                            'ticker' => $row->ticker,
                            'reasons' => array_slice($reasons, 0, 3),
                        ];
                    }
                }

                continue;
            }

            if ($direction === 'short' && ! $owner->canUseShort()) {
                continue;
            }

            $distance = abs($indicators['close'] - $indicators['sma20']) / $indicators['sma20'];

            $mathHits[] = [
                'ticker' => $row->ticker,
                'direction' => $direction,
                'indicators' => $indicators,
                'distance' => $distance,
                'volume' => (int) $row->last_volume,
            ];
        }

        usort($mathHits, function (array $a, array $b): int {
            return $a['distance'] <=> $b['distance'] ?: $b['volume'] <=> $a['volume'];
        });

        $maxChecks = (int) config('vestix.sniper_scanner.max_earnings_checks_per_run', 50);
        $earningsCutoff = (int) config('vestix.sniper_scanner.earnings_cutoff_days', 14);
        $finnhubDelay = max(0, (int) config('vestix.finnhub.rate_limit_delay', 1));
        $polygonDelay = max(0, (int) config('vestix.polygon.rate_limit_delay', 13));

        $toCheck = array_slice($mathHits, 0, $maxChecks);
        $earningsCapped = max(0, count($mathHits) - count($toCheck));
        $earningsBlocked = 0;
        $created = 0;
        $enriched = 0;
        $notified = 0;
        $deduped = 0;
        $enrichIndex = 0;

        foreach ($toCheck as $index => $hit) {
            if ($index > 0 && $finnhubDelay > 0) {
                sleep($finnhubDelay);
            }

            $this->earningsSync->syncTicker($hit['ticker'], force: true);
            $asset = $this->assetSync->ensureForTicker($hit['ticker']);
            $earningsDate = $asset->effectiveEarningsDate();

            if (EarningsExitSchedule::isInEntryQuarantine(
                $asset->effectiveLastEarningsDate(),
                $earningsDate,
            )) {
                $earningsBlocked++;

                continue;
            }

            if ($earningsDate !== null) {
                $days = EarningsExitSchedule::daysUntilEarnings($earningsDate);

                if ($days >= 0 && $days <= $earningsCutoff) {
                    $earningsBlocked++;

                    continue;
                }
            }

            $direction = $hit['direction'] === 'short' ? TradeDirection::Short : TradeDirection::Long;

            if (Position::userHasPersonalScoutWith($owner->id, $hit['ticker'], $direction)) {
                $deduped++;

                continue;
            }

            if ($dryRun) {
                $created++;

                continue;
            }

            $indicators = $hit['indicators'];

            $position = Position::query()->create([
                'user_id' => $owner->id,
                'ticker' => $hit['ticker'],
                'status' => 'scout',
                'source' => ScoutSource::SniperScan->value,
                'review_status' => ScoutReviewStatus::PendingVisualReview->value,
                'direction' => $direction->value,
                'visibility' => PositionVisibility::Private->value,
                'broker' => $owner->primary_broker?->value ?? Broker::Revolut->value,
                'broker_order_status' => BrokerOrderStatus::Scout->value,
                'signal_high' => $indicators['high'],
                'signal_low' => $indicators['low'],
                'signal_bar_date' => $indicators['date'],
                'detected_signal_bar_date' => $indicators['date'],
                'entry_price' => $indicators['high'],
                'latest_open_price' => $indicators['open'],
                'latest_close_price' => $indicators['close'],
                'latest_sma_20' => $indicators['sma20'],
                'latest_sma_50' => $indicators['sma50'],
                'scout_rsi' => $indicators['rsi14'],
                'bounce_day_volume' => $indicators['volume'],
            ]);

            $created++;

            if ($enrichIndex > 0 && $polygonDelay > 0) {
                sleep($polygonDelay);
            }

            if ($this->enrichCreatedScout($position)) {
                $enriched++;
            }

            $enrichIndex++;

            if ($this->notifyCreatedScout($owner, $position->fresh() ?? $position)) {
                $notified++;
            }
        }

        $coverage = [
            'bars_ready' => SniperLiquidityCache::query()->where('bars_ready', true)->count(),
            'with_cap' => SniperLiquidityCache::query()->whereNotNull('market_cap')->count(),
            'cache_rows' => SniperLiquidityCache::query()->count(),
        ];

        $summary = [
            'enabled' => true,
            'dry_run' => $dryRun,
            'date' => $sessionDate,
            'scanned' => $scanned,
            'liquid' => $liquidRows->count(),
            'math_hits' => count($mathHits),
            'earnings_blocked' => $earningsBlocked,
            'earnings_capped' => $earningsCapped,
            'created' => $created,
            'enriched' => $enriched,
            'notified' => $notified,
            'deduped' => $deduped,
            'splits_purged' => $splitsPurged,
            'coverage' => $coverage,
            'reject_samples' => $rejectSamples,
        ];

        if ($owner instanceof User && $rejectSamples !== []) {
            $prefs = is_array($owner->ui_preferences) ? $owner->ui_preferences : [];
            $prefs['sniper_last_rejects'] = [
                'date' => $sessionDate,
                'samples' => $rejectSamples,
            ];
            $owner->forceFill(['ui_preferences' => $prefs])->save();
        }

        Log::info('Sniper scan completed.', $summary);

        return $summary;
    }

    private function enrichCreatedScout(Position $position): bool
    {
        $synced = false;

        try {
            $synced = $this->marketDataFetcher->syncPosition($position, withDelays: false);
            $position->refresh();
        } catch (Throwable $exception) {
            Log::warning('Sniper scout enrich failed.', [
                'position_id' => $position->id,
                'ticker' => $position->ticker,
                'error' => $exception->getMessage(),
            ]);
        }

        $scorecard = $position->evaluateSetupScore();
        $position->update(['last_setup_score' => $scorecard['totalPoints']]);
        $position->refresh();

        return $synced;
    }

    private function notifyCreatedScout(User $owner, Position $position): bool
    {
        $scorecard = $position->evaluateSetupScore();
        $context = [
            'total_points' => $scorecard['totalPoints'],
            'max_points' => $scorecard['maxPoints'],
            'grade_label' => $scorecard['gradeLabel'],
        ];

        if ($this->alerts->dispatchNow(
            $owner->id,
            $position->id,
            AlertEventType::SniperScanTarget,
            $context,
        )) {
            return true;
        }

        // Existing prefs may predate sniper_scan_target; reuse digest toggle as fallback.
        if (! $this->ownerAllowsSniperDigestFallback($owner)) {
            return false;
        }

        $message = sprintf(
            '🎯 Nieuw sniper-doelwit: %s %s — Score: %d/%d (%s). Bekijk Visuele Review in Mijn Radar.',
            $position->ticker,
            $position->isShort() ? 'Short' : 'Long',
            $scorecard['totalPoints'],
            $scorecard['maxPoints'],
            $scorecard['gradeLabel'],
        );

        $this->alerts->dispatchUserEvent($owner, AlertEventType::SniperScanDigest, $message);

        return true;
    }

    private function ownerAllowsSniperDigestFallback(User $owner): bool
    {
        UserAlertPreference::ensureDefaultsForUser($owner);
        $owner->unsetRelation('alertPreferences');
        $owner->load('alertPreferences');

        foreach ($owner->alertPreferences as $preference) {
            if ($preference->hasEventEnabled(AlertEventType::SniperScanDigest)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(
        bool $enabled,
        bool $dryRun,
        ?string $date = null,
        int $splitsPurged = 0,
        ?string $reason = null,
    ): array {
        return [
            'enabled' => $enabled,
            'dry_run' => $dryRun,
            'date' => $date,
            'scanned' => 0,
            'liquid' => 0,
            'math_hits' => 0,
            'earnings_blocked' => 0,
            'earnings_capped' => 0,
            'created' => 0,
            'enriched' => 0,
            'notified' => 0,
            'deduped' => 0,
            'splits_purged' => $splitsPurged,
            'coverage' => [
                'bars_ready' => 0,
                'with_cap' => 0,
                'cache_rows' => 0,
            ],
            'reason' => $reason,
        ];
    }
}
