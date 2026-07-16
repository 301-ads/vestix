<?php

namespace Tests\Unit;

use App\Enums\BankrollCashflowType;
use App\Models\BankrollSnapshot;
use App\Models\User;
use App\Services\AlphaTrackerService;
use App\Services\BankrollCashflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BankrollCashflowAlphaTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_deposit_at_matching_nlv_is_zero_return(): void
    {
        $user = User::factory()->create([
            'baseline_capital' => null,
            'baseline_date' => null,
        ]);

        app(BankrollCashflowService::class)->record(
            $user,
            BankrollCashflowType::Deposit,
            3428.40,
            Carbon::parse('2026-07-16'),
            'IBKR openingsaldo',
        );

        $user->refresh();
        $this->assertSame('2026-07-16', $user->baseline_date->toDateString());
        $this->assertNull($user->baseline_capital);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 3428.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-16',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 3428.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 505,
            'recorded_on' => '2026-07-23',
            'recorded_at' => now(),
        ]);

        $curve = app(AlphaTrackerService::class)->growthCurve($user);

        $this->assertSame(0.0, $curve[0]['portfolio_pct']);
        $this->assertSame(0.0, $curve[1]['portfolio_pct']);
        $this->assertSame(0.0, $curve[1]['adjusted_amount']);
    }

    public function test_extra_deposit_does_not_create_fake_alpha(): void
    {
        $user = User::factory()->create();
        $cashflows = app(BankrollCashflowService::class);

        $cashflows->record($user, BankrollCashflowType::Deposit, 3428.40, Carbon::parse('2026-07-16'));
        $cashflows->record($user, BankrollCashflowType::Deposit, 3000, Carbon::parse('2026-07-20'));

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 3428.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-16',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 6428.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 505,
            'recorded_on' => '2026-07-23',
            'recorded_at' => now(),
        ]);

        $curve = app(AlphaTrackerService::class)->growthCurve($user);

        $this->assertSame(0.0, $curve[1]['portfolio_pct']);
        $this->assertSame(0.0, $curve[1]['adjusted_amount']);
        $this->assertSame(6428.40, $curve[1]['net_external']);
    }

    public function test_trading_gain_is_percent_of_contributed_capital(): void
    {
        $user = User::factory()->create();
        $cashflows = app(BankrollCashflowService::class);

        $cashflows->record($user, BankrollCashflowType::Deposit, 3428.40, Carbon::parse('2026-07-16'));
        $cashflows->record($user, BankrollCashflowType::Deposit, 3000, Carbon::parse('2026-07-20'));

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 3428.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-16',
            'recorded_at' => now(),
        ]);

        // NLV = 6428.40 contributed + $200 trading profit
        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 6628.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 510,
            'recorded_on' => '2026-07-23',
            'recorded_at' => now(),
        ]);

        $curve = app(AlphaTrackerService::class)->growthCurve($user);
        $stats = app(AlphaTrackerService::class)->ytdStats($user);

        // 200 / 6428.40 ≈ 3.11%
        $this->assertEqualsWithDelta(3.11, $curve[1]['portfolio_pct'], 0.01);
        $this->assertEqualsWithDelta(3.11, $stats['portfolio_ytd'], 0.01);
        $this->assertEqualsWithDelta(200.0, $curve[1]['adjusted_amount'], 0.01);
    }

    public function test_withdrawal_reduces_contributed_capital(): void
    {
        $user = User::factory()->create();
        $service = app(BankrollCashflowService::class);

        $service->record($user, BankrollCashflowType::Deposit, 3428.40, Carbon::parse('2026-07-16'));
        $service->record($user, BankrollCashflowType::Deposit, 3000, Carbon::parse('2026-07-18'));
        $service->record($user, BankrollCashflowType::Withdrawal, 500, Carbon::parse('2026-07-20'));

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 3428.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-16',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 5928.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-23',
            'recorded_at' => now(),
        ]);

        $this->assertSame(5928.40, $service->netExternalIn($user, Carbon::parse('2026-07-23')));

        $curve = app(AlphaTrackerService::class)->growthCurve($user);

        $this->assertSame(0.0, $curve[1]['portfolio_pct']);
        $this->assertSame(0.0, $curve[1]['adjusted_amount']);
    }
}
