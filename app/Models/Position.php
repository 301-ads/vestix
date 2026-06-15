<?php

namespace App\Models;

use App\Enums\PositionVisibility;
use App\Services\AssetSyncService;
use App\Support\ScoutSetupScorecard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            'current_sl' => 'decimal:2',
            'latest_close_price' => 'decimal:2',
            'exit_price' => 'decimal:2',
            'latest_sma_20' => 'decimal:2',
            'latest_sma_50' => 'decimal:2',
            'sma_20_five_days_ago' => 'decimal:2',
            'latest_atr_14' => 'decimal:2',
            'signal_high' => 'decimal:2',
            'signal_low' => 'decimal:2',
            'scout_rsi' => 'decimal:2',
            'bounce_volume_above_average' => 'boolean',
            'last_setup_score' => 'integer',
            'telegram_a_minus_alert_sent_at' => 'datetime',
            'telegram_a_plus_alert_sent_at' => 'datetime',
            'closed_at' => 'datetime',
            'visibility' => PositionVisibility::class,
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
            }

            $position->deleteReplacedChartScreenshot('entry_chart_screenshot_path');
            $position->deleteReplacedChartScreenshot('exit_chart_screenshot_path');

            if ($position->getOriginal('status') !== 'closed') {
                return;
            }

            $frozenFields = [
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

    public function clones(): HasMany
    {
        return $this->hasMany(Position::class, 'cloned_from_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
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
        ]);
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
        return $query
            ->where('status', 'open')
            ->whereNotNull('latest_close_price')
            ->whereNotNull('latest_sma_20')
            ->whereNotNull('latest_atr_14')
            ->whereNotNull('current_sl')
            ->whereColumn('latest_close_price', '>=', 'current_sl')
            ->whereRaw('ROUND(latest_sma_20 - (0.5 * latest_atr_14), 2) > current_sl');
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
        if ($sma === null || $atr === null || $sma === '' || $atr === '') {
            return null;
        }

        return round((float) $sma - (0.5 * (float) $atr), 2);
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

    public static function resolveActionCommand(mixed $close, mixed $currentSl, mixed $sma, mixed $atr): string
    {
        if ($close === null || $close === '') {
            return 'AWAITING DATA';
        }

        if ($currentSl === null || $currentSl === '') {
            return 'AWAITING DATA';
        }

        if ((float) $close <= (float) $currentSl) {
            return 'STOPPED OUT';
        }

        $newSl = self::computeNewSl($sma, $atr);

        if ($newSl && $newSl > (float) $currentSl) {
            return 'UPDATE';
        }

        return 'HOLD';
    }

    public function getNewSlAttribute(): ?float
    {
        return self::computeNewSl($this->latest_sma_20, $this->latest_atr_14);
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
