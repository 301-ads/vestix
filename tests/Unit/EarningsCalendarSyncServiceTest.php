<?php

namespace Tests\Unit;

use App\Enums\EarningsReleaseHour;
use App\Models\Asset;
use App\Models\Position;
use App\Services\EarningsCalendarSyncService;
use App\Services\FinnhubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EarningsCalendarSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_syncs_next_and_last_earnings_from_finnhub(): void
    {
        Cache::flush();

        $asset = Asset::factory()->withoutIcon()->create(['ticker' => 'AAPL']);
        Position::factory()->create([
            'ticker' => 'AAPL',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $finnhub = Mockery::mock(FinnhubService::class);
        $finnhub->shouldReceive('fetchEarningsWindow')
            ->once()
            ->with('AAPL')
            ->andReturn([
                'last' => [
                    'date' => '2026-08-04',
                    'hour' => EarningsReleaseHour::Amc,
                ],
                'next' => [
                    'date' => '2026-11-04',
                    'hour' => EarningsReleaseHour::Bmo,
                ],
            ]);

        $this->app->instance(FinnhubService::class, $finnhub);

        $summary = app(EarningsCalendarSyncService::class)->syncTrackedTickers();

        $this->assertSame(1, $summary['synced']);

        $asset->refresh();
        $this->assertSame('2026-11-04', $asset->next_earnings_date->toDateString());
        $this->assertSame(EarningsReleaseHour::Bmo, $asset->next_earnings_hour);
        $this->assertSame('2026-08-04', $asset->last_earnings_date->toDateString());
        $this->assertSame(EarningsReleaseHour::Amc, $asset->last_earnings_hour);
        $this->assertNotNull($asset->earnings_fetched_at);
    }

    public function test_clears_next_but_keeps_writing_last_when_only_past_earnings_found(): void
    {
        Cache::flush();

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'EC',
            'next_earnings_date' => '2026-08-04',
        ]);

        Position::factory()->create([
            'ticker' => 'EC',
            'asset_id' => $asset->id,
            'status' => 'scout',
        ]);

        $finnhub = Mockery::mock(FinnhubService::class);
        $finnhub->shouldReceive('fetchEarningsWindow')
            ->once()
            ->andReturn([
                'last' => [
                    'date' => '2026-08-04',
                    'hour' => EarningsReleaseHour::Amc,
                ],
                'next' => null,
            ]);

        $this->app->instance(FinnhubService::class, $finnhub);

        app(EarningsCalendarSyncService::class)->syncTicker('EC');

        $asset->refresh();
        $this->assertNull($asset->next_earnings_date);
        $this->assertSame('2026-08-04', $asset->last_earnings_date->toDateString());
    }

    public function test_does_not_overwrite_manual_date_override(): void
    {
        Cache::flush();

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'MSFT',
            'earnings_date_override' => '2026-05-01',
            'earnings_hour_override' => EarningsReleaseHour::Bmo,
        ]);

        Position::factory()->create([
            'ticker' => 'MSFT',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $finnhub = Mockery::mock(FinnhubService::class);
        $finnhub->shouldReceive('fetchEarningsWindow')
            ->once()
            ->andReturn([
                'last' => null,
                'next' => [
                    'date' => '2026-04-15',
                    'hour' => EarningsReleaseHour::Amc,
                ],
            ]);

        $this->app->instance(FinnhubService::class, $finnhub);

        app(EarningsCalendarSyncService::class)->syncTicker('MSFT');

        $asset->refresh();
        $this->assertSame('2026-04-15', $asset->next_earnings_date->toDateString());
        $this->assertSame('2026-05-01', $asset->earnings_date_override->toDateString());
        $this->assertSame(EarningsReleaseHour::Bmo, $asset->earnings_hour_override);
    }
}
