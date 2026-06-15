<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Position;
use App\Services\AssetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
            'vestix.tradingview.symbol_search_url' => 'https://symbol-search.tradingview.com/symbol_search/',
            'vestix.tradingview.logo_cdn_url' => 'https://s3-symbol-logo.tradingview.com',
        ]);
    }

    public function test_asset_sync_service_downloads_icon_from_tradingview(): void
    {
        Http::fake([
            'symbol-search.tradingview.com/*' => Http::response([
                [
                    'symbol' => 'BAC',
                    'description' => 'Bank of America Corporation',
                    'exchange' => 'NYSE',
                    'logoid' => 'bank-of-america',
                    'is_primary_listing' => true,
                    'country' => 'US',
                ],
            ]),
            's3-symbol-logo.tradingview.com/bank-of-america.svg' => Http::response('<svg></svg>', 200, [
                'Content-Type' => 'image/svg+xml',
            ]),
        ]);

        $asset = app(AssetSyncService::class)->ensureForTicker('BAC');

        $this->assertSame('Bank of America Corporation', $asset->company_name);
        $this->assertNotNull($asset->icon_path);
        $this->assertTrue(Storage::disk('public')->exists($asset->icon_path));
        $this->assertNotNull($asset->fetched_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.polygon.io'));
    }

    public function test_asset_sync_service_falls_back_to_polygon_when_tradingview_missing(): void
    {
        Http::fake([
            'symbol-search.tradingview.com/*' => Http::response([]),
            'api.polygon.io/v3/reference/tickers/SPY*' => Http::response([
                'status' => 'OK',
                'results' => [
                    'name' => 'SPDR S&P 500 ETF Trust',
                    'branding' => [
                        'icon_url' => 'https://api.polygon.io/v1/reference/company-branding/example/spy-icon.png',
                    ],
                ],
            ]),
            'api.polygon.io/v1/reference/company-branding/example/spy-icon.png*' => Http::response('png-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $asset = app(AssetSyncService::class)->ensureForTicker('SPY');

        $this->assertSame('SPDR S&P 500 ETF Trust', $asset->company_name);
        $this->assertNotNull($asset->icon_path);
    }

    public function test_asset_sync_service_skips_fetch_when_icon_already_exists(): void
    {
        $asset = Asset::factory()->create([
            'ticker' => 'MSFT',
            'icon_path' => 'ticker-logos/MSFT-icon.png',
        ]);

        Storage::disk('public')->put($asset->icon_path, 'existing');

        Http::fake();

        $result = app(AssetSyncService::class)->ensureForTicker('MSFT');

        $this->assertSame($asset->id, $result->id);
        Http::assertNothingSent();
    }

    public function test_asset_sync_service_handles_missing_branding_gracefully(): void
    {
        Http::fake([
            'symbol-search.tradingview.com/*' => Http::response([]),
            'api.polygon.io/v3/reference/tickers/SPY*' => Http::response([
                'status' => 'OK',
                'results' => [
                    'name' => 'SPDR S&P 500 ETF Trust',
                    'branding' => [],
                ],
            ]),
        ]);

        $asset = app(AssetSyncService::class)->ensureForTicker('SPY');

        $this->assertSame('SPDR S&P 500 ETF Trust', $asset->company_name);
        $this->assertNull($asset->icon_path);
        $this->assertNotNull($asset->fetched_at);
    }

    public function test_position_create_links_asset_and_downloads_icon(): void
    {
        Http::fake([
            'symbol-search.tradingview.com/*' => Http::response([
                [
                    'symbol' => 'NVDA',
                    'description' => 'NVIDIA Corporation',
                    'exchange' => 'NASDAQ',
                    'logoid' => 'nvidia',
                    'is_primary_listing' => true,
                    'country' => 'US',
                ],
            ]),
            's3-symbol-logo.tradingview.com/nvidia.svg' => Http::response('<svg></svg>', 200, [
                'Content-Type' => 'image/svg+xml',
            ]),
        ]);

        $position = Position::factory()->create([
            'ticker' => 'NVDA',
        ]);

        $position->refresh();

        $this->assertNotNull($position->asset_id);
        $this->assertSame('NVDA', $position->asset->ticker);
        $this->assertNotNull($position->asset->icon_path);
    }

    public function test_sync_assets_command_backfills_positions(): void
    {
        Http::fake([
            'symbol-search.tradingview.com/*' => Http::response([
                [
                    'symbol' => 'TSLA',
                    'description' => 'Tesla, Inc.',
                    'exchange' => 'NASDAQ',
                    'logoid' => 'tesla',
                    'is_primary_listing' => true,
                    'country' => 'US',
                ],
            ]),
            's3-symbol-logo.tradingview.com/tesla.svg' => Http::response('<svg></svg>', 200, [
                'Content-Type' => 'image/svg+xml',
            ]),
        ]);

        $position = Position::factory()->create([
            'ticker' => 'TSLA',
            'asset_id' => null,
        ]);

        $this->artisan('vestix:sync-assets', ['--delay' => 0])
            ->assertSuccessful();

        $position->refresh();

        $this->assertNotNull($position->asset_id);
        $this->assertNotNull($position->asset->icon_path);
    }
}
