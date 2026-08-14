<?php

namespace Tests\Unit;

use App\Enums\BrokerOrderStatus;
use App\Enums\TradeDirection;
use App\Models\Position;
use App\Models\User;
use App\Services\MarketDataFetcher;
use App\Services\PolygonMarketDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MarketDataFetcherSignalCandleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unlocked_scout_applies_newer_bounce_signal_and_entry(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'SFNC',
            'direction' => TradeDirection::Long,
            'signal_low' => 20.00,
            'signal_high' => 21.00,
            'signal_bar_date' => '2024-02-10',
            'entry_price' => 21.20,
            'market_open_reminder_on' => null,
        ]);

        $this->mockPolygonPayload([
            'latest_open_price' => 22.00,
            'latest_close_price' => 23.11,
            'recent_close_prices' => [23.11],
            'latest_sma_20' => 22.50,
            'sma_20_five_days_ago' => 22.00,
            'sma_20_ten_days_ago' => 21.50,
            'latest_sma_50' => 21.00,
            'latest_atr_14' => 2.00,
            'scout_rsi' => 55.0,
            'prior_day_low' => 22.00,
            'latest_bounce_bar' => [
                'date' => '2024-02-20',
                'open' => 22.00,
                'high' => 22.80,
                'low' => 21.90,
                'close' => 22.50,
                'volume' => 1_500_000.0,
            ],
            'latest_rejection_bar' => null,
        ]);

        $ok = app(MarketDataFetcher::class)->syncPosition($scout, withDelays: false);

        $this->assertTrue($ok);
        $scout->refresh();

        $this->assertSame('2024-02-20', $scout->signal_bar_date?->toDateString());
        $this->assertSame('2024-02-20', $scout->detected_signal_bar_date?->toDateString());
        $this->assertEqualsWithDelta(21.90, (float) $scout->signal_low, 0.01);
        $this->assertEqualsWithDelta(22.80, (float) $scout->signal_high, 0.01);
        $this->assertEqualsWithDelta(23.00, (float) $scout->entry_price, 0.01); // 22.80 + 0.1*2
    }

    public function test_order_plan_locked_scout_does_not_overwrite_signal_when_stop_is_intact(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'SFNC',
            'direction' => TradeDirection::Long,
            'signal_low' => 20.00,
            'signal_high' => 21.00,
            'signal_bar_date' => '2024-02-10',
            'entry_price' => 21.20,
            'latest_atr_14' => 2.00,
            'latest_close_price' => 21.05,
            'market_open_reminder_on' => now()->toDateString(),
        ]);

        $this->mockPolygonPayload([
            'latest_open_price' => 21.00,
            'latest_close_price' => 21.10,
            'recent_close_prices' => [21.10],
            'latest_sma_20' => 20.50,
            'sma_20_five_days_ago' => 20.00,
            'sma_20_ten_days_ago' => 19.50,
            'latest_sma_50' => 19.00,
            'latest_atr_14' => 2.00,
            'scout_rsi' => 55.0,
            'prior_day_low' => 20.80,
            'latest_bounce_bar' => [
                'date' => '2024-02-20',
                'open' => 22.00,
                'high' => 22.80,
                'low' => 21.90,
                'close' => 22.50,
                'volume' => 1_500_000.0,
            ],
            'latest_rejection_bar' => null,
        ]);

        app(MarketDataFetcher::class)->syncPosition($scout, withDelays: false);
        $scout->refresh();

        $this->assertSame('2024-02-10', $scout->signal_bar_date?->toDateString());
        $this->assertEqualsWithDelta(20.00, (float) $scout->signal_low, 0.01);
        $this->assertEqualsWithDelta(21.00, (float) $scout->signal_high, 0.01);
        $this->assertEqualsWithDelta(21.20, (float) $scout->entry_price, 0.01);
        $this->assertSame('2024-02-20', $scout->detected_signal_bar_date?->toDateString());
        $this->assertTrue($scout->signalCandleIsStale());
        $this->assertSame('Signaal 10d', $scout->signalCandleStaleLabel());
    }

    public function test_order_plan_locked_scout_reprices_when_buy_stop_is_through_the_tape(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'BRKB',
            'direction' => TradeDirection::Long,
            'signal_low' => 493.00,
            'signal_high' => 498.50,
            'signal_bar_date' => '2024-02-10',
            'entry_price' => 498.50,
            'latest_atr_14' => 11.20,
            'latest_close_price' => 499.00,
            'market_open_reminder_on' => now()->toDateString(),
        ]);

        $this->mockPolygonPayload([
            'latest_open_price' => 513.98,
            'latest_close_price' => 510.00,
            'recent_close_prices' => [510.00],
            'latest_sma_20' => 506.57,
            'sma_20_five_days_ago' => 504.00,
            'sma_20_ten_days_ago' => 501.00,
            'latest_sma_50' => 498.00,
            'latest_atr_14' => 11.20,
            'scout_rsi' => 52.0,
            'prior_day_low' => 507.96,
            'latest_bounce_bar' => [
                'date' => '2024-02-20',
                'open' => 513.98,
                'high' => 514.38,
                'low' => 507.96,
                'close' => 510.00,
                'volume' => 4_600_000.0,
            ],
            'latest_rejection_bar' => null,
        ]);

        app(MarketDataFetcher::class)->syncPosition($scout, withDelays: false);
        $scout->refresh();

        $this->assertSame('2024-02-20', $scout->signal_bar_date?->toDateString());
        $this->assertEqualsWithDelta(514.38, (float) $scout->signal_high, 0.01);
        $this->assertEqualsWithDelta(515.50, (float) $scout->entry_price, 0.01); // 514.38 + 0.1*11.20
        $this->assertFalse($scout->isPlannedEntryThroughMarket());
    }

    public function test_through_market_reprices_from_session_bar_when_latest_bounce_is_stale(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'BRKB',
            'direction' => TradeDirection::Long,
            'signal_low' => 493.00,
            'signal_high' => 498.50,
            'signal_bar_date' => '2024-02-10',
            'entry_price' => 499.62,
            'latest_atr_14' => 11.20,
            'latest_close_price' => 499.00,
            'market_open_reminder_on' => now()->toDateString(),
        ]);

        $this->mockPolygonPayload([
            'latest_open_price' => 513.98,
            'latest_close_price' => 510.00,
            'recent_close_prices' => [510.00],
            'latest_sma_20' => 506.57,
            'sma_20_five_days_ago' => 504.00,
            'sma_20_ten_days_ago' => 501.00,
            'latest_sma_50' => 498.00,
            'latest_atr_14' => 11.20,
            'scout_rsi' => 52.0,
            'prior_day_low' => 507.96,
            // Today is a red day — the bounce matcher still returns the consumed candle.
            'latest_bounce_bar' => [
                'date' => '2024-02-10',
                'open' => 495.00,
                'high' => 498.50,
                'low' => 493.00,
                'close' => 497.00,
                'volume' => 3_200_000.0,
            ],
            'latest_rejection_bar' => null,
            'latest_session_bar' => [
                'date' => '2024-02-20',
                'open' => 513.98,
                'high' => 514.38,
                'low' => 507.96,
                'close' => 510.00,
                'volume' => 4_600_000.0,
            ],
        ]);

        app(MarketDataFetcher::class)->syncPosition($scout, withDelays: false);
        $scout->refresh();

        $this->assertSame('2024-02-20', $scout->signal_bar_date?->toDateString());
        $this->assertEqualsWithDelta(514.38, (float) $scout->signal_high, 0.01);
        $this->assertEqualsWithDelta(515.50, (float) $scout->entry_price, 0.01);
        $this->assertFalse($scout->isPlannedEntryThroughMarket());
    }

    public function test_same_signal_bar_still_applies_atr_buffer_to_raw_high_entry(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'SFNC',
            'direction' => TradeDirection::Long,
            'signal_low' => 20.00,
            'signal_high' => 21.00,
            'signal_bar_date' => '2024-02-20',
            'entry_price' => 21.00,
            'latest_atr_14' => null,
            'market_open_reminder_on' => null,
        ]);

        $this->mockPolygonPayload([
            'latest_open_price' => 20.80,
            'latest_close_price' => 20.90,
            'recent_close_prices' => [20.90],
            'latest_sma_20' => 20.50,
            'sma_20_five_days_ago' => 20.00,
            'sma_20_ten_days_ago' => 19.50,
            'latest_sma_50' => 19.00,
            'latest_atr_14' => 2.00,
            'scout_rsi' => 55.0,
            'prior_day_low' => 20.40,
            'latest_bounce_bar' => [
                'date' => '2024-02-20',
                'open' => 20.50,
                'high' => 21.00,
                'low' => 20.00,
                'close' => 20.80,
                'volume' => 1_500_000.0,
            ],
            'latest_rejection_bar' => null,
        ]);

        app(MarketDataFetcher::class)->syncPosition($scout, withDelays: false);
        $scout->refresh();

        $this->assertSame('2024-02-20', $scout->signal_bar_date?->toDateString());
        $this->assertEqualsWithDelta(21.00, (float) $scout->signal_high, 0.01);
        $this->assertEqualsWithDelta(21.20, (float) $scout->entry_price, 0.01);
    }

    public function test_order_plan_locked_scout_reprices_same_day_bar_when_through_the_tape(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'BRKB',
            'direction' => TradeDirection::Long,
            'signal_low' => 493.00,
            'signal_high' => 498.50,
            'signal_bar_date' => '2024-02-20',
            'entry_price' => 499.62,
            'latest_atr_14' => 11.20,
            'latest_close_price' => 499.00,
            'market_open_reminder_on' => now()->toDateString(),
        ]);

        $this->mockPolygonPayload([
            'latest_open_price' => 513.98,
            'latest_close_price' => 510.00,
            'recent_close_prices' => [510.00],
            'latest_sma_20' => 506.57,
            'sma_20_five_days_ago' => 504.00,
            'sma_20_ten_days_ago' => 501.00,
            'latest_sma_50' => 498.00,
            'latest_atr_14' => 11.20,
            'scout_rsi' => 52.0,
            'prior_day_low' => 507.96,
            'latest_bounce_bar' => [
                'date' => '2024-02-20',
                'open' => 513.98,
                'high' => 514.38,
                'low' => 507.96,
                'close' => 510.00,
                'volume' => 4_600_000.0,
            ],
            'latest_rejection_bar' => null,
        ]);

        app(MarketDataFetcher::class)->syncPosition($scout, withDelays: false);
        $scout->refresh();

        $this->assertSame('2024-02-20', $scout->signal_bar_date?->toDateString());
        $this->assertEqualsWithDelta(514.38, (float) $scout->signal_high, 0.01);
        $this->assertEqualsWithDelta(515.50, (float) $scout->entry_price, 0.01);
        $this->assertFalse($scout->isPlannedEntryThroughMarket());
    }

    public function test_same_signal_bar_date_is_noop_for_signal_fields(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'SFNC',
            'direction' => TradeDirection::Long,
            'signal_low' => 20.00,
            'signal_high' => 21.00,
            'signal_bar_date' => '2024-02-20',
            'entry_price' => 21.20,
            'market_open_reminder_on' => null,
        ]);

        $this->mockPolygonPayload([
            'latest_open_price' => 20.80,
            'latest_close_price' => 21.10,
            'recent_close_prices' => [21.10],
            'latest_sma_20' => 22.50,
            'sma_20_five_days_ago' => 22.00,
            'sma_20_ten_days_ago' => 21.50,
            'latest_sma_50' => 21.00,
            'latest_atr_14' => 2.00,
            'scout_rsi' => 55.0,
            'prior_day_low' => 22.00,
            'latest_bounce_bar' => [
                'date' => '2024-02-20',
                'open' => 22.00,
                'high' => 99.00,
                'low' => 10.00,
                'close' => 22.50,
                'volume' => 1_500_000.0,
            ],
            'latest_rejection_bar' => null,
        ]);

        app(MarketDataFetcher::class)->syncPosition($scout, withDelays: false);
        $scout->refresh();

        $this->assertEqualsWithDelta(20.00, (float) $scout->signal_low, 0.01);
        $this->assertEqualsWithDelta(21.00, (float) $scout->signal_high, 0.01);
        $this->assertEqualsWithDelta(21.20, (float) $scout->entry_price, 0.01);
        $this->assertFalse($scout->signalCandleIsStale());
    }

    public function test_force_refresh_overwrites_locked_order_plan_scout(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'PONY',
            'direction' => TradeDirection::Short,
            'signal_low' => 6.00,
            'signal_high' => 7.00,
            'signal_bar_date' => '2024-02-10',
            'entry_price' => 5.80,
            'market_open_reminder_on' => now()->toDateString(),
            'broker_order_status' => BrokerOrderStatus::Pending,
            'latest_atr_14' => 1.00,
        ]);

        $this->mockPolygonPayload([
            'latest_open_price' => 6.90,
            'latest_close_price' => 6.50,
            'recent_close_prices' => [6.50],
            'latest_sma_20' => 6.80,
            'sma_20_five_days_ago' => 7.00,
            'sma_20_ten_days_ago' => 7.20,
            'latest_sma_50' => 7.50,
            'latest_atr_14' => 1.00,
            'scout_rsi' => 45.0,
            'prior_day_low' => 6.40,
            'latest_bounce_bar' => null,
            'latest_rejection_bar' => [
                'date' => '2024-02-21',
                'open' => 6.90,
                'high' => 7.10,
                'low' => 6.60,
                'close' => 6.70,
                'volume' => 2_000_000.0,
            ],
        ]);

        $ok = app(MarketDataFetcher::class)->refreshSignalCandle($scout);

        $this->assertTrue($ok);
        $scout->refresh();

        $this->assertSame('2024-02-21', $scout->signal_bar_date?->toDateString());
        $this->assertEqualsWithDelta(6.60, (float) $scout->signal_low, 0.01);
        $this->assertEqualsWithDelta(7.10, (float) $scout->signal_high, 0.01);
        $this->assertEqualsWithDelta(6.50, (float) $scout->entry_price, 0.01); // 6.60 - 0.1*1
    }

    public function test_sync_stores_post_signal_extremes_from_later_session(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'BF.B',
            'direction' => TradeDirection::Long,
            'signal_low' => 27.56,
            'signal_high' => 28.04,
            'signal_bar_date' => '2026-08-12',
            'entry_price' => 28.13,
            'latest_atr_14' => 0.99,
            'market_open_reminder_on' => null,
        ]);

        $this->mockPolygonPayload([
            'latest_open_price' => 28.00,
            'latest_close_price' => 28.03,
            'recent_close_prices' => [28.03],
            'latest_sma_20' => 27.67,
            'sma_20_five_days_ago' => 27.43,
            'sma_20_ten_days_ago' => 27.07,
            'latest_sma_50' => 26.80,
            'latest_atr_14' => 0.99,
            'scout_rsi' => 54.5,
            'prior_day_low' => 27.83,
            'latest_bounce_bar' => [
                'date' => '2026-08-12',
                'open' => 27.64,
                'high' => 28.04,
                'low' => 27.56,
                'close' => 27.92,
                'volume' => 1_120_000.0,
            ],
            'latest_rejection_bar' => null,
            'daily_bars' => [
                [
                    'date' => '2026-08-12',
                    'open' => 27.64,
                    'high' => 28.04,
                    'low' => 27.56,
                    'close' => 27.92,
                    'volume' => 1_120_000.0,
                ],
                [
                    'date' => '2026-08-13',
                    'open' => 28.27,
                    'high' => 28.54,
                    'low' => 27.83,
                    'close' => 28.03,
                    'volume' => 1_430_000.0,
                ],
                [
                    'date' => '2026-08-14',
                    'open' => 28.00,
                    'high' => 28.10,
                    'low' => 27.90,
                    'close' => 28.03,
                    'volume' => 900_000.0,
                ],
            ],
        ]);

        $ok = app(MarketDataFetcher::class)->syncPosition($scout, withDelays: false);

        $this->assertTrue($ok);
        $scout->refresh();

        $this->assertEqualsWithDelta(28.54, (float) $scout->post_signal_high, 0.01);
        $this->assertEqualsWithDelta(27.56, (float) $scout->post_signal_low, 0.01);
        $this->assertTrue($scout->isFailedBreakout());
        $this->assertSame('NO TRADE', $scout->evaluateSetupScore()['grade']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mockPolygonPayload(array $payload): void
    {
        $polygon = Mockery::mock(PolygonMarketDataService::class);
        $polygon->shouldReceive('fetchForTicker')->andReturn($payload);
        $this->app->instance(PolygonMarketDataService::class, $polygon);
    }
}
