<?php

namespace App\Services\Kluis;

use App\Models\User;
use App\Models\VaultDeposit;
use App\Models\VaultSetting;
use App\Support\Kluis\KluisOrderPlan;
use App\Support\Kluis\KluisThermometerReading;
use Illuminate\Support\Carbon;
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

    public function orderPlan(
        VaultSetting $settings,
        float $budget,
        KluisThermometerReading $reading,
    ): KluisOrderPlan {
        return $this->orderPlans->calculate($settings, $budget, $reading);
    }

    public function confirmMonth(
        User $user,
        float $budget,
        KluisThermometerReading $reading,
        ?Carbon $periodMonth = null,
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

        $plan = $this->orderPlan($settings, $budget, $reading);

        $settings->dry_powder_balance = $plan->dryPowderAfter;
        $settings->save();

        return VaultDeposit::query()->create([
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

        $deposit->delete();
    }
}
