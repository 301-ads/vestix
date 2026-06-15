<?php

namespace Tests\Unit;

use App\Services\AlphaVantageService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlphaVantageServiceTest extends TestCase
{
    private AlphaVantageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vestix.alpha_vantage.api_key' => 'test-key',
            'vestix.alpha_vantage.base_url' => 'https://www.alphavantage.co/query',
        ]);

        $this->service = new AlphaVantageService;
    }

    public function test_fetch_quote_returns_close_price(): void
    {
        Http::fake([
            '*' => Http::response([
                'Global Quote' => [
                    '03. high' => '79.50',
                    '04. low' => '76.80',
                    '05. price' => '78.20',
                ],
            ]),
        ]);

        $this->assertEquals(78.20, $this->service->fetchQuote('WDC'));
    }

    public function test_fetch_global_quote_returns_ohlc_values(): void
    {
        Http::fake([
            '*' => Http::response([
                'Global Quote' => [
                    '03. high' => '79.50',
                    '04. low' => '76.80',
                    '05. price' => '78.20',
                ],
            ]),
        ]);

        $this->assertSame([
            'close' => 78.20,
            'high' => 79.50,
            'low' => 76.80,
        ], $this->service->fetchGlobalQuote('WDC'));
    }

    public function test_fetch_sma20_returns_latest_value(): void
    {
        Http::fake([
            '*' => Http::response([
                'Technical Analysis: SMA' => [
                    '2024-01-02' => ['SMA' => '75.00'],
                    '2024-01-03' => ['SMA' => '77.50'],
                ],
            ]),
        ]);

        $this->assertEquals(77.50, $this->service->fetchSma20('WDC'));
    }

    public function test_fetch_sma20_pair_returns_latest_and_five_days_ago(): void
    {
        Http::fake([
            '*' => Http::response([
                'Technical Analysis: SMA' => [
                    '2024-01-01' => ['SMA' => '70.00'],
                    '2024-01-02' => ['SMA' => '71.00'],
                    '2024-01-03' => ['SMA' => '72.00'],
                    '2024-01-04' => ['SMA' => '73.00'],
                    '2024-01-05' => ['SMA' => '74.00'],
                    '2024-01-06' => ['SMA' => '75.00'],
                    '2024-01-07' => ['SMA' => '77.50'],
                ],
            ]),
        ]);

        $pair = $this->service->fetchSma20Pair('WDC');

        $this->assertEquals(77.50, $pair['latest']);
        $this->assertEquals(71.00, $pair['five_days_ago']);
    }

    public function test_fetch_sma50_returns_latest_value(): void
    {
        Http::fake([
            '*' => Http::response([
                'Technical Analysis: SMA' => [
                    '2024-01-02' => ['SMA' => '68.00'],
                    '2024-01-03' => ['SMA' => '70.50'],
                ],
            ]),
        ]);

        $this->assertEquals(70.50, $this->service->fetchSma50('WDC'));
    }

    public function test_fetch_rsi14_returns_latest_value(): void
    {
        Http::fake([
            '*' => Http::response([
                'Technical Analysis: RSI' => [
                    '2024-01-02' => ['RSI' => '48.00'],
                    '2024-01-03' => ['RSI' => '52.00'],
                ],
            ]),
        ]);

        $this->assertEquals(52.00, $this->service->fetchRsi14('WDC'));
    }

    public function test_fetch_atr14_returns_latest_value(): void
    {
        Http::fake([
            '*' => Http::response([
                'Technical Analysis: ATR' => [
                    '2024-01-02' => ['ATR' => '2.50'],
                    '2024-01-03' => ['ATR' => '2.80'],
                ],
            ]),
        ]);

        $this->assertEquals(2.80, $this->service->fetchAtr14('WDC'));
    }

    public function test_rate_limit_response_returns_null(): void
    {
        Http::fake([
            '*' => Http::response([
                'Note' => 'Thank you for using Alpha Vantage! Our standard API rate limit is 5 requests per minute.',
            ]),
        ]);

        $this->assertNull($this->service->fetchQuote('WDC'));
    }

    public function test_missing_api_key_returns_null_without_http_call(): void
    {
        config(['vestix.alpha_vantage.api_key' => null]);

        Http::fake();

        $this->assertNull($this->service->fetchQuote('WDC'));

        Http::assertNothingSent();
    }
}
