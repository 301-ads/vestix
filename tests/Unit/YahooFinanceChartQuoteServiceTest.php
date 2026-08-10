<?php

namespace Tests\Unit;

use App\Services\YahooFinanceChartQuoteService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YahooFinanceChartQuoteServiceTest extends TestCase
{
    public function test_parses_regular_market_price_from_chart_meta(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response([
                'chart' => [
                    'result' => [[
                        'meta' => [
                            'currency' => 'EUR',
                            'symbol' => 'VWCE.DE',
                            'regularMarketPrice' => 165.14,
                            'previousClose' => 164.28,
                        ],
                    ]],
                    'error' => null,
                ],
            ]),
        ]);

        $price = app(YahooFinanceChartQuoteService::class)->fetchLivePrice('VWCE.DE');

        $this->assertEqualsWithDelta(165.14, $price, 0.001);
    }

    public function test_parses_extended_hours_last_print_from_one_minute_bars(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response([
                'chart' => [
                    'result' => [[
                        'meta' => [
                            'regularMarketPrice' => 16.78,
                            'preMarketPrice' => null,
                        ],
                        'timestamp' => [1, 2, 3],
                        'indicators' => [
                            'quote' => [[
                                'close' => [16.80, null, 16.95],
                            ]],
                        ],
                    ]],
                    'error' => null,
                ],
            ]),
        ]);

        $price = app(YahooFinanceChartQuoteService::class)->fetchExtendedHoursLastPrice('EC');

        $this->assertEqualsWithDelta(16.95, $price, 0.001);
    }

    public function test_returns_null_on_http_failure(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response('nope', 500),
        ]);

        $this->assertNull(app(YahooFinanceChartQuoteService::class)->fetchLivePrice('VWCE.DE'));
    }
}
