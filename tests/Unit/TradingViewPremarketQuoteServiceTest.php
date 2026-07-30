<?php

namespace Tests\Unit;

use App\Services\TradingViewPremarketQuoteService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TradingViewPremarketQuoteServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vestix.tradingview.scanner_url' => 'https://scanner.tradingview.com/america/scan',
        ]);
    }

    public function test_returns_premarket_close_for_peco_like_gap_down(): void
    {
        Http::fake([
            'scanner.tradingview.com/*' => Http::response([
                'totalCount' => 1,
                'data' => [[
                    's' => 'NASDAQ:PECO',
                    'd' => ['PECO', 42.79, 42.40, 1200, 'NASDAQ', 'stock'],
                ]],
            ]),
        ]);

        $price = app(TradingViewPremarketQuoteService::class)->fetchPremarketPrice('PECO');

        $this->assertEqualsWithDelta(42.40, $price, 0.001);
    }

    public function test_returns_ok_with_null_price_when_no_premarket_trades(): void
    {
        Http::fake([
            'scanner.tradingview.com/*' => Http::response([
                'totalCount' => 1,
                'data' => [[
                    's' => 'NASDAQ:GNTX',
                    'd' => ['GNTX', 23.97, null, null, 'NASDAQ', 'stock'],
                ]],
            ]),
        ]);

        $quote = app(TradingViewPremarketQuoteService::class)->fetchPremarketQuote('GNTX');

        $this->assertTrue($quote['ok']);
        $this->assertNull($quote['price']);
    }

    public function test_returns_not_ok_on_http_failure(): void
    {
        Http::fake([
            'scanner.tradingview.com/*' => Http::response('nope', 500),
        ]);

        $quote = app(TradingViewPremarketQuoteService::class)->fetchPremarketQuote('PECO');

        $this->assertFalse($quote['ok']);
        $this->assertNull($quote['price']);
    }
}
