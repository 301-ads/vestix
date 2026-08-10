<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\User;
use App\Support\IbkrFillPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IbkrFillPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggested_fill_uses_ibkr_average_cost_not_planned_buy_stop(): void
    {
        $user = User::factory()->create([
            'ibkr_open_positions' => [
                ['symbol' => 'PINS', 'quantity' => 59, 'average_cost' => 23.77],
            ],
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'PINS',
            'signal_high' => 23.80,
            'latest_atr_14' => 0.99,
            // Planned buy-stop (= Order Plan trigger / often confused with limit).
            'entry_price' => 23.90,
            'quantity' => 59,
        ]);

        $this->assertSame(23.90, IbkrFillPrice::plannedBuyStop($scout));
        $this->assertSame(24.00, IbkrFillPrice::plannedLimit($scout));
        $this->assertSame(23.77, IbkrFillPrice::suggestedFillForScout($scout));
        $this->assertNotSame(
            IbkrFillPrice::plannedBuyStop($scout),
            IbkrFillPrice::suggestedFillForScout($scout),
        );
    }

    public function test_suggested_fill_is_null_without_ibkr_average_cost(): void
    {
        $user = User::factory()->create([
            'ibkr_open_positions' => [
                ['symbol' => 'PINS', 'quantity' => 59],
            ],
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'PINS',
            'entry_price' => 23.90,
        ]);

        $this->assertNull(IbkrFillPrice::suggestedFillForScout($scout));
    }
}
