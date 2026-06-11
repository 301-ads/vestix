<?php

namespace Tests\Feature\Console;

use App\Models\Position;
use App\Models\User;
use App\Services\MarketDataFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchSwngDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'swng.alpha_vantage.api_key' => 'test-key',
            'swng.alpha_vantage.base_url' => 'https://www.alphavantage.co/query',
            'swng.alpha_vantage.rate_limit_delay' => 0,
            'swng.alpha_vantage.intra_request_delay' => 0,
            'swng.polygon.api_key' => null,
        ]);

        Cache::forget('swng:last_api_fetch');
        Cache::forget('swng:sync_in_progress');
        Cache::lock(MarketDataFetcher::syncLockKey())->forceRelease();
    }

    public function test_command_sets_cache_when_no_open_positions(): void
    {
        $this->artisan('swng:fetch-data')
            ->expectsOutput('Geen open posities of scouts gevonden. Engine gaat weer in slaapstand.')
            ->assertSuccessful();

        $this->assertNotNull(Cache::get('swng:last_api_fetch'));
    }

    public function test_command_updates_position_when_all_data_is_available(): void
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
            '*' => Http::sequence()
                ->push([
                    'Global Quote' => ['05. price' => '78.20'],
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

        $this->artisan('swng:fetch-data')
            ->expectsOutput('Succesvol geüpdatet: WDC')
            ->assertSuccessful();

        $position->refresh();

        $this->assertEquals(78.20, (float) $position->latest_close_price);
        $this->assertEquals(77.50, (float) $position->latest_sma_20);
        $this->assertEquals(2.80, (float) $position->latest_atr_14);
        $this->assertEquals(52.00, (float) $position->scout_rsi);
        $this->assertEquals(76.10, $position->new_sl);
        $this->assertEquals('UPDATE', $position->action_command);
        $this->assertNotNull(Cache::get('swng:last_api_fetch'));
    }

    public function test_command_clears_sync_in_progress_flag(): void
    {
        Cache::put('swng:sync_in_progress', now()->toIso8601String(), now()->addHour());

        $this->artisan('swng:fetch-data')
            ->assertSuccessful();

        $this->assertFalse(\App\Support\MarketDataFreshness::isSyncInProgress());
    }

    public function test_stale_sync_flag_is_cleared_automatically(): void
    {
        Cache::put('swng:sync_in_progress', now()->subMinutes(25)->toIso8601String(), now()->addHour());

        $this->assertFalse(\App\Support\MarketDataFreshness::isSyncInProgress());
        $this->assertNull(Cache::get('swng:sync_in_progress'));
    }

    public function test_command_sends_completion_notification_to_user(): void
    {
        $user = User::factory()->create();

        $position = Position::factory()->create([
            'ticker' => 'WDC',
            'latest_close_price' => null,
            'latest_sma_20' => null,
            'latest_atr_14' => null,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'Global Quote' => ['05. price' => '78.20'],
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

        $this->artisan('swng:fetch-data', ['--user-id' => $user->id])
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
            '*' => Http::sequence()
                ->push([
                    'Global Quote' => ['05. price' => '78.20'],
                ])
                ->push([
                    'Note' => 'API rate limit reached.',
                ])
                ->push([
                    'Technical Analysis: ATR' => [
                        '2024-01-03' => ['ATR' => '2.80'],
                    ],
                ]),
        ]);

        $this->artisan('swng:fetch-data')
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
            $this->artisan('swng:fetch-data')
                ->expectsOutput('API-sync draait al. Deze run wordt overgeslagen.')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }
    }
}
