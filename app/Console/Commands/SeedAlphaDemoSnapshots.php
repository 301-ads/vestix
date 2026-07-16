<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BankrollSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SeedAlphaDemoSnapshots extends Command
{
    protected $signature = 'vestix:seed-alpha-demo-snapshots
                            {--user= : User ID (defaults to first user)}
                            {--weeks=4 : Number of weekly demo points after baseline}
                            {--baseline=3428.40 : Day-1 baseline capital}
                            {--date= : Baseline date (Y-m-d), defaults to today in bankroll timezone}
                            {--force : Allow running outside local/testing}';

    protected $description = 'Set Vestix 2.0 baseline and seed fake weekly Alpha Tracker snapshots (local/testing)';

    public function handle(BankrollSnapshotService $snapshots): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refusing to seed demo snapshots outside local/testing. Pass --force to override.');

            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        $timezone = $snapshots->timezone();
        $baselineAmount = round((float) $this->option('baseline'), 2);
        $baselineDate = filled($this->option('date'))
            ? Carbon::parse((string) $this->option('date'), $timezone)->startOfDay()
            : now($timezone)->startOfDay();
        $weeks = max(1, (int) $this->option('weeks'));

        $user->forceFill([
            'baseline_capital' => $baselineAmount,
            'baseline_date' => $baselineDate->toDateString(),
            'trading_bankroll' => $baselineAmount,
        ])->save();

        $benchmarkStart = 500.0;
        $amounts = $this->demoAmounts($baselineAmount, $weeks);

        foreach ($amounts as $index => $amount) {
            $recordedOn = $baselineDate->copy()->addWeeks($index);
            $benchmarkClose = round($benchmarkStart * (1 + (0.005 * $index)), 4);

            $user->bankrollSnapshots()->updateOrCreate(
                [
                    'recorded_on' => $recordedOn->toDateString(),
                ],
                [
                    'amount' => $amount,
                    'benchmark_ticker' => (string) config('vestix.bankroll_tracker.benchmark_ticker', 'SPY'),
                    'benchmark_close' => $benchmarkClose,
                    'recorded_at' => now(),
                ],
            );
        }

        $user->update(['trading_bankroll' => $amounts[array_key_last($amounts)]]);

        $this->info(sprintf(
            'Seeded baseline $%s on %s for user #%d (%d snapshots).',
            number_format($baselineAmount, 2, '.', ''),
            $baselineDate->toDateString(),
            $user->id,
            count($amounts),
        ));

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $userId = $this->option('user');

        if (filled($userId)) {
            return User::query()->find((int) $userId);
        }

        return User::query()->orderBy('id')->first();
    }

    /**
     * @return list<float>
     */
    private function demoAmounts(float $baseline, int $weeks): array
    {
        // Baseline + a few mild weekly swings so the Alpha chart has shape.
        $multipliers = [1.0, 1.012, 0.997, 1.028, 1.041];
        $amounts = [];

        for ($i = 0; $i <= $weeks; $i++) {
            $multiplier = $multipliers[$i % count($multipliers)];
            if ($i === 0) {
                $multiplier = 1.0;
            }
            $amounts[] = round($baseline * $multiplier, 2);
        }

        return $amounts;
    }
}
