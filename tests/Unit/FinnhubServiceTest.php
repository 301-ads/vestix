<?php

namespace Tests\Unit;

use App\Services\FinnhubService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinnhubServiceTest extends TestCase
{
    private FinnhubService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vestix.finnhub.api_key' => 'test-finnhub-key',
            'vestix.finnhub.base_url' => 'https://finnhub.io/api/v1',
        ]);

        $this->service = new FinnhubService;
    }

    public function test_fetch_quote_returns_close_high_low(): void
    {
        Http::fake([
            'finnhub.io/*' => Http::response([
                'c' => 201.19,
                'h' => 204.975,
                'l' => 199.635,
                'o' => 200.0,
                'pc' => 203.36,
            ]),
        ]);

        $quote = $this->service->fetchQuote('TKO');

        $this->assertNotNull($quote);
        $this->assertEqualsWithDelta(201.19, $quote['close'], 0.001);
        $this->assertEqualsWithDelta(204.975, $quote['high'], 0.001);
        $this->assertEqualsWithDelta(199.635, $quote['low'], 0.001);
    }

    public function test_fetch_recent_bars_builds_daily_series(): void
    {
        Http::fake([
            'finnhub.io/*' => Http::response([
                's' => 'ok',
                't' => [1718409600, 1718496000],
                'o' => [100.0, 101.0],
                'h' => [102.0, 103.0],
                'l' => [99.0, 100.0],
                'c' => [101.0, 102.0],
                'v' => [1_000_000, 1_100_000],
            ]),
        ]);

        $payload = $this->service->fetchRecentBars('TKO', lookbackDays: 10, limit: 10);

        $this->assertNotNull($payload);
        $this->assertCount(2, $payload['bars']);
        $this->assertEqualsWithDelta(102.0, $payload['today']['close'], 0.001);
        $this->assertEqualsWithDelta(1_000_000.0, $payload['adv30'], 0.001);
    }

    public function test_fetch_quote_returns_null_without_api_key(): void
    {
        config(['vestix.finnhub.api_key' => null]);

        $this->assertNull((new FinnhubService)->fetchQuote('TKO'));
    }
}
