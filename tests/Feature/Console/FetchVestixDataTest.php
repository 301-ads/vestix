<?php

namespace Tests\Feature\Console;

use App\Enums\BrokerOrderStatus;
use App\Models\Position;
use App\Models\User;
use App\Services\MarketDataFetcher;
use App\Support\MarketDataFreshness;
use App\Support\ScoutSetupScorecard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\MarketDataTestTime;
use Tests\Support\PolygonFixtures;
use Tests\TestCase;

class FetchVestixDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{SMA: string}>
     */
    private function smaSeries(int $count = 6, float $latest = 100.0, float $fiveDaysAgo = 99.0): array
    {
        $series = [];

        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $value = $offset === 0
                ? $latest
                : ($offset === 5 ? $fiveDaysAgo : $fiveDaysAgo - 0.5);

            $series['2024-01-'.str_pad((string) ($count - $offset), 2, '0', STR_PAD_LEFT)] = [
                'SMA' => number_format($value, 2, '.', ''),
            ];
        }

        return $series;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vestix.alpha_vantage.api_key' => 'test-key',
            'vestix.alpha_vantage.base_url' => 'https://www.alphavantage.co/query',
            'vestix.alpha_vantage.rate_limit_delay' => 0,
            'vestix.alpha_vantage.intra_request_delay' => 0,
            'vestix.polygon.api_key' => null,
            'vestix.polygon.rate_limit_delay' => 0,
            'vestix.finnhub.api_key' => null,
        ]);

        MarketDataTestTime::freezeBeforeUsMarketClose();

        Cache::forget('vestix:last_api_fetch');
        Cache::forget('vestix:sync_in_progress');
        Cache::forget('vestix:polygon:last_request_at');
        Cache::lock(MarketDataFetcher::syncLockKey())->forceRelease();
    }

    protected function tearDown(): void
    {
        MarketDataTestTime::reset();
        parent::tearDown();
    }

    public function test_command_sets_cache_when_no_open_positions(): void
    {
        $this->artisan('vestix:fetch-data')
            ->expectsOutput('Geen open posities of scouts gevonden. Engine gaat weer in slaapstand.')
            ->assertSuccessful();

        $this->assertNotNull(Cache::get('vestix:last_api_fetch'));
    }

    public function test_command_updates_position_when_polygon_data_is_available(): void
    {
        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
        ]);

        $position = Position::factory()->create([
            'ticker' => 'WDC',
            'latest_close_price' => null,
            'latest_sma_20' => null,
            'latest_atr_14' => null,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => PolygonFixtures::dailyBars(latestClose: 78.20),
            ]),
        ]);

        $this->artisan('vestix:fetch-data')
            ->expectsOutput('Succesvol geüpdatet: WDC')
            ->assertSuccessful();

        $position->refresh();

        $this->assertNotNull($position->latest_close_price);
        $this->assertNotNull($position->latest_sma_20);
        $this->assertNotNull($position->latest_atr_14);
        $this->assertNotNull($position->scout_rsi);
        $this->assertNotNull($position->new_sl);
        $this->assertNotNull($position->recent_close_prices);
        $this->assertNotEmpty($position->recent_close_prices);
        $this->assertEquals('UPDATE', $position->action_command);
        $this->assertNotNull(Cache::get('vestix:last_api_fetch'));
    }

    public function test_command_updates_position_when_alpha_vantage_fallback_is_available(): void
    {
        $position = Position::factory()->create([
            'ticker' => 'WDC',
            'latest_close_price' => null,
            'latest_sma_20' => null,
            'latest_atr_14' => null,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response(['status' => 'ERROR']),
            'www.alphavantage.co/*' => Http::sequence()
                ->push([
                    'Global Quote' => [
                        '03. high' => '79.50',
                        '04. low' => '76.80',
                        '05. price' => '78.20',
                    ],
                ])
                ->push([
                    'Global Quote' => [
                        '03. high' => '79.50',
                        '04. low' => '76.80',
                        '05. price' => '78.20',
                    ],
                ])
                ->push([
                    'Technical Analysis: SMA' => [
                        '2024-01-02' => ['SMA' => '77.00'],
                        '2024-01-03' => ['SMA' => '77.50'],
                    ],
                ])
                ->push([
                    'Technical Analysis: SMA' => [
                        '2024-01-03' => ['SMA' => '75.00'],
                    ],
                ])
                ->push([
                    'Technical Analysis: ATR' => [
                        '2024-01-03' => ['ATR' => '2.80'],
                    ],
                ])
                ->push([
                    'Technical Analysis: RSI' => [
                        '2024-01-03' => ['RSI' => '52.00'],
                    ],
                ]),
        ]);

        $this->artisan('vestix:fetch-data')
            ->expectsOutput('Succesvol geüpdatet: WDC')
            ->assertSuccessful();

        $position->refresh();

        $this->assertEquals(78.20, (float) $position->latest_close_price);
        $this->assertEquals(77.50, (float) $position->latest_sma_20);
        $this->assertEquals(2.80, (float) $position->latest_atr_14);
        $this->assertEquals(52.00, (float) $position->scout_rsi);
        $this->assertEquals(76.10, $position->new_sl);
        $this->assertEquals('UPDATE', $position->action_command);
    }

    public function test_command_fetches_single_position(): void
    {
        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
        ]);

        $user = $this->authenticateFilament();
        $position = Position::factory()->for($user)->scout()->create(['ticker' => 'MSFT']);

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => PolygonFixtures::dailyBars(latestClose: 78.20),
            ]),
            'finnhub.io/*' => Http::response([
                'earningsCalendar' => [
                    [
                        'date' => '2026-07-15',
                        'hour' => 'amc',
                        'symbol' => 'MSFT',
                    ],
                ],
            ]),
        ]);

        config(['vestix.finnhub.api_key' => 'test-finnhub-key']);

        $this->artisan('vestix:fetch-data', [
            '--position-id' => $position->id,
            '--user-id' => $user->id,
        ])->expectsOutput('Succesvol geüpdatet: MSFT')
            ->expectsOutput('Earnings sync voltooid voor MSFT.')
            ->assertSuccessful();

        $position->refresh();
        $position->load('asset');

        $this->assertNotNull($position->latest_close_price);
        $this->assertSame('2026-07-15', $position->asset?->next_earnings_date?->toDateString());
        $this->assertNotNull($position->asset?->earnings_fetched_at);
        $this->assertFalse(MarketDataFreshness::isPositionSyncInProgress($position->id));

        $notification = $user->fresh()->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame('Marktdata bijgewerkt', $notification->data['title']);
        $this->assertStringContainsString('MSFT', $notification->data['body']);
        $this->assertStringContainsString('Earnings:', $notification->data['body']);
    }

    public function test_command_fetches_single_ticker_for_create_form(): void
    {
        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
        ]);

        $user = $this->authenticateFilament();

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => PolygonFixtures::dailyBars(latestClose: 78.20),
            ]),
        ]);

        $this->artisan('vestix:fetch-data', [
            '--ticker' => 'MSFT',
            '--user-id' => $user->id,
        ])->assertSuccessful();

        $payload = Cache::get(MarketDataFreshness::tickerFetchKey($user->id, 'MSFT'));

        $this->assertIsArray($payload);
        $this->assertEqualsWithDelta(78.20, $payload['latest_close_price'], 0.05);

        $notification = $user->fresh()->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertSame('Marktdata klaar', $notification->data['title']);
    }

    public function test_command_clears_sync_in_progress_flag(): void
    {
        Cache::put('vestix:sync_in_progress', now()->toIso8601String(), now()->addHour());

        $this->artisan('vestix:fetch-data')
            ->assertSuccessful();

        $this->assertFalse(MarketDataFreshness::isSyncInProgress());
    }

    public function test_stale_sync_flag_is_cleared_automatically(): void
    {
        Cache::put('vestix:sync_in_progress', now()->subMinutes(25)->toIso8601String(), now()->addHour());

        $this->assertFalse(MarketDataFreshness::isSyncInProgress());
        $this->assertNull(Cache::get('vestix:sync_in_progress'));
    }

    public function test_command_sends_completion_notification_to_user(): void
    {
        $user = $this->authenticateFilament();
        Position::factory()->for($user)->create([
            'ticker' => 'WDC',
            'latest_close_price' => null,
            'latest_sma_20' => null,
            'latest_atr_14' => null,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response(['status' => 'ERROR']),
            'www.alphavantage.co/*' => Http::sequence()
                ->push(['Note' => 'Daily series unavailable in test.'])
                ->push(['Global Quote' => ['05. price' => '78.20']])
                ->push([
                    'Technical Analysis: SMA' => [
                        '2024-01-02' => ['SMA' => '77.00'],
                        '2024-01-03' => ['SMA' => '77.50'],
                    ],
                ])
                ->push(['Technical Analysis: SMA' => ['2024-01-03' => ['SMA' => '75.00']]])
                ->push(['Technical Analysis: ATR' => ['2024-01-03' => ['ATR' => '2.80']]])
                ->push(['Technical Analysis: RSI' => ['2024-01-03' => ['RSI' => '52.00']]]),
        ]);

        $this->artisan('vestix:fetch-data', ['--user-id' => $user->id])
            ->assertSuccessful();

        $notification = $user->fresh()->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame('API-sync voltooid', $notification->data['title']);
        $this->assertStringContainsString('1 van 1 posities', $notification->data['body']);
    }

    public function test_command_does_not_update_position_when_data_is_incomplete(): void
    {
        $position = Position::factory()->create([
            'ticker' => 'WDC',
            'latest_close_price' => 70.00,
            'latest_sma_20' => 75.00,
            'latest_atr_14' => 2.50,
            'status' => 'open',
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response(['status' => 'ERROR']),
            'www.alphavantage.co/*' => Http::sequence()
                ->push(['Note' => 'Daily series unavailable in test.'])
                ->push(['Global Quote' => ['05. price' => '78.20']])
                ->push(['Note' => 'API rate limit reached.'])
                ->push(['Technical Analysis: ATR' => ['2024-01-03' => ['ATR' => '2.80']]]),
        ]);

        $this->artisan('vestix:fetch-data')
            ->expectsOutputToContain('Incomplete data of API limit bereikt voor WDC')
            ->assertSuccessful();

        $position->refresh();

        $this->assertEquals(70.00, (float) $position->latest_close_price);
        $this->assertEquals(75.00, (float) $position->latest_sma_20);
        $this->assertEquals(2.50, (float) $position->latest_atr_14);
    }

    public function test_command_skips_when_sync_lock_is_held(): void
    {
        $lock = Cache::lock(MarketDataFetcher::syncLockKey(), 7200);
        $lock->get();

        try {
            $this->artisan('vestix:fetch-data')
                ->expectsOutput('API-sync draait al. Deze run wordt overgeslagen.')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }
    }

    public function test_command_recalculates_setup_score_for_scouts(): void
    {
        config(['vestix.polygon.api_key' => null]);

        $user = User::factory()->create();
        $position = Position::factory()->for($user)->scout()->create([
            'ticker' => 'APTV',
            'last_setup_score' => null,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response(['status' => 'ERROR']),
            'www.alphavantage.co/*' => Http::sequence()
                ->push(['Note' => 'Daily series unavailable in test.'])
                ->push(['Global Quote' => ['05. price' => '100.50']])
                ->push(['Technical Analysis: SMA' => $this->smaSeries()])
                ->push(['Technical Analysis: SMA' => ['2024-01-06' => ['SMA' => '95.00']]])
                ->push(['Technical Analysis: ATR' => ['2024-01-06' => ['ATR' => '2.00']]])
                ->push(['Technical Analysis: RSI' => ['2024-01-06' => ['RSI' => '50.00']]]),
        ]);

        $this->artisan('vestix:fetch-data')->assertSuccessful();

        $position->refresh();

        $this->assertNotNull($position->last_setup_score);
        $this->assertLessThanOrEqual(ScoutSetupScorecard::maxPoints(), $position->last_setup_score);
    }

    public function test_command_keeps_volume_false_without_bounce_day(): void
    {
        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
        ]);

        $position = Position::factory()->scout()->create([
            'ticker' => 'APTV',
            'bounce_volume_above_average' => false,
            'latest_close_price' => 103.60,
            'latest_sma_20' => 100.00,
        ]);

        $polygonBars = [];

        for ($day = 1; $day <= 60; $day++) {
            $polygonBars[] = [
                'o' => 103,
                'h' => 104,
                'l' => 102,
                'c' => 103.60,
                'v' => 1_000_000,
                't' => now()->subDays(60 - $day)->startOfDay()->timestamp * 1000,
            ];
        }

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => $polygonBars,
            ]),
        ]);

        $this->artisan('vestix:fetch-data')->assertSuccessful();

        $position->refresh();

        $this->assertFalse($position->bounce_volume_above_average);
        $this->assertEquals(1_000_000, $position->avg_volume_30d);
    }

    public function test_command_flags_stale_buy_stop_after_bulk_sync(): void
    {
        config(['vestix.polygon.api_key' => null]);

        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->pendingBrokerOrder()->create([
            'ticker' => 'APTV',
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response(['status' => 'ERROR']),
            'www.alphavantage.co/*' => Http::sequence()
                ->push(['Note' => 'Daily series unavailable in test.'])
                ->push(['Global Quote' => ['05. price' => '100.50']])
                ->push(['Technical Analysis: SMA' => $this->smaSeries()])
                ->push(['Technical Analysis: SMA' => ['2024-01-06' => ['SMA' => '95.00']]])
                ->push(['Technical Analysis: ATR' => ['2024-01-06' => ['ATR' => '2.00']]])
                ->push(['Technical Analysis: RSI' => ['2024-01-06' => ['RSI' => '50.00']]]),
        ]);

        $this->artisan('vestix:fetch-data')
            ->expectsOutputToContain('stale buy-stop(s) gemarkeerd voor ochtend-review')
            ->assertSuccessful();

        $scout->refresh();

        $this->assertSame(BrokerOrderStatus::Scout, $scout->broker_order_status);
        $this->assertNotNull($scout->buy_stop_review_required_on);
        $this->assertNotNull($scout->buy_stop_review_setup_score);
        $this->assertNotNull($scout->buy_stop_review_setup_grade);
    }

    public function test_command_does_not_flag_scout_only_pipeline(): void
    {
        config(['vestix.polygon.api_key' => null]);

        $scout = Position::factory()->scout()->create([
            'ticker' => 'APTV',
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response(['status' => 'ERROR']),
            'www.alphavantage.co/*' => Http::sequence()
                ->push(['Note' => 'Daily series unavailable in test.'])
                ->push(['Global Quote' => ['05. price' => '100.50']])
                ->push(['Technical Analysis: SMA' => $this->smaSeries()])
                ->push(['Technical Analysis: SMA' => ['2024-01-06' => ['SMA' => '95.00']]])
                ->push(['Technical Analysis: ATR' => ['2024-01-06' => ['ATR' => '2.00']]])
                ->push(['Technical Analysis: RSI' => ['2024-01-06' => ['RSI' => '50.00']]]),
        ]);

        $this->artisan('vestix:fetch-data')->assertSuccessful();

        $scout->refresh();

        $this->assertNull($scout->buy_stop_review_required_on);
    }
}
