<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Models\BankrollSnapshot;
use App\Models\Position;
use App\Models\User;
use App\Services\BankrollSnapshotService;
use App\Services\BenchmarkCloseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class BankrollSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_record_snapshot_syncs_trading_bankroll_and_stores_benchmark(): void
    {
        $resolver = Mockery::mock(BenchmarkCloseResolver::class);
        $resolver->shouldReceive('benchmarkTicker')->andReturn('SPY');
        $resolver->shouldReceive('resolveTradingDayClose')->once()->andReturn(550.25);

        $service = new BankrollSnapshotService($resolver);
        $user = User::factory()->create(['trading_bankroll' => 9000]);

        $snapshot = $service->recordSnapshot($user, 10634.60, Carbon::parse('2026-07-12', 'Europe/Amsterdam'));

        $this->assertSame('10634.60', $snapshot->amount);
        $this->assertSame('550.2500', $snapshot->benchmark_close);
        $this->assertSame('SPY', $snapshot->benchmark_ticker);
        $this->assertEquals(10634.60, (float) $user->fresh()->trading_bankroll);
    }

    public function test_record_snapshot_updates_existing_row_for_same_day(): void
    {
        $resolver = Mockery::mock(BenchmarkCloseResolver::class);
        $resolver->shouldReceive('benchmarkTicker')->andReturn('SPY');
        $resolver->shouldReceive('resolveTradingDayClose')->andReturn(550.25);

        $service = new BankrollSnapshotService($resolver);
        $user = User::factory()->create(['trading_bankroll' => 9000]);

        // Simulate SQLite date column stored as datetime (common after Eloquent date cast).
        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-28 00:00:00',
            'recorded_at' => now(),
        ]);

        $snapshot = $service->recordSnapshot($user, 25000, Carbon::parse('2026-07-28', 'Europe/Amsterdam'));

        $this->assertSame(1, BankrollSnapshot::query()->where('user_id', $user->id)->count());
        $this->assertSame('25000.00', $snapshot->amount);
        $this->assertEquals(25000.0, (float) $user->fresh()->trading_bankroll);
    }

    public function test_resolve_alpha_equity_adds_revolut_cash_to_ibkr_nlv(): void
    {
        $user = User::factory()->create([
            'ibkr_net_liquidation' => 7927.25,
            'revolut_cash' => 2130.70,
            'trading_bankroll' => 7927.25,
        ]);

        Position::factory()->for($user)->create([
            'ticker' => 'BAC',
            'status' => 'open',
            'broker' => Broker::Ibkr,
            'quantity' => 22,
            'entry_price' => 51.50,
            'latest_close_price' => 63.22,
        ]);

        $service = app(BankrollSnapshotService::class);

        $this->assertEqualsWithDelta(7927.25 + 2130.70, $service->resolveAlphaEquity($user), 0.01);
        $this->assertEqualsWithDelta(7927.25 + 2130.70, $service->resolveAlphaEquity($user, 7927.25), 0.01);
    }

    public function test_is_update_due_on_saturday_without_weekly_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00', 'Europe/Amsterdam'));

        $service = app(BankrollSnapshotService::class);
        $user = User::factory()->create();

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-06-28',
            'recorded_at' => now(),
        ]);

        $this->assertTrue($service->isUpdateDue($user));
    }

    public function test_is_not_due_when_snapshot_exists_this_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 10:00:00', 'Europe/Amsterdam'));

        $service = app(BankrollSnapshotService::class);
        $user = User::factory()->create();

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-12',
            'recorded_at' => now(),
        ]);

        $this->assertFalse($service->isUpdateDue($user));
    }

    public function test_is_due_when_last_snapshot_is_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Europe/Amsterdam'));

        $service = app(BankrollSnapshotService::class);
        $user = User::factory()->create();

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-06-20',
            'recorded_at' => now(),
        ]);

        $this->assertTrue($service->isUpdateDue($user));
    }

    public function test_fill_missing_from_ibkr_daily_equity_only_fills_after_latest_snapshot(): void
    {
        $resolver = Mockery::mock(BenchmarkCloseResolver::class);
        $resolver->shouldReceive('benchmarkTicker')->andReturn('SPY');
        $resolver->shouldReceive('resolveTradingDayClose')->andReturn(500.0);

        $service = new BankrollSnapshotService($resolver);
        $user = User::factory()->create([
            'baseline_date' => '2026-07-15',
            'revolut_cash' => 0,
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 5555.82,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 751.83,
            'recorded_on' => '2026-07-15',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 9256.68,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 773.26,
            'recorded_on' => '2026-08-10',
            'recorded_at' => now(),
        ]);

        // Jul 16/17 are before latest snapshot — must NOT invent a fake Revolut-era cliff.
        $filled = $service->fillMissingFromIbkrDailyEquity($user, [
            '2026-07-16' => 3437.84,
            '2026-07-17' => 4555.29,
            '2026-08-10' => 7866.94, // existing latest
            '2026-08-11' => 7900.00, // after latest → catch-up OK
        ], 2130.70);

        $this->assertSame(1, $filled);
        $this->assertDatabaseMissing('bankroll_snapshots', [
            'user_id' => $user->id,
            'recorded_on' => '2026-07-16 00:00:00',
        ]);
        $this->assertDatabaseHas('bankroll_snapshots', [
            'user_id' => $user->id,
            'recorded_on' => '2026-08-11 00:00:00',
            'amount' => 10030.70,
        ]);
    }

    public function test_alpha_tracker_session_date_is_last_completed_us_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 20:00:00', 'Europe/Amsterdam'));

        $date = app(BankrollSnapshotService::class)->alphaTrackerSessionDate();

        $this->assertSame('2026-08-14', $date->toDateString());

        Carbon::setTestNow();
    }
}
