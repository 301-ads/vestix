<?php

namespace App\Models;

use App\Enums\BrokerOrderStatus;
use App\Enums\EarningsExitUrgency;
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
            'latest_atr_14' => 'decimal:2',
            'prior_day_low' => 'decimal:2',
            'signal_high' => 'decimal:2',
            'signal_low' => 'decimal:2',
            'scout_rsi' => 'decimal:2',
            'bounce_volume_above_average' => 'boolean',
            'last_setup_score' => 'integer',
            'telegram_a_minus_alert_sent_at' => 'datetime',
            'telegram_a_plus_alert_sent_at' => 'datetime',
            'premarket_price' => 'decimal:2',
            'premarket_scan_type' => PremarketScanResult::class,
            'premarket_reference_price' => 'decimal:2',
            'premarket_distance_pct' => 'decimal:4',
            'premarket_checked_at' => 'datetime',
            'closed_at' => 'datetime',
            'freeride_secured_at' => 'datetime',
            'initial_sl' => 'decimal:2',
            'risk_reward_ratio' => 'decimal:4',
            'visibility' => PositionVisibility::class,
            'broker_order_status' => BrokerOrderStatus::class,
            'market_open_reminder_on' => 'date',
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
                'latest_atr_14',
                'scout_rsi',
                'bounce_volume_above_average',
                'bounce_day_volume',
                'avg_volume_30d',
                'current_sl',
                'entry_price',
                'quantity',
                'status',
                'exit_price',
                'closed_at',
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
        return $query->scout()->forUser($userId);
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

    public function scheduleMarketOpenReminder(?Carbon $from = null): void
    {
        $from ??= Carbon::today('Europe/Amsterdam');

        $this->update([
            'market_open_reminder_on' => UsMarketSession::nextTradingDay($from->copy()->startOfDay())->toDateString(),
        ]);
    }

    public function clearMarketOpenReminder(): void
    {
        $this->update(['market_open_reminder_on' => null]);
    }

    public function cloneForUser(User $user): self
    {
        $clone = $this->replicate([
            'quantity',
            'telegram_a_minus_alert_sent_at',
            'telegram_a_plus_alert_sent_at',
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
            'risk_reward_ratio' => self::computeRiskRewardRatio(
                $exitPrice,
                $this->entry_price,
                $this->initial_sl ?? $this->current_sl,
            ),
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

    public function activateAsPosition(float $entryPrice, float $quantity): void
    {
        $sl = self::computeNewSl($this->latest_sma_20, $this->latest_atr_14);

        if ($sl === null) {
            throw new InvalidArgumentException('Marktdata ontbreekt — kan geen stop-loss berekenen.');
        }

        $this->update([
            'status' => 'open',
            'entry_price' => $entryPrice,
            'quantity' => $quantity,
            'current_sl' => $sl,
            'initial_sl' => $sl,
            'premarket_price' => null,
            'premarket_scan_type' => null,
            'premarket_reference_price' => null,
            'premarket_distance_pct' => null,
            'premarket_checked_at' => null,
        ]);
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

        return self::computeRiskRewardRatio(
            $this->exit_price,
            $this->entry_price,
            $this->initial_sl ?? $this->current_sl,
        );
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
            ->filter(fn (self $position): bool => in_array($position->action_command, ['UPDATE', 'STOPPED OUT'], true)
                || $position->requiresEarningsExit())
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

        return EarningsExitSchedule::urgency($earningsDate, $today);
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

        if ($this->entry_price === null || $this->current_sl === null || $this->quantity === null) {
            return 0;
        }

        if ((float) $this->current_sl > (float) $this->entry_price) {
            return 0;
        }

        return ((float) $this->entry_price - (float) $this->current_sl) * (float) $this->quantity;
    }

    public function getLockedInProfitDollarsAttribute(): float
    {
        if ($this->status !== 'open') {
            return 0;
        }

        if ($this->entry_price === null || $this->current_sl === null || $this->quantity === null) {
            return 0;
        }

        if ((float) $this->current_sl <= (float) $this->entry_price) {
            return 0;
        }

        return ((float) $this->current_sl - (float) $this->entry_price) * (float) $this->quantity;
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
            ->whereRaw('((latest_close_price - current_sl) / latest_close_price) * 100 >= 0')
            ->whereRaw('((latest_close_price - current_sl) / latest_close_price) * 100 < ?', [$threshold]);
    }

    public function getCurrentValueAttribute(): float
    {
        $price = $this->valuation_price;

        if ($price === null || $this->quantity === null) {
            return 0;
        }

        return $price * (float) $this->quantity;
    }

    public function getUnrealizedPnlAttribute(): float
    {
        if ($this->status !== 'open' && $this->status !== 'closed') {
            return 0;
        }

        return $this->current_value - $this->investment;
    }

    public function getUnrealizedPnlPercentageAttribute(): float
    {
        $price = $this->valuation_price;

        if ($price === null || $this->entry_price === null || (float) $this->entry_price == 0) {
            return 0;
        }

        return (($price - (float) $this->entry_price) / (float) $this->entry_price) * 100;
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
    public function evaluateSetupScore(?array $overrides = null): array
    {
        $inputs = [
            'signal_low' => $overrides['signal_low'] ?? $this->signal_low,
            'latest_open_price' => $overrides['latest_open_price'] ?? $this->latest_open_price,
            'latest_close_price' => $overrides['latest_close_price'] ?? $this->latest_close_price,
            'latest_sma_20' => $overrides['latest_sma_20'] ?? $this->latest_sma_20,
            'sma_20_five_days_ago' => $overrides['sma_20_five_days_ago'] ?? $this->sma_20_five_days_ago,
            'latest_sma_50' => $overrides['latest_sma_50'] ?? $this->latest_sma_50,
            'scout_rsi' => $overrides['scout_rsi'] ?? $this->scout_rsi,
            'bounce_volume_above_average' => $overrides['bounce_volume_above_average'] ?? $this->bounce_volume_above_average,
        ];

        return ScoutSetupScorecard::evaluate($inputs);
    }
}
