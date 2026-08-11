<?php

namespace Tests\Feature;

use App\Contracts\DailyBarProvider;
use App\Enums\PremarketScanResult;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PositionPriceChartTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_owner_can_fetch_price_chart_payload(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $position = Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'status' => 'open',
            'entry_price' => 50.0,
            'current_sl' => 47.0,
            'quantity' => 10,
        ]);

        $bars = [];
        $start = Carbon::parse('2026-01-02');
        for ($i = 0; $i < 30; $i++) {
            $close = 48 + ($i * 0.2);
            $bars[] = [
                'open' => $close - 0.1,
                'high' => $close + 0.5,
                'low' => $close - 0.5,
                'close' => $close,
                'volume' => 1_000_000,
                'date' => $start->copy()->addWeekdays($i)->toDateString(),
            ];
        }

        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn([
            'today' => $bars[array_key_last($bars)],
            'adv30' => 1_000_000.0,
            'bars' => $bars,
        ]);
        $this->app->instance(DailyBarProvider::class, $dailyBars);

        $response = $this->actingAs($user)
            ->getJson(route('positions.price-chart', ['position' => $position, 'range' => '1M']));

        $response->assertOk()
            ->assertJsonPath('ticker', 'BAC')
            ->assertJsonPath('range', '1M')
            ->assertJsonPath('series', 'area')
            ->assertJsonMissingPath('candles')
            ->assertJsonMissingPath('premarket')
            ->assertJsonStructure([
                'points' => [['time', 'value']],
                'period_change' => ['absolute', 'percent', 'positive'],
                'levels' => ['entry', 'stop', 'target1'],
                'markers',
            ]);
    }

    public function test_scout_owner_gets_candle_payload_with_premarket_and_signal_levels(): void
    {
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00', 'America/New_York'));

        $user = User::factory()->create();
        $position = Position::factory()->scout()->create([
            'user_id' => $user->id,
            'ticker' => 'RELX',
            'entry_price' => 48.0,
            'signal_high' => 47.5,
            'signal_low' => 45.0,
            'latest_atr_14' => 1.2,
            'latest_sma_20' => 46.5,
            'signal_bar_date' => '2026-07-20',
            'premarket_price' => 49.25,
            'premarket_scan_type' => PremarketScanResult::Ok,
            'premarket_checked_at' => now(),
        ]);

        $bars = [];
        $start = Carbon::parse('2026-05-01');
        for ($i = 0; $i < 80; $i++) {
            $close = 40 + ($i * 0.15);
            $bars[] = [
                'open' => $close - 0.1,
                'high' => $close + 0.3,
                'low' => $close - 0.3,
                'close' => $close,
                'volume' => 1_000_000,
                'date' => $start->copy()->addWeekdays($i)->toDateString(),
            ];
        }

        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn([
            'today' => $bars[array_key_last($bars)],
            'adv30' => 1_000_000.0,
            'bars' => $bars,
        ]);
        $this->app->instance(DailyBarProvider::class, $dailyBars);

        $response = $this->actingAs($user)
            ->getJson(route('positions.price-chart', ['position' => $position, 'range' => '3M']));

        $response->assertOk()
            ->assertJsonPath('ticker', 'RELX')
            ->assertJsonPath('series', 'candles')
            ->assertJsonPath('premarket.checked', true)
            ->assertJsonStructure([
                'candles' => [['time', 'open', 'high', 'low', 'close']],
                'premarket' => ['price', 'label', 'description', 'tone', 'checked'],
                'levels' => ['entry', 'stop', 'target1', 'signal_high', 'signal_low', 'sma20'],
                'markers',
            ]);

        $this->assertSame(47.5, (float) $response->json('levels.signal_high'));
        $this->assertSame(45.0, (float) $response->json('levels.signal_low'));
        $this->assertSame(46.5, (float) $response->json('levels.sma20'));
        $this->assertSame(48.0, (float) $response->json('levels.entry'));
        $this->assertSame(49.25, (float) $response->json('premarket.price'));
        $this->assertSame('signal', $response->json('markers.0.role'));

        Carbon::setTestNow();
    }

    public function test_guest_cannot_fetch_price_chart(): void
    {
        $position = Position::factory()->create(['status' => 'open']);

        $this->getJson(route('positions.price-chart', $position))
            ->assertRedirect();
    }
}
