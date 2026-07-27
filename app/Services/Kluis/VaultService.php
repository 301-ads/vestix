<?php

namespace App\Services\Kluis;

use App\Enums\VaultTransactionSource;
use App\Models\User;
use App\Models\VaultDeposit;
use App\Models\VaultSetting;
use App\Models\VaultTransaction;
use App\Support\Kluis\KluisHoldingsSummary;
use App\Support\Kluis\KluisOrderPlan;
use App\Support\Kluis\KluisThermometerReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class VaultService
{
    public function __construct(
        private KluisMarketDataService $marketData,
        private KluisOrderPlanCalculator $orderPlans,
    ) {}

    public function settingsFor(User $user): VaultSetting
    {
        $defaults = VaultSetting::defaultsFor($user)->getAttributes();
        unset($defaults['user_id'], $defaults['id']);

        return VaultSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            $defaults,
        );
    }

    public function updateSettings(VaultSetting $settings, array $data): VaultSetting
    {
        $settings->fill([
            'etf_ticker' => strtoupper(trim((string) ($data['etf_ticker'] ?? $settings->etf_ticker))),
            'default_monthly_budget' => (float) ($data['default_monthly_budget'] ?? $settings->default_monthly_budget),
            'overheat_threshold_pct' => (float) ($data['overheat_threshold_pct'] ?? $settings->overheat_threshold_pct),
            'crash_threshold_pct' => (float) ($data['crash_threshold_pct'] ?? $settings->crash_threshold_pct),
            'overheat_invest_fraction' => (float) ($data['overheat_invest_fraction'] ?? $settings->overheat_invest_fraction),
            'dip_dry_powder_fraction' => (float) ($data['dip_dry_powder_fraction'] ?? $settings->dip_dry_powder_fraction),
            'crash_dry_powder_fraction' => (float) ($data['crash_dry_powder_fraction'] ?? $settings->crash_dry_powder_fraction),
        ]);
        $settings->save();

        return $settings->fresh();
    }

    public function reading(VaultSetting $settings, bool $force = false): ?KluisThermometerReading
    {
        return $this->marketData->fetchReading($settings, $force);
    }

    public function holdingsPrice(VaultSetting $settings, bool $force = false): ?array
    {
        return $this->marketData->fetchHoldingsPrice((string) $settings->etf_ticker, $force);
    }

    public function orderPlan(
        VaultSetting $settings,
        float $budget,
        KluisThermometerReading $reading,
    ): KluisOrderPlan {
        $valuation = $this->holdingsPrice($settings);
        $valuationPrice = $valuation['price'] ?? null;

        return $this->orderPlans->calculate($settings, $budget, $reading, $valuationPrice);
    }

    /**
     * @param  array{shares?: float|null, fill_price?: float|null, etf_amount?: float|null, fee?: float|null}|null  $fill
     */
    public function confirmMonth(
        User $user,
        float $budget,
        ?KluisThermometerReading $reading = null,
        ?Carbon $periodMonth = null,
        ?array $fill = null,
    ): VaultDeposit {
        $settings = $this->settingsFor($user);
        $periodMonth = ($periodMonth ?? now())->copy()->startOfMonth();

        if (VaultDeposit::query()
            ->where('user_id', $user->id)
            ->whereDate('period_month', $periodMonth->toDateString())
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'budget' => 'Deze maand is al bevestigd in de Kluis.',
            ]);
        }

        $freshReading = $this->reading($settings, force: true);
        $reading = $freshReading ?? $reading;

        if ($reading === null) {
            throw ValidationException::withMessages([
                'budget' => 'Geen thermometerdata beschikbaar. Ververs eerst de thermometer.',
            ]);
        }

        $plan = $this->orderPlan($settings, $budget, $reading);

        $settings->dry_powder_balance = $plan->dryPowderAfter;
        $settings->save();

        $deposit = VaultDeposit::query()->create([
            'user_id' => $user->id,
            'period_month' => $periodMonth->toDateString(),
            'climate' => $plan->climate->value,
            'deviation_pct' => $reading->deviationPct,
            'budget_input' => $plan->budgetInput,
            'etf_amount' => $plan->etfAmount,
            'dry_powder_delta' => $plan->dryPowderDelta,
            'dry_powder_after' => $plan->dryPowderAfter,
            'etf_price' => $reading->close,
            'sma_200' => $reading->sma200,
            'confirmed_at' => now(),
        ]);

        if ($this->fillIsComplete($fill) || $plan->etfAmount > 0) {
            $this->recordFillFromConfirm($user, $deposit, $reading, $plan, $fill);
        }

        return $deposit;
    }

    /**
     * @param  array{traded_at: mixed, shares: float, fill_price: float, etf_amount: float, fee?: float, ticker?: string, notes?: string|null}  $data
     */
    public function addHistoricalPurchase(User $user, array $data): VaultTransaction
    {
        $settings = $this->settingsFor($user);

        return VaultTransaction::query()->create([
            'user_id' => $user->id,
            'vault_deposit_id' => null,
            'traded_at' => Carbon::parse($data['traded_at']),
            'shares' => round((float) $data['shares'], 6),
            'fill_price' => round((float) $data['fill_price'], 4),
            'etf_amount' => round((float) $data['etf_amount'], 2),
            'fee' => round((float) ($data['fee'] ?? 0), 2),
            'source' => VaultTransactionSource::Historical,
            'ticker' => strtoupper(trim((string) ($data['ticker'] ?? $settings->etf_ticker))),
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param  array{traded_at?: mixed, shares?: float, fill_price?: float, etf_amount?: float, fee?: float, ticker?: string, notes?: string|null}  $data
     */
    public function updateHistoricalPurchase(User $user, VaultTransaction $transaction, array $data): VaultTransaction
    {
        $this->assertOwnedHistorical($user, $transaction);

        $transaction->fill([
            'traded_at' => isset($data['traded_at']) ? Carbon::parse($data['traded_at']) : $transaction->traded_at,
            'shares' => isset($data['shares']) ? round((float) $data['shares'], 6) : $transaction->shares,
            'fill_price' => isset($data['fill_price']) ? round((float) $data['fill_price'], 4) : $transaction->fill_price,
            'etf_amount' => isset($data['etf_amount']) ? round((float) $data['etf_amount'], 2) : $transaction->etf_amount,
            'fee' => isset($data['fee']) ? round((float) $data['fee'], 2) : $transaction->fee,
            'ticker' => isset($data['ticker'])
                ? strtoupper(trim((string) $data['ticker']))
                : $transaction->ticker,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $transaction->notes,
        ]);
        $transaction->save();

        return $transaction->fresh();
    }

    public function deleteHistoricalPurchase(User $user, VaultTransaction $transaction): void
    {
        $this->assertOwnedHistorical($user, $transaction);
        $transaction->delete();
    }

    public function revertDeposit(User $user, VaultDeposit $deposit): void
    {
        if ($deposit->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'deposit' => 'Deze storting hoort niet bij jouw Kluis.',
            ]);
        }

        $latestId = VaultDeposit::query()
            ->where('user_id', $user->id)
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->value('id');

        if ($latestId !== $deposit->id) {
            throw ValidationException::withMessages([
                'deposit' => 'Alleen de laatste bevestigde maand kun je terugdraaien.',
            ]);
        }

        $settings = $this->settingsFor($user);
        $settings->dry_powder_balance = round(
            max(0.0, (float) $settings->dry_powder_balance - (float) $deposit->dry_powder_delta),
            2,
        );
        $settings->save();

        VaultTransaction::query()
            ->where('vault_deposit_id', $deposit->id)
            ->delete();

        $deposit->delete();
    }

    public function holdingsSummary(User $user, bool $forcePrice = false): KluisHoldingsSummary
    {
        $settings = $this->settingsFor($user);
        $dryPowder = (float) $settings->dry_powder_balance;

        $agg = VaultTransaction::query()
            ->where('user_id', $user->id)
            ->selectRaw('COALESCE(SUM(shares), 0) as shares')
            ->selectRaw('COALESCE(SUM(etf_amount), 0) as notional')
            ->selectRaw('COALESCE(SUM(fee), 0) as fees')
            ->selectRaw('COUNT(*) as transaction_count')
            ->first();

        $shares = round((float) ($agg->shares ?? 0), 6);
        $notional = round((float) ($agg->notional ?? 0), 2);
        $fees = round((float) ($agg->fees ?? 0), 2);
        $costBasis = round($notional + $fees, 2);
        $count = (int) ($agg->transaction_count ?? 0);

        $valuation = $this->holdingsPrice($settings, $forcePrice);
        $livePrice = isset($valuation['price']) ? (float) $valuation['price'] : null;
        $priceSymbol = isset($valuation['resolved_symbol']) ? (string) $valuation['resolved_symbol'] : null;
        $holdingsValue = $livePrice !== null
            ? round($shares * $livePrice, 2)
            : null;
        $unrealizedPnl = $holdingsValue !== null
            ? round($holdingsValue - $costBasis, 2)
            : null;
        $totalStrategic = $holdingsValue !== null
            ? round($holdingsValue + $dryPowder, 2)
            : round($costBasis + $dryPowder, 2);

        return new KluisHoldingsSummary(
            shares: $shares,
            costBasis: $costBasis,
            notional: $notional,
            fees: $fees,
            transactionCount: $count,
            livePrice: $livePrice,
            holdingsValue: $holdingsValue,
            unrealizedPnl: $unrealizedPnl,
            dryPowder: $dryPowder,
            totalStrategic: $totalStrategic,
            priceSymbol: $priceSymbol,
        );
    }

    /**
     * Equity curve points: cumulative cost vs marked holdings at each fill + live tip.
     *
     * @return list<array{date: string, label: string, cost_basis: float, holdings_value: float}>
     */
    public function equityCurve(User $user): array
    {
        /** @var Collection<int, VaultTransaction> $transactions */
        $transactions = VaultTransaction::query()
            ->where('user_id', $user->id)
            ->orderBy('traded_at')
            ->orderBy('id')
            ->get();

        $points = [];
        $runningShares = 0.0;
        $runningCost = 0.0;

        foreach ($transactions as $transaction) {
            $runningShares += (float) $transaction->shares;
            $runningCost += $transaction->costBasis();
            $markPrice = (float) $transaction->fill_price;

            $points[] = [
                'date' => $transaction->traded_at->toDateString(),
                'label' => $transaction->traded_at->format('j M Y'),
                'cost_basis' => round($runningCost, 2),
                'holdings_value' => round($runningShares * $markPrice, 2),
            ];
        }

        $summary = $this->holdingsSummary($user);

        if ($summary->hasLivePrice() && $summary->shares > 0) {
            $points[] = [
                'date' => now()->toDateString(),
                'label' => 'Nu',
                'cost_basis' => $summary->costBasis,
                'holdings_value' => (float) $summary->holdingsValue,
            ];
        }

        return $points;
    }

    public function monthAlreadyConfirmed(User $user, ?Carbon $periodMonth = null): bool
    {
        $periodMonth = ($periodMonth ?? now())->copy()->startOfMonth();

        return VaultDeposit::query()
            ->where('user_id', $user->id)
            ->whereDate('period_month', $periodMonth->toDateString())
            ->exists();
    }

    /**
     * @param  array{shares?: float|null, fill_price?: float|null, etf_amount?: float|null, fee?: float|null}|null  $fill
     */
    private function recordFillFromConfirm(
        User $user,
        VaultDeposit $deposit,
        KluisThermometerReading $reading,
        KluisOrderPlan $plan,
        ?array $fill,
    ): void {
        $etfAmount = isset($fill['etf_amount']) && $fill['etf_amount'] !== null
            ? round((float) $fill['etf_amount'], 2)
            : $plan->etfAmount;

        if ($etfAmount <= 0) {
            return;
        }

        $fillPrice = isset($fill['fill_price']) && $fill['fill_price'] !== null && (float) $fill['fill_price'] > 0
            ? round((float) $fill['fill_price'], 4)
            : round($reading->close, 4);

        $shares = isset($fill['shares']) && $fill['shares'] !== null && (float) $fill['shares'] > 0
            ? round((float) $fill['shares'], 6)
            : ($fillPrice > 0 ? round($etfAmount / $fillPrice, 6) : 0.0);

        if ($shares <= 0) {
            return;
        }

        VaultTransaction::query()->create([
            'user_id' => $user->id,
            'vault_deposit_id' => $deposit->id,
            'traded_at' => $deposit->confirmed_at ?? now(),
            'shares' => $shares,
            'fill_price' => $fillPrice,
            'etf_amount' => $etfAmount,
            'fee' => round((float) ($fill['fee'] ?? 0), 2),
            'source' => VaultTransactionSource::MonthlyConfirm,
            'ticker' => $reading->ticker,
            'notes' => null,
        ]);
    }

    /**
     * @param  array{shares?: float|null, fill_price?: float|null, etf_amount?: float|null, fee?: float|null}|null  $fill
     */
    private function fillIsComplete(?array $fill): bool
    {
        if ($fill === null) {
            return false;
        }

        return isset($fill['shares'], $fill['fill_price'])
            && (float) $fill['shares'] > 0
            && (float) $fill['fill_price'] > 0;
    }

    private function assertOwnedHistorical(User $user, VaultTransaction $transaction): void
    {
        if ($transaction->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'transaction' => 'Deze aankoop hoort niet bij jouw Kluis.',
            ]);
        }

        if ($transaction->source !== VaultTransactionSource::Historical) {
            throw ValidationException::withMessages([
                'transaction' => 'Alleen historische aankopen kun je hier bewerken.',
            ]);
        }
    }
}
