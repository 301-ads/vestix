<?php

namespace Tests\Unit;

use App\Enums\TradeDirection;
use App\Models\Position;
use App\Models\User;
use App\Services\StrategyAnalyticsService;
use App\Support\BrokerOrderTicket;
use App\Support\PositionSizing;
use App\Support\StopLimitBuffer;
use App\Support\StopLossProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShortModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_risk_per_share_and_quantity(): void
    {
        $this->assertSame(2.50, PositionSizing::riskPerShare(100.0, 102.50, TradeDirection::Short));
        $this->assertSame(20, PositionSizing::quantityFromRiskBudget(50.0, 100.0, 102.50, TradeDirection::Short));
        $this->assertNull(PositionSizing::quantityFromRiskBudget(50.0, 100.0, 98.0, TradeDirection::Short));
    }

    public function test_short_target_price_subtracts_reward(): void
    {
        $this->assertSame(95.0, PositionSizing::targetPrice(100.0, 2.5, 2.0, TradeDirection::Short));
        $this->assertSame(105.0, PositionSizing::targetPrice(100.0, 2.5, 2.0, TradeDirection::Long));
    }

    public function test_short_stop_limit_buffer_subtracts(): void
    {
        $this->assertSame(120.25, StopLimitBuffer::limitPrice(120.00));
        $this->assertSame(119.75, StopLimitBuffer::limitPriceForDirection(120.00, TradeDirection::Short));
    }

    public function test_short_standard_stop_is_above_sma(): void
    {
        $this->assertSame(99.0, StopLossProtocol::computeStandard(100.0, 2.0, TradeDirection::Long));
        $this->assertSame(101.0, StopLossProtocol::computeStandard(100.0, 2.0, TradeDirection::Short));
    }

    public function test_compute_sell_stop(): void
    {
        $this->assertSame(99.87, Position::computeSellStop(100.0, 1.30));
        $this->assertNull(Position::computeSellStop(null, 1.30));
    }

    public function test_short_target_and_planned_risk_accessors(): void
    {
        $position = Position::factory()->scout()->short()->make([
            'entry_price' => 100.00,
            'quantity' => 10,
            'latest_sma_20' => 102.00,
            'latest_atr_14' => 2.00,
            'target_1_rr' => 2.0,
        ]);

        // new_sl for short scout = SMA + ATR/2 = 103.00
        $this->assertSame(103.0, $position->new_sl);
        $this->assertSame(3.0, $position->planned_risk_per_share);
        $this->assertSame(94.0, $position->plannedBracketTarget1Price());
    }

    public function test_short_unrealized_pnl_profits_when_price_falls(): void
    {
        $position = Position::factory()->short()->create([
            'status' => 'open',
            'entry_price' => 100.00,
            'quantity' => 10,
            'current_sl' => 103.00,
            'latest_close_price' => 95.00,
            'initial_sl_placed_at' => now(),
        ]);

        $this->assertEqualsWithDelta(50.0, $position->unrealized_pnl, 0.01);
        $this->assertEqualsWithDelta(5.0, $position->unrealized_pnl_percentage, 0.01);
    }

    public function test_short_stopped_out_when_close_hits_stop_above(): void
    {
        $position = Position::factory()->short()->create([
            'status' => 'open',
            'entry_price' => 100.00,
            'quantity' => 10,
            'current_sl' => 103.00,
            'latest_close_price' => 103.50,
            'latest_sma_20' => 101.00,
            'latest_atr_14' => 2.00,
            'initial_sl_placed_at' => now(),
        ]);

        $this->assertSame('STOPPED OUT', $position->action_command);
    }

    public function test_short_trail_update_when_new_sl_moves_lower(): void
    {
        $position = Position::factory()->short()->create([
            'status' => 'open',
            'entry_price' => 100.00,
            'quantity' => 10,
            'current_sl' => 105.00,
            'latest_close_price' => 98.00,
            'latest_sma_20' => 102.00,
            'latest_atr_14' => 2.00,
            'scout_rsi' => 50,
            'initial_sl_placed_at' => now(),
        ]);

        // Standard short SL = 102 + 1 = 103 < current 105 → UPDATE
        $this->assertSame('UPDATE', $position->action_command);
    }

    public function test_ibkr_bracket_ticket_short_labels(): void
    {
        $position = Position::factory()->scout()->short()->make([
            'ticker' => 'XYZ',
            'entry_price' => 50.00,
            'quantity' => 20,
            'latest_sma_20' => 52.00,
            'latest_atr_14' => 2.00,
            'target_1_rr' => 2.0,
            'first_tranche_fraction' => 0.5,
        ]);

        $ticket = BrokerOrderTicket::forIbkrBracket($position);

        $this->assertTrue($ticket['is_short']);
        $this->assertStringContainsString('SHORT', (string) $ticket['warning']);
        $this->assertSame('SELL STOP LIMIT', $ticket['rows'][0]['value']);
        $this->assertSame('Prijs (Sell-Stop)', $ticket['rows'][2]['label']);
        $this->assertSame('Limit Prijs (Min Verkoop)', $ticket['rows'][3]['label']);
        $this->assertSame('Take Profit (BUY LIMIT)', $ticket['rows'][4]['label']);
        $this->assertSame('Stop Loss (BUY STOP)', $ticket['rows'][5]['label']);
        $this->assertLessThan(50.0, (float) str_replace(['$', ','], '', $ticket['rows'][3]['value']));
    }

    public function test_analytics_split_by_direction(): void
    {
        $user = User::factory()->create();

        Position::factory()->for($user)->closed()->create([
            'direction' => TradeDirection::Long,
            'entry_price' => 100,
            'quantity' => 10,
            'exit_price' => 110,
            'current_sl' => 95,
            'initial_sl' => 95,
            'is_legacy' => false,
        ]);

        Position::factory()->for($user)->closed()->short()->create([
            'entry_price' => 100,
            'quantity' => 10,
            'exit_price' => 90,
            'current_sl' => 105,
            'initial_sl' => 105,
            'is_legacy' => false,
        ]);

        $analytics = app(StrategyAnalyticsService::class);

        $this->assertSame(2, $analytics->overallStats($user->id)['total_trades']);
        $this->assertSame(1, $analytics->overallStats($user->id, TradeDirection::Long)['total_trades']);
        $this->assertSame(1, $analytics->overallStats($user->id, TradeDirection::Short)['total_trades']);

        $split = $analytics->pnlSplitByDirection($user->id);
        $this->assertEqualsWithDelta(100.0, $split['long'], 0.01);
        $this->assertEqualsWithDelta(100.0, $split['short'], 0.01);
        $this->assertEqualsWithDelta(200.0, $split['total'], 0.01);
    }

    public function test_user_can_use_short_defaults_false(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canUseShort());
        $this->assertFalse((bool) $user->is_short_enabled);

        $user->forceFill(['is_short_enabled' => true])->save();

        $this->assertTrue($user->fresh()->canUseShort());
    }

    public function test_default_risk_percent_for_direction(): void
    {
        $user = User::factory()->create([
            'default_risk_percent' => 1.5,
            'default_short_risk_percent' => 1.0,
            'is_short_enabled' => true,
        ]);

        $this->assertEqualsWithDelta(1.5, $user->defaultRiskPercentFor(TradeDirection::Long), 0.001);
        $this->assertEqualsWithDelta(1.0, $user->defaultRiskPercentFor(TradeDirection::Short), 0.001);
    }

    public function test_short_risk_percent_falls_back_to_long_when_unset(): void
    {
        $user = User::factory()->create([
            'default_risk_percent' => 1.5,
            'default_short_risk_percent' => null,
            'is_short_enabled' => true,
        ]);

        $this->assertEqualsWithDelta(1.5, $user->defaultRiskPercentFor(TradeDirection::Short), 0.001);
    }

    public function test_position_defaults_to_long_direction(): void
    {
        $position = Position::factory()->create();

        $this->assertTrue($position->isLong());
        $this->assertSame(TradeDirection::Long, $position->tradeDirection());
    }
}
