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

    public function test_parses_intraday_ohlc_bars(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response([
                'chart' => [
                    'result' => [[
                        'timestamp' => [1_700_000_000, 1_700_000_300, 1_700_000_600],
                        'indicators' => [
                            'quote' => [[
                                'open' => [10.0, 10.2, null],
                                'high' => [10.5, 10.4, null],
                                'low' => [9.9, 10.1, null],
                                'close' => [10.2, 10.3, null],
                                'volume' => [1000, 1100, null],
                            ]],
                        ],
                    ]],
                    'error' => null,
                ],
            ]),
        ]);

        $bars = app(YahooFinanceChartQuoteService::class)->fetchIntradayBars('PINS', '5m');

        $this->assertNotNull($bars);
        $this->assertCount(2, $bars);
        $this->assertSame(1_700_000_000, $bars[0]['time']);
        $this->assertEqualsWithDelta(10.3, $bars[1]['close'], 0.001);
    }
}
