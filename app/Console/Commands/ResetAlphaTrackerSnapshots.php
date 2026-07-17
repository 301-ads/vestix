<?php

namespace App\Console\Commands;

use App\Enums\BankrollCashflowType;
use App\Models\User;
use App\Services\BankrollCashflowService;
use App\Services\BankrollSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ResetAlphaTrackerSnapshots extends Command
{
    protected $signature = 'vestix:reset-alpha-snapshots
                            {--user= : User ID (defaults to first user with cashflows or IBKR sync)}
                            {--current= : Override current NLV (e.g. 4553.67 from IBKR UI)}
                            {--dry-run : Show what would happen without writing}';

    protected $description = 'Delete polluted bankroll snapshots and rebuild a clean Alpha start from cashflows + current NLV. Keeps deposits/withdrawals.';

    public function handle(
        BankrollSnapshotService $snapshots,
        BankrollCashflowService $cashflows,
    ): int {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->error('No matching user found.');

            return self::FAILURE;
        }

        $timezone = $snapshots->timezone();
        $dryRun = (bool) $this->option('dry-run');

        $existingSnapshots = $user->bankrollSnapshots()->orderBy('recorded_on')->get();
        $opening = $user->bankrollCashflows()
            ->where('type', BankrollCashflowType::Deposit->value)
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->first();

        if ($opening === null) {
            $this->error('User has no deposit cashflows. Record the IBKR opening balance first.');

            return self::FAILURE;
        }

        $openingDate = Carbon::parse($opening->occurred_on->toDateString(), $timezone)->startOfDay();
        $openingAmount = round((float) $opening->amount, 2);

        $currentAmount = $this->resolveCurrentNlv($user);
        $currentDate = now($timezone)->startOfDay();

        $this->table(
            ['Item', 'Value'],
            [
                ['User', "#{$user->id} {$user->email}"],
                ['Snapshots to delete', (string) $existingSnapshots->count()],
                ['Opening deposit', sprintf('$%s on %s', number_format($openingAmount, 2, '.', ''), $openingDate->toDateString())],
                ['Current NLV', sprintf('$%s on %s', number_format($currentAmount, 2, '.', ''), $currentDate->toDateString())],
                ['Cashflows kept', (string) $user->bankrollCashflows()->count()],
                ['Mode', $dryRun ? 'dry-run' : 'write'],
            ],
        );

        if ($existingSnapshots->isNotEmpty()) {
            $this->line('Existing snapshots:');
            foreach ($existingSnapshots as $snapshot) {
                $this->line(sprintf(
                    '  - %s  $%s',
                    $snapshot->recorded_on->toDateString(),
                    number_format((float) $snapshot->amount, 2, '.', ''),
                ));
            }
        }

        if ($dryRun) {
            $this->warn('Dry-run only — nothing written.');

            return self::SUCCESS;
        }

        $deleted = $user->bankrollSnapshots()->delete();

        $snapshots->recordSnapshot($user, $openingAmount, $openingDate);

        if ($currentDate->toDateString() !== $openingDate->toDateString()) {
            $snapshots->recordSnapshot($user, $currentAmount, $currentDate);
        } elseif (abs($currentAmount - $openingAmount) > 0.009) {
            // Same calendar day but NLV already moved — still need two points for the chart.
            $snapshots->recordSnapshot($user, $currentAmount, $currentDate->copy()->addDay());
        }

        $user->forceFill([
            'baseline_date' => $openingDate->toDateString(),
            'baseline_capital' => null,
            'trading_bankroll' => $currentAmount,
        ])->save();

        $netExternal = $cashflows->netExternalIn($user, $currentDate);
        $tradingPnl = round($currentAmount - $netExternal, 2);
        $returnPct = $netExternal > 0
            ? round(($tradingPnl / $netExternal) * 100, 2)
            : null;

        $this->info(sprintf(
            'Reset done: deleted %d snapshot(s). Net external $%s → trading P&L $%s (%s).',
            $deleted,
            number_format($netExternal, 2, '.', ''),
            number_format($tradingPnl, 2, '.', ''),
            $returnPct !== null ? "{$returnPct}%" : 'n/a',
        ));
        $this->comment('Refresh the Prestaties page. Re-run vestix:sync-ibkr when Flex NLV matches IBKR live.');

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $userId = $this->option('user');

        if (filled($userId)) {
            return User::query()->find((int) $userId);
        }

        return User::query()
            ->whereHas('bankrollCashflows')
            ->orderBy('id')
            ->first()
            ?? User::query()->whereNotNull('ibkr_last_success_at')->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();
    }

    private function resolveCurrentNlv(User $user): float
    {
        if (filled($this->option('current'))) {
            return round((float) $this->option('current'), 2);
        }

        if ($user->ibkr_net_liquidation !== null && (float) $user->ibkr_net_liquidation > 0) {
            return round((float) $user->ibkr_net_liquidation, 2);
        }

        if ($user->trading_bankroll !== null && (float) $user->trading_bankroll > 0) {
            return round((float) $user->trading_bankroll, 2);
        }

        return 0.0;
    }
}
