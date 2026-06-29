<?php

namespace Tests\Feature;

use App\Enums\TrailingStopMode;
use App\Models\Asset;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PositionAccessorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_new_sl_calculates_sma_minus_half_atr(): void
    {
        $position = Position::factory()->make([
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
        ]);

        $this->assertEquals(76.10, $position->new_sl);
    }

    public function test_new_sl_returns_null_without_indicators(): void
    {
        $position = Position::factory()->make([
            'latest_sma_20' => null,
            'latest_atr_14' => 2.80,
        ]);

        $this->assertNull($position->new_sl);
    }

    public function test_buy_stop_calculates_high_plus_ten_percent_atr(): void
    {
        $this->assertEquals(68.13, Position::computeBuyStop(68.00, 1.30));
    }

    public function test_buy_stop_returns_null_without_inputs(): void
    {
        $this->assertNull(Position::computeBuyStop(null, 1.30));
        $this->assertNull(Position::computeBuyStop(68.00, null));
        $this->assertNull(Position::computeBuyStop('', 1.30));
        $this->assertNull(Position::computeBuyStop(68.00, ''));
    }

    public function test_buy_stop_returns_null_for_non_positive_inputs(): void
    {
        $this->assertNull(Position::computeBuyStop(0, 1.30));
        $this->assertNull(Position::computeBuyStop(68.00, 0));
        $this->assertNull(Position::computeBuyStop(-1.00, 1.30));
    }

    public function test_action_command_is_awaiting_data_without_close(): void
    {
        $position = Position::factory()->make([
            'latest_close_price' => null,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
        ]);

        $this->assertEquals('AWAITING DATA', $position->action_command);
    }

    public function test_action_command_is_stopped_out_when_below_sl(): void
    {
        $position = Position::factory()->make([
            'latest_close_price' => 70.00,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
        ]);

        $this->assertEquals('STOPPED OUT', $position->action_command);
    }

    public function test_action_command_is_stopped_out_when_equal_to_sl(): void
    {
        $position = Position::factory()->make([
            'latest_close_price' => 74.50,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
        ]);

        $this->assertEquals('STOPPED OUT', $position->action_command);
    }

    public function test_stopped_out_scope_matches_open_positions_at_or_below_sl(): void
    {
        $stoppedOut = Position::factory()->create([
            'latest_close_price' => 74.50,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $aboveSl = Position::factory()->create([
            'latest_close_price' => 80.00,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $ids = Position::query()->stoppedOut()->pluck('id');

        $this->assertTrue($ids->contains($stoppedOut->id));
        $this->assertFalse($ids->contains($aboveSl->id));
    }

    public function test_action_command_is_update_when_new_sl_higher(): void
    {
        $position = Position::factory()->make([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
        ]);

        $this->assertEquals('UPDATE', $position->action_command);
    }

    public function test_action_command_is_hold_when_new_sl_not_higher(): void
    {
        $position = Position::factory()->make([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 75.00,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
        ]);

        $this->assertEquals('HOLD', $position->action_command);
    }

    public function test_requires_sl_update_scope_matches_update_positions_only(): void
    {
        $updatePosition = Position::factory()->create([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $holdPosition = Position::factory()->create([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 75.00,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $ids = Position::query()->requiresSlUpdate()->pluck('id');

        $this->assertTrue($ids->contains($updatePosition->id));
        $this->assertFalse($ids->contains($holdPosition->id));
        $this->assertEquals('UPDATE', $updatePosition->action_command);
        $this->assertEquals('HOLD', $holdPosition->action_command);
    }

    public function test_requires_action_scope_matches_stopped_out_and_update_positions_only(): void
    {
        $stoppedOut = Position::factory()->create([
            'latest_close_price' => 74.50,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $updatePosition = Position::factory()->create([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $holdPosition = Position::factory()->create([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 75.00,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $ids = Position::query()->requiresAction()->pluck('id');

        $this->assertTrue($ids->contains($stoppedOut->id));
        $this->assertTrue($ids->contains($updatePosition->id));
        $this->assertFalse($ids->contains($holdPosition->id));
    }

    public function test_new_sl_rounds_to_two_decimals_for_display_and_storage(): void
    {
        $position = Position::factory()->make([
            'latest_sma_20' => 51.71,
            'latest_atr_14' => 1.13,
            'current_sl' => 51.15,
        ]);

        $this->assertEquals(51.15, $position->new_sl);
        $this->assertEquals('HOLD', $position->action_command);
    }

    public function test_capital_risk_dollars_for_position_below_entry(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 80.00,
            'current_sl' => 74.50,
            'quantity' => 10,
        ]);

        $this->assertEqualsWithDelta(55.0, $position->capital_risk_dollars, 0.01);
        $this->assertEquals(0, $position->locked_in_profit_dollars);
        $this->assertEqualsWithDelta($position->capital_risk_dollars, $position->risk_dollars, 0.01);
    }

    public function test_locked_in_profit_dollars_for_winner_position(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 875.00,
            'current_sl' => 1500.00,
            'quantity' => 2,
        ]);

        $this->assertEquals(0, $position->capital_risk_dollars);
        $this->assertEqualsWithDelta(1250.0, $position->locked_in_profit_dollars, 0.01);
        $this->assertEqualsWithDelta($position->capital_risk_dollars, $position->risk_dollars, 0.01);
    }

    public function test_capital_risk_and_locked_profit_are_zero_when_sl_equals_entry(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 100.00,
            'current_sl' => 100.00,
            'quantity' => 5,
        ]);

        $this->assertEquals(0, $position->capital_risk_dollars);
        $this->assertEquals(0, $position->locked_in_profit_dollars);
    }

    public function test_capital_risk_and_locked_profit_return_zero_without_required_data(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 100.00,
            'current_sl' => null,
            'quantity' => 5,
        ]);

        $this->assertEquals(0, $position->capital_risk_dollars);
        $this->assertEquals(0, $position->locked_in_profit_dollars);
    }

    public function test_current_value_calculates_market_value(): void
    {
        $position = Position::factory()->make([
            'latest_close_price' => 1777.77,
            'quantity' => 2,
        ]);

        $this->assertEqualsWithDelta(3555.54, $position->current_value, 0.01);
    }

    public function test_current_value_returns_zero_without_close(): void
    {
        $position = Position::factory()->make([
            'latest_close_price' => null,
            'quantity' => 2,
        ]);

        $this->assertEquals(0, $position->current_value);
    }

    public function test_unrealized_pnl_calculates_positive_gain(): void
    {
        $position = Position::factory()->make([
            'entry_price' => 875.56,
            'latest_close_price' => 1777.77,
            'quantity' => 2,
        ]);

        $this->assertEqualsWithDelta(1804.42, $position->unrealized_pnl, 0.01);
    }

    public function test_unrealized_pnl_calculates_negative_loss(): void
    {
        $position = Position::factory()->make([
            'entry_price' => 100.00,
            'latest_close_price' => 80.00,
            'quantity' => 10,
        ]);

        $this->assertEqualsWithDelta(-200.0, $position->unrealized_pnl, 0.01);
    }

    public function test_unrealized_pnl_percentage_calculates_gain(): void
    {
        $position = Position::factory()->make([
            'entry_price' => 875.56,
            'latest_close_price' => 1777.77,
            'quantity' => 2,
        ]);

        $this->assertEqualsWithDelta(103.04, $position->unrealized_pnl_percentage, 0.01);
    }

    public function test_unrealized_pnl_percentage_returns_zero_without_close(): void
    {
        $position = Position::factory()->make([
            'entry_price' => 875.56,
            'latest_close_price' => null,
            'quantity' => 2,
        ]);

        $this->assertEquals(0, $position->unrealized_pnl_percentage);
    }

    public function test_unrealized_pnl_percentage_returns_zero_with_zero_entry(): void
    {
        $position = Position::factory()->make([
            'entry_price' => 0,
            'latest_close_price' => 100.00,
            'quantity' => 2,
        ]);

        $this->assertEquals(0, $position->unrealized_pnl_percentage);
    }

    public function test_closed_position_pnl_uses_exit_price(): void
    {
        $position = Position::factory()->make([
            'status' => 'closed',
            'entry_price' => 100.00,
            'exit_price' => 90.00,
            'latest_close_price' => 95.00,
            'quantity' => 10,
        ]);

        $this->assertEqualsWithDelta(-100.0, $position->unrealized_pnl, 0.01);
        $this->assertEqualsWithDelta(-10.0, $position->unrealized_pnl_percentage, 0.01);
        $this->assertEquals(0, $position->risk_dollars);
    }

    public function test_closed_position_market_data_is_frozen_on_update(): void
    {
        $position = Position::factory()->create([
            'status' => 'closed',
            'exit_price' => 90.00,
            'closed_at' => now(),
            'latest_close_price' => 90.00,
            'latest_sma_20' => 95.00,
            'latest_atr_14' => 2.00,
            'current_sl' => 88.00,
            'entry_price' => 100.00,
            'quantity' => 5,
        ]);

        $position->update([
            'latest_close_price' => 50.00,
            'latest_sma_20' => 40.00,
            'latest_atr_14' => 1.00,
            'current_sl' => 45.00,
            'entry_price' => 80.00,
            'quantity' => 20,
        ]);

        $fresh = $position->fresh();

        $this->assertEquals(90.00, (float) $fresh->latest_close_price);
        $this->assertEquals(95.00, (float) $fresh->latest_sma_20);
        $this->assertEquals(2.00, (float) $fresh->latest_atr_14);
        $this->assertEquals(88.00, (float) $fresh->current_sl);
        $this->assertEquals(100.00, (float) $fresh->entry_price);
        $this->assertEquals(5, (float) $fresh->quantity);
    }

    public function test_pre_earnings_scenario_a_uses_standard_sl_when_not_overheated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'SCNA',
            'next_earnings_date' => '2026-03-10',
        ]);

        $position = Position::factory()->create([
            'ticker' => 'SCNA',
            'asset_id' => $asset->id,
            'status' => 'open',
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'latest_close_price' => 78.00,
            'scout_rsi' => 55.00,
            'current_sl' => 74.50,
        ]);

        $this->assertSame(TrailingStopMode::Standard, $position->trailing_stop_mode);
        $this->assertEquals(76.10, $position->new_sl);
        $this->assertEquals('UPDATE', $position->action_command);
    }

    public function test_pre_earnings_scenario_b_uses_aggressive_sl_when_overheated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'SCNB',
            'next_earnings_date' => '2026-03-10',
        ]);

        $position = Position::factory()->create([
            'ticker' => 'SCNB',
            'asset_id' => $asset->id,
            'status' => 'open',
            'latest_sma_20' => 40.00,
            'latest_atr_14' => 2.00,
            'latest_close_price' => 50.00,
            'scout_rsi' => 72.00,
            'current_sl' => 39.00,
        ]);

        $this->assertSame(TrailingStopMode::AggressivePreEarnings, $position->trailing_stop_mode);
        $this->assertEquals(47.00, $position->new_sl);
        $this->assertEquals('UPDATE', $position->action_command);
    }
}
