<?php

namespace Tests\Unit\Ibkr;

use App\Models\User;
use App\Services\Ibkr\StubIbkrAccountReader;
use App\Services\SmartAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartAllocationIbkrCapitalTest extends TestCase
{
    use RefreshDatabase;

    public function test_sizing_uses_min_of_available_funds_and_settled_cash(): void
    {
        $user = User::factory()->create([
            'trading_bankroll' => 10000,
            'ibkr_net_liquidation' => 10000,
            'ibkr_available_funds' => 5000,
            'ibkr_settled_cash' => 3800,
            'ibkr_last_success_at' => now(),
            'ibkr_data_stale' => false,
            'default_risk_percent' => 1,
        ]);

        config(['vestix.ibkr.block_automation_when_stale' => true]);

        $bankroll = app(SmartAllocationService::class)->resolveSizingBankroll($user);

        $this->assertEqualsWithDelta(3800.0, $bankroll, 0.01);
    }

    public function test_sizing_returns_zero_when_ibkr_data_is_stale(): void
    {
        $user = User::factory()->create([
            'trading_bankroll' => 10000,
            'ibkr_available_funds' => 5000,
            'ibkr_settled_cash' => 3800,
            'ibkr_data_stale' => true,
            'ibkr_last_success_at' => now()->subDays(5),
        ]);

        $this->assertSame(0.0, app(SmartAllocationService::class)->resolveSizingBankroll($user));
    }

    public function test_stub_reader_exposes_settled_cash_and_open_orders(): void
    {
        config([
            'vestix.ibkr.stub.settled_cash' => 2500,
            'vestix.ibkr.stub.available_funds' => 3000,
            'vestix.ibkr.stub.open_orders' => [
                [
                    'symbol' => 'RPRX',
                    'quantity' => 100,
                    'side' => 'BUY',
                    'order_type' => 'STP LMT',
                    'status' => 'Submitted',
                ],
            ],
        ]);

        $user = User::factory()->create();
        $reader = new StubIbkrAccountReader;

        $this->assertSame(2500.0, $reader->settledCash($user));
        $this->assertSame(2500.0, $reader->deployableCapital($user));
        $this->assertSame('RPRX', $reader->openOrders($user)[0]['symbol']);
    }
}
