<?php

namespace Tests\Unit;

use App\Services\PolygonReferenceService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PolygonReferenceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
        ]);
    }

    public function test_fetch_ticker_branding_returns_name_and_urls(): void
    {
        Http::fake([
            'api.polygon.io/v3/reference/tickers/AAPL*' => Http::response([
                'status' => 'OK',
                'results' => [
                    'name' => 'Apple Inc.',
                    'branding' => [
                        'icon_url' => 'https://api.polygon.io/v1/reference/company-branding/example/icon.png',
                        'logo_url' => 'https://api.polygon.io/v1/reference/company-branding/example/logo.svg',
                    ],
                ],
            ]),
        ]);

        $service = new PolygonReferenceService;

        $this->assertSame([
            'name' => 'Apple Inc.',
            'icon_url' => 'https://api.polygon.io/v1/reference/company-branding/example/icon.png',
            'logo_url' => 'https://api.polygon.io/v1/reference/company-branding/example/logo.svg',
        ], $service->fetchTickerBranding('aapl'));
    }

    public function test_fetch_ticker_branding_returns_null_without_api_key(): void
    {
        config(['vestix.polygon.api_key' => null]);

        Http::fake();

        $service = new PolygonReferenceService;

        $this->assertNull($service->fetchTickerBranding('AAPL'));
        Http::assertNothingSent();
    }
}
