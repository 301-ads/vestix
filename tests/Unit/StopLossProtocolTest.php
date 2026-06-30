<?php

namespace Tests\Unit;

use App\Enums\TrailingStopMode;
use App\Models\Asset;
use App\Models\Position;
use App\Support\StopLossProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StopLossProtocolTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_standard_sl_outside_earnings_window_even_when_overheated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));

        $position = $this->makeOpenPositionWithEarnings('2026-03-20', [
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'latest_close_price' => 90.00,
            'scout_rsi' => 75.00,
        ]);

        $this->assertSame(TrailingStopMode::Standard, StopLossProtocol::activeMode($position));
        $this->assertEquals(76.10, StopLossProtocol::resolve($position));
    }

    public function test_standard_sl_in_window_when_not_overheated_scenario_a(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));

        $position = $this->makeOpenPositionWithEarnings('2026-03-10', [
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'latest_close_price' => 78.00,
            'scout_rsi' => 55.00,
        ]);

        $this->assertTrue(StopLossProtocol::isPreEarningsWindow($position));
        $this->assertFalse(StopLossProtocol::isOverheated($position));
        $this->assertSame(TrailingStopMode::Standard, StopLossProtocol::activeMode($position));
        $this->assertEquals(76.10, StopLossProtocol::resolve($position));
    }

    public function test_aggressive_atr_sl_in_window_when_overheated_scenario_b(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));

        $position = $this->makeOpenPositionWithEarnings('2026-03-10', [
            'latest_sma_20' => 40.00,
            'latest_atr_14' => 2.00,
            'latest_close_price' => 50.00,
            'scout_rsi' => 72.00,
        ]);

        $this->assertTrue(StopLossProtocol::isPreEarningsWindow($position));
        $this->assertTrue(StopLossProtocol::isOverheated($position));
        $this->assertSame(TrailingStopMode::AggressivePreEarnings, StopLossProtocol::activeMode($position));
        $this->assertEquals(47.00, StopLossProtocol::resolve($position));
    }

    public function test_aggressive_prior_day_low_method(): void
    {
        config(['vestix.pre_earnings_trailing.aggressive_method' => 'prior_day_low']);

        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));

        $position = $this->makeOpenPositionWithEarnings('2026-03-10', [
            'latest_sma_20' => 40.00,
            'latest_atr_14' => 2.00,
            'latest_close_price' => 50.00,
            'scout_rsi' => 72.00,
            'prior_day_low' => 48.00,
        ]);

        $this->assertEquals(47.95, StopLossProtocol::resolve($position));
    }

    public function test_aggressive_sl_uses_max_with_standard_when_aggressive_is_lower(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));

        $position = $this->makeOpenPositionWithEarnings('2026-03-10', [
            'latest_sma_20' => 44.00,
            'latest_atr_14' => 0.50,
            'latest_close_price' => 50.00,
            'scout_rsi' => 72.00,
        ]);

        $standard = 43.75;
        $aggressive = 49.25;

        $this->assertEquals($standard, StopLossProtocol::computeStandard(44.00, 0.50));
        $this->assertEquals($aggressive, StopLossProtocol::computeAggressive($position));
        $this->assertEquals(max($standard, $aggressive), StopLossProtocol::resolve($position));
    }

    public function test_resolve_for_indicators_always_uses_standard(): void
    {
        $this->assertEquals(76.10, StopLossProtocol::resolveForIndicators(77.50, 2.80));
    }

    public function test_compute_sma_extension_pct(): void
    {
        $this->assertEqualsWithDelta(4.23, StopLossProtocol::computeSmaExtensionPct(57.88, 55.53), 0.01);
        $this->assertNull(StopLossProtocol::computeSmaExtensionPct(null, 55.53));
    }

    public function test_overheated_requires_both_rsi_and_sma_extension(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));

        $highRsiOnly = $this->makeOpenPositionWithEarnings('2026-03-10', [
            'latest_sma_20' => 48.00,
            'latest_close_price' => 49.00,
            'scout_rsi' => 75.00,
        ], 'RSI1');

        $highExtensionOnly = $this->makeOpenPositionWithEarnings('2026-03-10', [
            'latest_sma_20' => 40.00,
            'latest_close_price' => 50.00,
            'scout_rsi' => 60.00,
        ], 'EXT1');

        $this->assertFalse(StopLossProtocol::isOverheated($highRsiOnly));
        $this->assertFalse(StopLossProtocol::isOverheated($highExtensionOnly));
    }

    /**
     * @param  array<string, mixed>  $positionOverrides
     */
    private function makeOpenPositionWithEarnings(
        string $earningsDate,
        array $positionOverrides = [],
        ?string $ticker = null,
    ): Position {
        $ticker ??= 'T'.fake()->unique()->numerify('###');

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => $ticker,
            'next_earnings_date' => $earningsDate,
        ]);

        return Position::factory()->create(array_merge([
            'ticker' => $ticker,
            'asset_id' => $asset->id,
            'status' => 'open',
        ], $positionOverrides));
    }
}
