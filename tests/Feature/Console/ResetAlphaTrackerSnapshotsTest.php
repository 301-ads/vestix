<?php

namespace Tests\Feature\Console;

use App\Enums\BankrollCashflowType;
use App\Models\User;
use App\Services\BankrollCashflowService;
use App\Services\BankrollSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ResetAlphaTrackerSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_resets_polluted_snapshots_and_keeps_cashflows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 21:00:00', 'Europe/Amsterdam'));

        $user = User::factory()->create([
            'trading_bankroll' => 10634.60,
            'baseline_date' => '2026-07-15',
        ]);

        $cashflows = app(BankrollCashflowService::class);
        $snapshots = app(BankrollSnapshotService::class);

        $cashflows->record($user, BankrollCashflowType::Deposit, 3428.40, Carbon::parse('2026-07-15'));
        $cashflows->record($user, BankrollCashflowType::Deposit, 1145.10, Carbon::parse('2026-07-17'));

        $snapshots->recordSnapshot($user, 10634.60, Carbon::parse('2026-07-16'));
        $snapshots->recordSnapshot($user, 3437.84, Carbon::parse('2026-07-17'));

        $this->artisan('vestix:reset-alpha-snapshots', [
            '--user' => $user->id,
            '--current' => 4553.67,
        ])->assertSuccessful();

        $user->refresh();

        $this->assertSame(2, $user->bankrollCashflows()->count());
        $this->assertSame(2, $user->bankrollSnapshots()->count());

        $openingSnapshot = $user->bankrollSnapshots()->orderBy('recorded_on')->first();
        $latestSnapshot = $user->bankrollSnapshots()->orderByDesc('recorded_on')->first();

        $this->assertSame('2026-07-15', $openingSnapshot?->recorded_on->toDateString());
        $this->assertEquals(3428.40, (float) $openingSnapshot?->amount);
        $this->assertSame('2026-07-17', $latestSnapshot?->recorded_on->toDateString());
        $this->assertEquals(4553.67, (float) $latestSnapshot?->amount);
        $this->assertDatabaseMissing('bankroll_snapshots', [
            'user_id' => $user->id,
            'amount' => 10634.60,
        ]);
        $this->assertEquals(4553.67, (float) $user->trading_bankroll);
        $this->assertSame('2026-07-15', $user->baseline_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_dry_run_does_not_delete_snapshots(): void
    {
        $user = User::factory()->create();
        app(BankrollCashflowService::class)->record(
            $user,
            BankrollCashflowType::Deposit,
            3428.40,
            Carbon::parse('2026-07-15'),
        );
        app(BankrollSnapshotService::class)->recordSnapshot($user, 10634.60, Carbon::parse('2026-07-16'));

        $this->artisan('vestix:reset-alpha-snapshots', [
            '--user' => $user->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(1, $user->bankrollSnapshots()->count());
    }
}
