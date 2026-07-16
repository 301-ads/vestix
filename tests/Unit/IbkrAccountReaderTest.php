<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Bankroll\IbkrBankrollSource;
use App\Services\Ibkr\FlexIbkrAccountReader;
use App\Services\Ibkr\StubIbkrAccountReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IbkrAccountReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_stub_reader_prefers_trading_bankroll_over_baseline(): void
    {
        $user = User::factory()->create([
            'baseline_capital' => 3428.40,
            'trading_bankroll' => 10600.68,
        ]);

        $reader = new StubIbkrAccountReader;

        $this->assertSame(10600.68, $reader->netLiquidationValue($user));
        $this->assertSame(10600.68, $reader->availableFunds($user));
        $this->assertSame([], $reader->openPositions($user));
    }

    public function test_stub_reader_falls_back_to_baseline_capital(): void
    {
        $user = User::factory()->create([
            'baseline_capital' => 3428.40,
            'trading_bankroll' => null,
        ]);

        $reader = new StubIbkrAccountReader;

        $this->assertSame(3428.40, $reader->netLiquidationValue($user));
    }

    public function test_stub_reader_honors_config_overrides(): void
    {
        config([
            'vestix.ibkr.stub.net_liquidation' => 4000,
            'vestix.ibkr.stub.available_funds' => 2500,
            'vestix.ibkr.stub.open_positions' => [
                ['symbol' => 'OCUL', 'quantity' => 100],
            ],
        ]);

        $user = User::factory()->create(['baseline_capital' => 3428.40]);
        $reader = new StubIbkrAccountReader;

        $this->assertSame(4000.0, $reader->netLiquidationValue($user));
        $this->assertSame(2500.0, $reader->availableFunds($user));
        $this->assertSame([
            ['symbol' => 'OCUL', 'quantity' => 100.0],
        ], $reader->openPositions($user));
    }

    public function test_ibkr_bankroll_source_delegates_to_reader(): void
    {
        $user = User::factory()->create(['baseline_capital' => 3428.40]);
        $source = new IbkrBankrollSource(new StubIbkrAccountReader);

        $this->assertSame(3428.40, $source->resolveAmount($user));
    }

    public function test_flex_reader_throws_until_phase_two(): void
    {
        $user = User::factory()->create();
        $reader = new FlexIbkrAccountReader;

        $this->expectException(RuntimeException::class);
        $reader->netLiquidationValue($user);
    }
}
