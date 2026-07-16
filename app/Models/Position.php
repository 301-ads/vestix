<?php

namespace App\Models;

use App\Enums\Broker;
use App\Enums\BrokerOrderStatus;
use App\Enums\EarningsExitUrgency;
use App\Enums\ExecutionDigestStatus;
use App\Enums\PositionVisibility;
use App\Enums\PremarketScanResult;
use App\Enums\ScoutPipelineStatus;
use App\Enums\TrailingStopMode;
use App\Services\AssetSyncService;
use App\Support\EarningsExitSchedule;
use App\Support\ScoutSetupScorecard;
use App\Support\StopLossProtocol;
use App\Support\UsMarketSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class Position extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $appends = ['new_sl', 'action_command'];

    protected function casts(): array
    {
        return [
            'entry_price' => 'decimal:2',
            'quantity' => 'decimal:6',
            'risk_budget' => 'decimal:2',
            'risk_percent' => 'decimal:2',
            'current_sl' => 'decimal:2',
            'latest_open_price' => 'decimal:2',
            'latest_close_price' => 'decimal:2',
            'recent_close_prices' => 'array',
            'exit_price' => 'decimal:2',
            'latest_sma_20' => 'decimal:2',
            'latest_sma_50' => 'decimal:2',
            'sma_20_five_days_ago' => 'decimal:2',
            'sma_20_ten_days_ago' => 'decimal:2',
            'latest_atr_14' => 'decimal:2',
            'prior_day_low' => 'decimal:2',
            'signal_high' => 'decimal:2',
            'signal_low' => 'decimal:2',
            'scout_rsi' => 'decimal:2',
            'bounce_volume_above_average' => 'boolean',
            'relative_volume' => 'decimal:2',
            'sector_close' => 'decimal:4',
            'sector_sma_50' => 'decimal:4',
            'sector_trend_positive' => 'boolean',
            'pre_bounce_extension_atr' => 'decimal:2',
            'last_setup_score' => 'integer',
            'telegram_a_minus_alert_sent_at' => 'datetime',
            'telegram_a_plus_alert_sent_at' => 'datetime',
            'trader_promoted_a_plus' => 'boolean',
            'trader_promoted_a_plus_at' => 'datetime',
            'trader_promoted_a' => 'boolean',
            'trader_promoted_a_at' => 'datetime',
            'premarket_price' => 'decimal:2',
            'premarket_scan_type' => PremarketScanResult::class,
            'premarket_reference_price' => 'decimal:2',
            'premarket_distance_pct' => 'decimal:4',
            'premarket_checked_at' => 'datetime',
            'closed_at' => 'datetime',
            'freeride_secured_at' => 'datetime',
            'scaled_out_price' => 'decimal:2',
            'scaled_out_quantity' => 'decimal:6',
            'scaled_out_at' => 'datetime',
            'realized_pnl' => 'decimal:2',
            'target_1_rr' => 'decimal:4',
            'first_tranche_fraction' => 'decimal:4',
            'target_1_limit_placed_at' => 'datetime',
            'initial_sl' => 'decimal:2',
            'initial_sl_placed_at' => 'datetime',
            'held_through_earnings_date' => 'date',
            'held_through_earnings_at' => 'datetime',
            'risk_reward_ratio' => 'decimal:4',
            'visibility' => PositionVisibility::class,
            'broker_order_status' => BrokerOrderStatus::class,
            'broker' => Broker::class,
            'market_open_reminder_on' => 'date',
            'execution_digest_status' => ExecutionDigestStatus::class,
            'execution_digest_price' => 'decimal:4',
            'execution_digest_at' => 'datetime',
            'buy_stop_review_required_on' => 'date',
            'buy_stop_review_setup_score' => 'integer',
            'is_legacy' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Position $position): void {
            if (
                $position->isDirty('status')
                && $position->status === 'closed'
                && $position->getOriginal('status') !== 'closed'
                && blank($position->exit_price)
            ) {
                $position->status = $position->getOriginal('status');
            }

            if ($position->isDirty('entry_price')) {
                $position->telegram_a_minus_alert_sent_at = null;
                $position->telegram_a_plus_alert_sent_at = null;
                $position->trader_promoted_a = false;
                $position->trader_promoted_a_at = null;
                $position->trader_promoted_a_plus = false;
                $position->trader_promoted_a_plus_at = null;
                $position->premarket_price = null;
                $position->premarket_scan_type = null;
                $position->premarket_reference_price = null;
                $position->premarket_distance_pct = null;
                $position->premarket_checked_at = null;
            }

            $position->deleteReplacedChartScreenshot('entry_chart_screenshot_path');
            $position->deleteReplacedChartScreenshot('exit_chart_screenshot_path');

            if ($position->getOriginal('status') !== 'closed') {
                return;
            }

            $frozenFields = [
                'latest_open_price',
                'latest_close_price',
                'latest_sma_20',
                'latest_sma_50',
                'sma_20_five_days_ago',
                'sma_20_ten_days_ago',
                'latest_atr_14',
                'scout_rsi',
                'bounce_volume_above_average',
                'bounce_day_volume',
                'avg_volume_30d',
                'relative_volume',
                'volume_sma_20',
                'sector_etf',
                'sector_close',
                'sector_sma_50',
                'sector_trend_positive',
                'pre_bounce_extension_atr',
                'current_sl',
                'entry_price',
                'quantity',
                'status',
                'exit_price',
                'closed_at',
                'scaled_out_price',
                'scaled_out_quantity',
                'scaled_out_at',
                'realized_pnl',
                'target_1_rr',
                'first_tranche_fraction',
                'target_1_limit_placed_at',
                'initial_sl_placed_at',
            ];

            foreach ($frozenFields as $field) {
                if ($position->isDirty($field)) {
                    $position->{$field} = $position->getOriginal($field);
                }
            }
        });

        static::deleting(function (Position $position): void {
            $position->deleteChartScreenshotFile($position->entry_chart_screenshot_path);
            $position->deleteChartScreenshotFile($position->exit_chart_screenshot_path);
        });

        static::saved(function (Position $position): void {
            if (blank($position->ticker)) {
                return;
            }

            if (! $position->wasRecentlyCreated && ! $position->wasChanged('ticker')) {
                return;
            }

            $asset = app(AssetSyncService::class)->ensureForTicker($position->ticker);

            if ($position->asset_id !== $asset->id) {
                $position->updateQuietly(['asset_id' => $asset->id]);
            }
        });
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function squad(): BelongsTo
    {
        return $this->belongsTo(Squad::class);
    }

    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'cloned_from_id');
    }

    public function strategyTag(): BelongsTo
    {
        return $this->belongsTo(StrategyTag::class);
    }

    public function clones(): HasMany
    {
        return $this->hasMany(Position::class, 'cloned_from_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOrderBySetupGrade(Builder $query, string $direction = 'asc'): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query
            ->orderByRaw(ScoutSetupScorecard::setupGradeSortRankSql().' '.$direction)
            ->orderBy('ticker');
    }

    public function scopePersonalScouts(Builder $query, int $userId): Builder
    {
        return $query->scout()->nonLegacy()->forUser($userId);
    }

    public function scopeSquadShared(Builder $query, int $squadId): Builder
    {
        return $query
            ->scout()
            ->where('squad_id', $squadId)
            ->where('visibility', PositionVisibility::Squad);
    }

    public function isOwnedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return (int) $this->user_id === (int) $user->id;
    }

    public function scoutPipelineStatus(): ScoutPipelineStatus
    {
        return ScoutPipelineStatus::fromPosition($this);
    }

    public function scheduleMarketOpenReminder(?Carbon $now = null): void
    {
        $now ??= Carbon::now('Europe/Amsterdam');

        $this->update([
            'market_open_reminder_on' => self::resolveMarketOpenReminderDate($now)->toDateString(),
        ]);
    }

    public static function resolveMarketOpenReminderDate(?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now('Europe/Amsterdam');
        $today = $now->copy()->startOfDay();

        $reminderTime = (string) config(
            'vestix.execution_digest.time',
            config('vestix.market_open_reminder.time', '15:31'),
        );
        [$hour, $minute] = array_pad(explode(':', $reminderTime), 2, '0');
        $reminderAt = $today->copy()->setTime((int) $hour, (int) $minute);

        if (
            UsMarketSession::isUsTradingDay($now->copy()->timezone('America/New_York'))
            && $now->lessThan($reminderAt)
        ) {
            return $today;
        }

        return UsMarketSession::nextTradingDay($today);
    }

    public function clearMarketOpenReminder(): void
    {
        $this->update(['market_open_reminder_on' => null]);
    }

    /**
     * Scouts in the user's Order Plan (execution cart).
     *
     * @return Collection<int, self>
     */
    public static function orderPlanForUser(int $userId): Collection
    {
        return static::query()
            ->forUser($userId)
            ->scout()
            ->nonLegacy()
            ->whereNotNull('market_open_reminder_on')
            ->whereNotNull('entry_price')
            ->with('asset')
            ->orderBy('market_open_reminder_on')
            ->orderBy('ticker')
            ->get();
    }

    public function scopeRequiringBuyStopReview(Builder $query): Builder
    {
        return $query->scout()->whereNotNull('buy_stop_review_required_on');
    }

    /**
     * @return Collection<int, self>
     */
    public static function requiringBuyStopReviewForUser(int $userId): Collection
    {
        return static::query()
            ->forUser($userId)
            ->nonLegacy()
            ->requiringBuyStopReview()
            ->with('asset')
            ->orderBy('buy_stop_review_required_on')
            ->orderBy('ticker')
            ->get();
    }

    public function clearBuyStopReview(): void
    {
        $this->update([
            'buy_stop_review_required_on' => null,
            'buy_stop_review_setup_score' => null,
            'buy_stop_review_setup_grade' => null,
        ]);
    }

    public function rolloverBuyStop(): void
    {
        $this->update([
            'broker_order_status' => BrokerOrderStatus::Pending,
            'market_open_reminder_on' => null,
            'buy_stop_review_required_on' => null,
            'buy_stop_review_setup_score' => null,
            'buy_stop_review_setup_grade' => null,
        ]);
    }

    public function cancelScoutSetup(): void
    {
        $this->delete();
    }

    public function buyStopReviewValidationHint(): ?string
    {
        $scorecard = $this->evaluateSetupScore();

        if ($scorecard['hardFailReasons'] !== []) {
            return 'Let op: setup is nu te zwak (NO TRADE).';
        }

        $snapshotGrade = $this->buy_stop_review_setup_grade;
        $currentGrade = $scorecard['grade'];

        if ($snapshotGrade !== null && $currentGrade !== $snapshotGrade) {
            $gradeOrder = ['A++' => 5, 'A' => 4, 'B' => 3, 'C' => 2, 'NO TRADE' => 0];
            $snapshotRank = $gradeOrder[$snapshotGrade] ?? 0;
            $currentRank = $gradeOrder[$currentGrade] ?? 0;

            if ($currentRank < $snapshotRank) {
                return sprintf(
                    'Let op: setup is verslechterd (%s → %s, %d/%d punten).',
                    $snapshotGrade,
                    $currentGrade,
                    $scorecard['totalPoints'],
                    ScoutSetupScorecard::maxPoints(),
                );
            }
        }

        if ($this->buy_stop_review_setup_score !== null) {
            $delta = $scorecard['totalPoints'] - $this->buy_stop_review_setup_score;

            if ($delta <= -2) {
                return sprintf(
                    'Let op: score gedaald met %d punten (%d → %d/%d).',
                    abs($delta),
                    $this->buy_stop_review_setup_score,
                    $scorecard['totalPoints'],
                    ScoutSetupScorecard::maxPoints(),
                );
            }
        }

        return null;
    }

    public function cloneForUser(User $user): self
    {
        $clone = $this->replicate([
            'quantity',
            'telegram_a_minus_alert_sent_at',
            'telegram_a_plus_alert_sent_at',
            'trader_promoted_a_plus',
            'trader_promoted_a_plus_at',
            'last_setup_score',
            'exit_price',
            'closed_at',
            'exit_chart_screenshot_path',
        ]);

        $clone->fill([
            'user_id' => $user->id,
            'visibility' => PositionVisibility::Private,
            'squad_id' => null,
            'cloned_from_id' => $this->id,
            'status' => 'scout',
        ]);

        $clone->save();

        return $clone;
    }

    public function archiveWithExitPrice(float $exitPrice, ?string $exitChartPath = null): void
    {
        $data = [
            'exit_price' => $exitPrice,
            'status' => 'closed',
            'closed_at' => now(),
            'risk_reward_ratio' => self::computeBlendedRiskRewardRatio($this, $exitPrice),
        ];

        if ($exitChartPath !== null) {
            $data['exit_chart_screenshot_path'] = $exitChartPath;
        }

        $this->update($data);
    }

    public function getEntryChartScreenshotUrlAttribute(): ?string
    {
        return $this->chartScreenshotUrl($this->entry_chart_screenshot_path);
    }

    public function getExitChartScreenshotUrlAttribute(): ?string
    {
        return $this->chartScreenshotUrl($this->exit_chart_screenshot_path);
    }

    private function deleteReplacedChartScreenshot(string $field): void
    {
        if (! $this->isDirty($field)) {
            return;
        }

        $original = $this->getOriginal($field);

        if ($original && $original !== $this->{$field}) {
            $this->deleteChartScreenshotFile($original);
        }
    }

    private function deleteChartScreenshotFile(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function chartScreenshotUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function activateAsPosition(
        float $entryPrice,
        float $quantity,
        ?float $target1Rr = null,
        ?float $firstTrancheFraction = null,
    ): void {
        $sl = self::computeNewSl($this->latest_sma_20, $this->latest_atr_14);

        if ($sl === null) {
            throw new InvalidArgumentException('Marktdata ontbreekt — kan geen stop-loss berekenen.');
        }

        $this->loadMissing('user');

        $this->update([
            'status' => 'open',
            'entry_price' => $entryPrice,
            'quantity' => $quantity,
            'current_sl' => $sl,
            'initial_sl' => $sl,
            'broker' => $this->user?->primary_broker?->value ?? $this->broker?->value ?? Broker::Revolut->value,
            'target_1_rr' => $target1Rr ?? self::defaultTarget1Rr(),
            'first_tranche_fraction' => $firstTrancheFraction ?? self::defaultFirstTrancheFraction(),
            'premarket_price' => null,
            'premarket_scan_type' => null,
            'premarket_reference_price' => null,
            'premarket_distance_pct' => null,
            'premarket_checked_at' => null,
        ]);
    }

    public function hasScaledOut(): bool
    {
        return $this->scaled_out_at !== null
            && $this->scaled_out_quantity !== null
            && (float) $this->scaled_out_quantity > 0;
    }

    public function isAutoRunnerBypass(): bool
    {
        return $this->status === 'open'
            && ! $this->hasScaledOut()
            && $this->entry_price !== null
            && $this->current_sl !== null
            && (float) $this->current_sl >= (float) $this->entry_price;
    }

    public function effectiveBroker(): Broker
    {
        // Scouts always follow the profile setting (temporary hybrid; no per-scout override).
        if ($this->status === 'scout') {
            $this->loadMissing('user');

            return $this->user?->primary_broker ?? Broker::Revolut;
        }

        if ($this->broker !== null) {
            return $this->broker;
        }

        $this->loadMissing('user');

        return $this->user?->primary_broker ?? Broker::Revolut;
    }

    public function usesRevolutWorkflow(): bool
    {
        return $this->effectiveBroker() === Broker::Revolut;
    }

    public function usesIbkrWorkflow(): bool
    {
        return $this->effectiveBroker() === Broker::Ibkr;
    }

    public function suppressesLimitSellTodo(): bool
    {
        return $this->usesIbkrWorkflow() || $this->isAutoRunnerBypass();
    }

    /** @deprecated Use usesRevolutWorkflow() — workflow is determined by position broker tag. */
    public function userUsesRevolutWorkflow(): bool
    {
        return $this->usesRevolutWorkflow();
    }

    public function isTarget1Hit(): bool
    {
        if ($this->status !== 'open' || $this->hasScaledOut()) {
            return false;
        }

        $target = $this->target_1_price;
        $close = $this->latest_close_price;

        if ($target === null || $close === null) {
            return false;
        }

        return (float) $close >= (float) $target;
    }

    public function hasTarget1LimitPlaced(): bool
    {
        return $this->target_1_limit_placed_at !== null;
    }

    public function markTarget1LimitPlaced(): void
    {
        $this->update(['target_1_limit_placed_at' => now()]);
    }

    public function hasInitialSlPlaced(): bool
    {
        return $this->initial_sl_placed_at !== null;
    }

    public function markInitialSlPlaced(): void
    {
        $this->update(['initial_sl_placed_at' => now()]);
    }

    public function scaleOut(float $fillPrice, float $quantityToSell): void
    {
        if ($this->status !== 'open') {
            throw new InvalidArgumentException('Alleen open posities kunnen gedeeltelijk worden verkocht.');
        }

        if ($this->hasScaledOut()) {
            throw new InvalidArgumentException('Deze positie is al gedeeltelijk verkocht.');
        }

        if ($this->entry_price === null || $this->quantity === null) {
            throw new InvalidArgumentException('Entry of quantity ontbreekt.');
        }

        $maxQty = (float) $this->quantity;

        if ($quantityToSell <= 0 || $quantityToSell > $maxQty) {
            throw new InvalidArgumentException('Ongeldige verkoophoeveelheid.');
        }

        $realizedPnl = round($quantityToSell * ($fillPrice - (float) $this->entry_price), 2);

        $data = [
            'scaled_out_price' => $fillPrice,
            'scaled_out_quantity' => $quantityToSell,
            'scaled_out_at' => now(),
            'realized_pnl' => $realizedPnl,
        ];

        if (config('vestix.scale_out.move_stop_to_breakeven', true)) {
            $data['current_sl'] = max((float) ($this->current_sl ?? 0), (float) $this->entry_price);
            $data['freeride_secured_at'] = now();
        }

        $this->update($data);
    }

    public static function defaultTarget1Rr(): float
    {
        return (float) config('vestix.scale_out.target_1_rr', 2.0);
    }

    public static function defaultFirstTrancheFraction(): float
    {
        return (float) config('vestix.scale_out.first_tranche_fraction', 0.5);
    }

    public function getEffectiveTarget1RrAttribute(): float
    {
        return $this->target_1_rr !== null
            ? (float) $this->target_1_rr
            : self::defaultTarget1Rr();
    }

    public function getEffectiveFirstTrancheFractionAttribute(): float
    {
        return $this->first_tranche_fraction !== null
            ? (float) $this->first_tranche_fraction
            : self::defaultFirstTrancheFraction();
    }

    public function getTarget1PriceAttribute(): ?float
    {
        if ($this->entry_price === null) {
            return null;
        }

        $initialSl = $this->initial_sl ?? $this->current_sl;

        if ($initialSl === null) {
            return null;
        }

        $riskPerShare = (float) $this->entry_price - (float) $initialSl;

        if ($riskPerShare <= 0) {
            return null;
        }

        return round((float) $this->entry_price + ($this->effective_target_1_rr * $riskPerShare), 2);
    }

    /**
     * Target 1 for scout bracket orders — uses computed new_sl when initial_sl is not set yet.
     */
    public function plannedBracketTarget1Price(): ?float
    {
        if ($this->target_1_price !== null) {
            return $this->target_1_price;
        }

        if ($this->entry_price === null || $this->new_sl === null) {
            return null;
        }

        $riskPerShare = (float) $this->entry_price - (float) $this->new_sl;

        if ($riskPerShare <= 0) {
            return null;
        }

        return round((float) $this->entry_price + ($this->effective_target_1_rr * $riskPerShare), 2);
    }

    public function hasCompleteBracketPlan(): bool
    {
        return $this->entry_price !== null
            && $this->quantity !== null
            && (float) $this->quantity > 0
            && $this->new_sl !== null
            && $this->plannedBracketTarget1Price() !== null;
    }

    public function getTarget1QuantityAttribute(): ?float
    {
        if ($this->quantity === null) {
            return null;
        }

        $fraction = $this->effective_first_tranche_fraction;

        return round((float) $this->quantity * $fraction, 6);
    }

    public function getTarget1ProfitDollarsAttribute(): ?float
    {
        $target = $this->target_1_price;
        $qty = $this->target_1_quantity;

        if ($target === null || $qty === null || $this->entry_price === null) {
            return null;
        }

        return round($qty * ($target - (float) $this->entry_price), 2);
    }

    public function getRemainingQuantityAttribute(): ?float
    {
        if ($this->quantity === null) {
            return null;
        }

        if (! $this->hasScaledOut()) {
            return (float) $this->quantity;
        }

        return max(0, (float) $this->quantity - (float) $this->scaled_out_quantity);
    }

    public function getStoredRealizedPnlAttribute(): float
    {
        if ($this->realized_pnl !== null) {
            return (float) $this->realized_pnl;
        }

        return 0.0;
    }

    public function isFreerideSecured(): bool
    {
        if ($this->freeride_secured_at !== null) {
            return true;
        }

        if ($this->status !== 'open') {
            return false;
        }

        if ($this->entry_price === null || $this->current_sl === null) {
            return false;
        }

        return (float) $this->current_sl > (float) $this->entry_price;
    }

    public function holdingDays(): int
    {
        $start = $this->created_at;

        if ($start === null) {
            return 0;
        }

        $end = $this->status === 'closed' && $this->closed_at !== null
            ? $this->closed_at
            : now();

        return max(0, (int) $start->diffInDays($end));
    }

    public function rMultiple(): ?float
    {
        if ($this->status !== 'closed') {
            return null;
        }

        if ($this->risk_reward_ratio !== null) {
            return (float) $this->risk_reward_ratio;
        }

        return self::computeBlendedRiskRewardRatio($this, $this->exit_price);
    }

    public static function computeBlendedRiskRewardRatio(Position $position, mixed $exitPrice): ?float
    {
        if ($exitPrice === null || $position->entry_price === null) {
            return null;
        }

        $initialSl = $position->initial_sl ?? $position->current_sl;

        if ($initialSl === null || $position->quantity === null) {
            return null;
        }

        $riskPerShare = abs((float) $position->entry_price - (float) $initialSl);

        if ($riskPerShare <= 0) {
            return null;
        }

        $totalRisk = $riskPerShare * (float) $position->quantity;
        $remainingQty = $position->remaining_quantity ?? (float) $position->quantity;
        $realized = $position->stored_realized_pnl;
        $runnerPnl = $remainingQty * ((float) $exitPrice - (float) $position->entry_price);
        $totalPnl = $realized + $runnerPnl;

        return round($totalPnl / $totalRisk, 4);
    }

    public static function computeRiskRewardRatio(mixed $exit, mixed $entry, mixed $initialSl): ?float
    {
        if ($exit === null || $entry === null || $initialSl === null) {
            return null;
        }

        $exit = (float) $exit;
        $entry = (float) $entry;
        $initialSl = (float) $initialSl;

        $risk = abs($entry - $initialSl);

        if ($risk <= 0) {
            return null;
        }

        return round(abs($exit - $entry) / $risk, 4);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeScout(Builder $query): Builder
    {
        return $query->where('status', 'scout');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    public function scopeLegacy(Builder $query): Builder
    {
        return $query->where('is_legacy', true);
    }

    public function scopeNonLegacy(Builder $query): Builder
    {
        return $query->where('is_legacy', false);
    }

    public function scopeTracked(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'scout']);
    }

    public function wasPremarketCheckedToday(?Carbon $now = null): bool
    {
        if ($this->premarket_checked_at === null) {
            return false;
        }

        $now ??= Carbon::now('America/New_York');

        return $this->premarket_checked_at
            ->timezone('America/New_York')
            ->toDateString() === $now->toDateString();
    }

    public function hasPremarketGapUpRisk(): bool
    {
        return $this->premarket_scan_type === PremarketScanResult::GapRisk
            && $this->wasPremarketCheckedToday();
    }

    public function hasPremarketReclamation(): bool
    {
        return $this->premarket_scan_type === PremarketScanResult::Reclamation
            && $this->wasPremarketCheckedToday();
    }

    public function hasPremarketLanding(): bool
    {
        return $this->premarket_scan_type === PremarketScanResult::Landing
            && $this->wasPremarketCheckedToday();
    }

    public function wasExecutionDigestCheckedToday(?Carbon $now = null): bool
    {
        if ($this->execution_digest_at === null) {
            return false;
        }

        $now ??= Carbon::now('Europe/Amsterdam');

        return $this->execution_digest_at
            ->timezone('Europe/Amsterdam')
            ->toDateString() === $now->toDateString();
    }

    public function hasExecutionDigestCancellation(): bool
    {
        return $this->wasExecutionDigestCheckedToday()
            && $this->execution_digest_status instanceof ExecutionDigestStatus
            && $this->execution_digest_status->isCancelled();
    }

    public function scopeStoppedOut(Builder $query): Builder
    {
        return $query
            ->where('status', 'open')
            ->whereNotNull('latest_close_price')
            ->whereNotNull('current_sl')
            ->whereColumn('latest_close_price', '<=', 'current_sl');
    }

    public function scopeRequiresSlUpdate(Builder $query): Builder
    {
        // SQL uses standard formula only; pre-earnings aggressive mode is resolved via StopLossProtocol in PHP.
        return $query
            ->where('status', 'open')
            ->whereNotNull('latest_close_price')
            ->whereNotNull('latest_sma_20')
            ->whereNotNull('latest_atr_14')
            ->whereNotNull('current_sl')
            ->whereColumn('latest_close_price', '>=', 'current_sl')
            ->whereRaw('ROUND(latest_sma_20 - (latest_atr_14 / 2), 2) > current_sl');
    }

    public const PRIMARY_ACTION_TARGET_1 = 'TARGET_1';

    public const PRIMARY_ACTION_LIQUIDATION = 'LIQUIDATION';

    public const PRIMARY_ACTION_EARNINGS = 'EARNINGS';

    public const PRIMARY_ACTION_UPDATE_SL = 'UPDATE_SL';

    public const PRIMARY_ACTION_PLACE_INITIAL_SL = 'PLACE_INITIAL_SL';

    /**
     * Resolve the single most important action for a position.
     *
     * Hierarchy (only one action is ever surfaced per position):
     * 1. Target 1 hit  -> sell tranche (this also moves the SL to breakeven, so SL updates are irrelevant)
     * 2. Stopped out   -> liquidate (mutually exclusive with Target 1)
     * 3. Earnings      -> exit before earnings
     * 4. Initial SL    -> place stop-loss at broker after activation
     * 5. SL can raise  -> update stop-loss
     */
    public function primaryActionType(?Carbon $today = null): ?string
    {
        if ($this->status !== 'open') {
            return null;
        }

        if ($this->isTarget1Hit()
            && ! $this->hasTarget1LimitPlaced()
            && ! $this->suppressesLimitSellTodo()) {
            return self::PRIMARY_ACTION_TARGET_1;
        }

        if ($this->action_command === 'STOPPED OUT') {
            return self::PRIMARY_ACTION_LIQUIDATION;
        }

        if ($this->requiresEarningsExit($today)) {
            return self::PRIMARY_ACTION_EARNINGS;
        }

        if (! $this->hasInitialSlPlaced()) {
            return self::PRIMARY_ACTION_PLACE_INITIAL_SL;
        }

        if ($this->action_command === 'UPDATE') {
            return self::PRIMARY_ACTION_UPDATE_SL;
        }

        return null;
    }

    /**
     * @return Collection<int, self>
     */
    public static function requiringActionForUser(int $userId): Collection
    {
        return static::query()
            ->forUser($userId)
            ->open()
            ->with('asset')
            ->get()
            ->filter(fn (self $position): bool => $position->primaryActionType() !== null)
            ->values();
    }

    public function effectiveEarningsDate(): ?Carbon
    {
        return $this->asset?->effectiveEarningsDate();
    }

    public function daysUntilEarnings(?Carbon $today = null): ?int
    {
        $earningsDate = $this->effectiveEarningsDate();

        if ($earningsDate === null) {
            return null;
        }

        return EarningsExitSchedule::daysUntilEarnings($earningsDate, $today);
    }

    public function heldThroughEarningsForCurrentCycle(): bool
    {
        $earningsDate = $this->effectiveEarningsDate();

        if ($earningsDate === null || $this->held_through_earnings_date === null) {
            return false;
        }

        return $this->held_through_earnings_date->equalTo($earningsDate->copy()->startOfDay());
    }

    public function acknowledgeHeldThroughEarnings(): void
    {
        if ($this->status !== 'open') {
            throw new InvalidArgumentException('Alleen open posities kunnen earnings afwachten.');
        }

        $earningsDate = $this->effectiveEarningsDate();

        if ($earningsDate === null) {
            throw new InvalidArgumentException('Geen earnings-datum beschikbaar.');
        }

        if ($this->earningsExitUrgency() === null) {
            throw new InvalidArgumentException('Geen actieve earnings-exit voor deze positie.');
        }

        $this->update([
            'held_through_earnings_date' => $earningsDate->toDateString(),
            'held_through_earnings_at' => now(),
        ]);
    }

    public function requiresEarningsExit(?Carbon $today = null): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        return $this->earningsExitUrgency($today) !== null;
    }

    public function earningsExitUrgency(?Carbon $today = null): ?EarningsExitUrgency
    {
        $earningsDate = $this->effectiveEarningsDate();

        if ($earningsDate === null || $this->status !== 'open') {
            return null;
        }

        if ($this->heldThroughEarningsForCurrentCycle()) {
            return null;
        }

        return EarningsExitSchedule::urgency(
            $earningsDate,
            $today,
            $this->asset?->effectiveEarningsHour(),
        );
    }

    public function scopeRequiresAction(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where(fn (Builder $query): Builder => $query->stoppedOut())
                ->orWhere(fn (Builder $query): Builder => $query->requiresSlUpdate());
        });
    }

    public static function computeNewSl(mixed $sma, mixed $atr): ?float
    {
        return StopLossProtocol::computeStandard($sma, $atr);
    }

    public static function computeBuyStop(mixed $high, mixed $atr): ?float
    {
        if ($high === null || $atr === null || $high === '' || $atr === '') {
            return null;
        }

        $high = (float) $high;
        $atr = (float) $atr;

        if ($high <= 0 || $atr <= 0) {
            return null;
        }

        return round($high + (0.10 * $atr), 2);
    }

    public static function resolveActionCommand(
        mixed $close,
        mixed $currentSl,
        mixed $sma,
        mixed $atr,
        ?Position $position = null,
    ): string {
        if ($close === null || $close === '') {
            return 'AWAITING DATA';
        }

        if ($currentSl === null || $currentSl === '') {
            return 'AWAITING DATA';
        }

        if ((float) $close <= (float) $currentSl) {
            return 'STOPPED OUT';
        }

        $newSl = $position !== null
            ? StopLossProtocol::resolve($position)
            : StopLossProtocol::resolveForIndicators($sma, $atr);

        if ($newSl && $newSl > (float) $currentSl) {
            return 'UPDATE';
        }

        return 'HOLD';
    }

    public function getNewSlAttribute(): ?float
    {
        return StopLossProtocol::resolve($this);
    }

    public function getTrailingStopModeAttribute(): TrailingStopMode
    {
        return StopLossProtocol::activeMode($this);
    }

    public function getActionCommandAttribute(): string
    {
        if ($this->status === 'scout') {
            return 'SCOUT';
        }

        if ($this->status === 'closed') {
            return 'CLOSED';
        }

        return self::resolveActionCommand(
            $this->latest_close_price,
            $this->current_sl,
            $this->latest_sma_20,
            $this->latest_atr_14,
            $this,
        );
    }

    public function getPlannedRiskPerShareAttribute(): ?float
    {
        if ($this->status !== 'scout' || $this->entry_price === null || $this->new_sl === null) {
            return null;
        }

        return (float) $this->entry_price - $this->new_sl;
    }

    public function getPlannedRiskPercentageAttribute(): ?float
    {
        $perShare = $this->planned_risk_per_share;

        if ($perShare === null || (float) $this->entry_price == 0) {
            return null;
        }

        return ($perShare / (float) $this->entry_price) * 100;
    }

    public function getPlannedRiskDollarsAttribute(): ?float
    {
        $perShare = $this->planned_risk_per_share;

        if ($perShare === null || $this->quantity === null) {
            return null;
        }

        return $perShare * (float) $this->quantity;
    }

    public function getValuationPriceAttribute(): ?float
    {
        if ($this->status === 'closed') {
            return $this->exit_price !== null ? (float) $this->exit_price : null;
        }

        if ($this->status === 'scout') {
            return null;
        }

        return $this->latest_close_price !== null ? (float) $this->latest_close_price : null;
    }

    public function getInvestmentAttribute(): float
    {
        if ($this->entry_price === null || $this->quantity === null) {
            return 0;
        }

        return (float) $this->entry_price * (float) $this->quantity;
    }

    public function getCapitalRiskDollarsAttribute(): float
    {
        if ($this->status !== 'open') {
            return 0;
        }

        if ($this->entry_price === null || $this->current_sl === null || $this->remaining_quantity === null) {
            return 0;
        }

        if ((float) $this->current_sl > (float) $this->entry_price) {
            return 0;
        }

        return ((float) $this->entry_price - (float) $this->current_sl) * (float) $this->remaining_quantity;
    }

    public function getLockedInProfitDollarsAttribute(): float
    {
        if ($this->status !== 'open') {
            return 0;
        }

        $lockedFromStop = 0.0;

        if ($this->entry_price !== null && $this->current_sl !== null && $this->remaining_quantity !== null) {
            if ((float) $this->current_sl > (float) $this->entry_price) {
                $lockedFromStop = ((float) $this->current_sl - (float) $this->entry_price) * (float) $this->remaining_quantity;
            }
        }

        return $lockedFromStop + $this->stored_realized_pnl;
    }

    public function getRiskDollarsAttribute(): float
    {
        return $this->capital_risk_dollars;
    }

    public function isInDangerZone(float $threshold = 2.0): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        $close = $this->latest_close_price;
        $stopLoss = $this->current_sl;

        if ($close === null || $stopLoss === null || (float) $close <= 0) {
            return false;
        }

        $bufferPercentage = (((float) $close - (float) $stopLoss) / (float) $close) * 100;

        return $bufferPercentage >= 0 && $bufferPercentage < $threshold;
    }

    /**
     * @param  Builder<Position>  $query
     * @return Builder<Position>
     */
    public function scopeInDangerZone(Builder $query, float $threshold = 2.0): Builder
    {
        return $query
            ->where('status', 'open')
            ->whereNotNull('latest_close_price')
            ->whereNotNull('current_sl')
            ->where('latest_close_price', '>', 0)
            ->whereRaw('(CAST(latest_close_price AS REAL) - CAST(current_sl AS REAL)) / CAST(latest_close_price AS REAL) * 100 >= 0')
            ->whereRaw('(CAST(latest_close_price AS REAL) - CAST(current_sl AS REAL)) / CAST(latest_close_price AS REAL) * 100 < ?', [$threshold]);
    }

    public function getCurrentValueAttribute(): float
    {
        $price = $this->valuation_price;
        $qty = $this->remaining_quantity;

        if ($price === null || $qty === null) {
            return 0;
        }

        return $price * $qty;
    }

    public function getUnrealizedPnlAttribute(): float
    {
        if ($this->status !== 'open' && $this->status !== 'closed') {
            return 0;
        }

        if ($this->valuation_price === null || $this->entry_price === null || $this->remaining_quantity === null) {
            return 0;
        }

        return $this->current_value
            - ((float) $this->remaining_quantity * (float) $this->entry_price)
            + $this->stored_realized_pnl;
    }

    public function getUnrealizedPnlPercentageAttribute(): float
    {
        if ($this->valuation_price === null || $this->entry_price === null || $this->investment <= 0) {
            return 0;
        }

        return ($this->unrealized_pnl / $this->investment) * 100;
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     * @return array{
     *     totalPoints: int,
     *     maxPoints: int,
     *     grade: string,
     *     gradeLabel: string,
     *     hardFailReasons: array<int, string>,
     *     criteria: array<int, array<string, mixed>>,
     * }
     */
    public function promoteToA(): void
    {
        $this->update([
            'trader_promoted_a' => true,
            'trader_promoted_a_at' => now(),
        ]);
    }

    public function clearAPromotion(): void
    {
        if (! $this->trader_promoted_a) {
            return;
        }

        $this->update([
            'trader_promoted_a' => false,
            'trader_promoted_a_at' => null,
        ]);
    }

    public function promoteToAPlus(): void
    {
        $this->update([
            'trader_promoted_a' => true,
            'trader_promoted_a_at' => $this->trader_promoted_a_at ?? now(),
            'trader_promoted_a_plus' => true,
            'trader_promoted_a_plus_at' => now(),
        ]);
    }

    public function clearAPlusPromotion(): void
    {
        if (! $this->trader_promoted_a_plus) {
            return;
        }

        $this->update([
            'trader_promoted_a_plus' => false,
            'trader_promoted_a_plus_at' => null,
            'telegram_a_plus_alert_sent_at' => null,
        ]);
    }

    public function evaluateSetupScore(?array $overrides = null): array
    {
        $inputs = [
            'signal_low' => $overrides['signal_low'] ?? $this->signal_low,
            'latest_open_price' => $overrides['latest_open_price'] ?? $this->latest_open_price,
            'latest_close_price' => $overrides['latest_close_price'] ?? $this->latest_close_price,
            'latest_sma_20' => $overrides['latest_sma_20'] ?? $this->latest_sma_20,
            'sma_20_five_days_ago' => $overrides['sma_20_five_days_ago'] ?? $this->sma_20_five_days_ago,
            'sma_20_ten_days_ago' => $overrides['sma_20_ten_days_ago'] ?? $this->sma_20_ten_days_ago,
            'latest_sma_50' => $overrides['latest_sma_50'] ?? $this->latest_sma_50,
            'scout_rsi' => $overrides['scout_rsi'] ?? $this->scout_rsi,
            'bounce_volume_above_average' => $overrides['bounce_volume_above_average'] ?? $this->bounce_volume_above_average,
            'relative_volume' => $overrides['relative_volume'] ?? $this->relative_volume,
            'bounce_day_volume' => $overrides['bounce_day_volume'] ?? $this->bounce_day_volume,
            'volume_sma_20' => $overrides['volume_sma_20'] ?? $this->volume_sma_20,
            'sector_etf' => $overrides['sector_etf'] ?? $this->sector_etf,
            'sector_trend_positive' => $overrides['sector_trend_positive'] ?? $this->sector_trend_positive,
            'pre_bounce_extension_atr' => $overrides['pre_bounce_extension_atr'] ?? $this->pre_bounce_extension_atr,
            'days_until_earnings' => $overrides['days_until_earnings'] ?? $this->daysUntilEarnings(),
        ];

        $result = ScoutSetupScorecard::evaluate($inputs);

        if (
            ($overrides['trader_promoted_a'] ?? $this->trader_promoted_a)
            && $result['hardFailReasons'] === []
            && $result['totalPoints'] >= 8
        ) {
            $result['grade'] = 'A';
            $result['gradeLabel'] = 'A SETUP';
        }

        if (
            ($overrides['trader_promoted_a_plus'] ?? $this->trader_promoted_a_plus)
            && $result['hardFailReasons'] === []
            && $result['totalPoints'] === ScoutSetupScorecard::maxPoints()
        ) {
            $result['grade'] = 'A++';
            $result['gradeLabel'] = 'A++ SETUP';
        }

        return $result;
    }
}
