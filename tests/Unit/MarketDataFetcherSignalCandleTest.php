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

    public function test_order_plan_locked_scout_does_not_overwrite_signal(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'SFNC',
            'direction' => TradeDirection::Long,
            'signal_low' => 20.00,
            'signal_high' => 21.00,
            'signal_bar_date' => '2024-02-10',
            'entry_price' => 21.20,
            'market_open_reminder_on' => now()->toDateString(),
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
