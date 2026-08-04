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
            'vestix.premarket.min_flat_print_volume' => 1000,
        ]);
    }

    public function test_returns_premarket_close_for_peco_like_gap_down(): void
    {
        Http::fake([
            'scanner.tradingview.com/*' => Http::response([
                'totalCount' => 1,
                'data' => [[
                    's' => 'NASDAQ:PECO',
                    // name, close, pm_close, pm_open, pm_high, pm_low, pm_volume, exchange, type
                    'd' => ['PECO', 42.79, 42.40, 42.50, 42.55, 42.35, 1200, 'NASDAQ', 'stock'],
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
                    'd' => ['GNTX', 23.97, null, null, null, null, null, 'NASDAQ', 'stock'],
                ]],
            ]),
        ]);

        $quote = app(TradingViewPremarketQuoteService::class)->fetchPremarketQuote('GNTX');

        $this->assertTrue($quote['ok']);
        $this->assertNull($quote['price']);
    }

    public function test_rejects_flat_low_volume_ghost_print_like_wh(): void
    {
        // Scanner still returns premarket_close/volume while TV UI says "Pre-market No trades".
        Http::fake([
            'scanner.tradingview.com/*' => Http::response([
                'totalCount' => 1,
                'data' => [[
                    's' => 'NYSE:WH',
                    'd' => ['WH', 75.27, 75.52, 75.52, 75.52, 75.52, 140, 'NYSE', 'stock'],
                ]],
            ]),
        ]);

        $quote = app(TradingViewPremarketQuoteService::class)->fetchPremarketQuote('WH');

        $this->assertTrue($quote['ok']);
        $this->assertNull($quote['price']);
    }

    public function test_accepts_flat_print_when_volume_meets_threshold(): void
    {
        Http::fake([
            'scanner.tradingview.com/*' => Http::response([
                'totalCount' => 1,
                'data' => [[
                    's' => 'NYSE:FOO',
                    'd' => ['FOO', 50.00, 51.00, 51.00, 51.00, 51.00, 1500, 'NYSE', 'stock'],
                ]],
            ]),
        ]);

        $quote = app(TradingViewPremarketQuoteService::class)->fetchPremarketQuote('FOO');

        $this->assertTrue($quote['ok']);
        $this->assertEqualsWithDelta(51.00, $quote['price'], 0.001);
    }

    public function test_accepts_low_volume_when_premarket_has_range(): void
    {
        Http::fake([
            'scanner.tradingview.com/*' => Http::response([
                'totalCount' => 1,
                'data' => [[
                    's' => 'NASDAQ:PECO',
                    'd' => ['PECO', 42.20, 42.64, 42.31, 42.64, 42.31, 500, 'NASDAQ', 'stock'],
                ]],
            ]),
        ]);

        $quote = app(TradingViewPremarketQuoteService::class)->fetchPremarketQuote('PECO');

        $this->assertTrue($quote['ok']);
        $this->assertEqualsWithDelta(42.64, $quote['price'], 0.001);
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
