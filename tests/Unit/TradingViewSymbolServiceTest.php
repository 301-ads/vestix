<?php

namespace Tests\Unit;

use App\Services\TradingViewSymbolService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TradingViewSymbolServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vestix.tradingview.symbol_search_url' => 'https://symbol-search.tradingview.com/symbol_search/',
            'vestix.tradingview.logo_cdn_url' => 'https://s3-symbol-logo.tradingview.com',
        ]);
    }

    public function test_resolve_symbol_returns_tradingview_logo_for_bac(): void
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
        ]);

        $service = new TradingViewSymbolService;

        $this->assertSame([
            'name' => 'Bank of America Corporation',
            'logoid' => 'bank-of-america',
            'exchange' => 'NYSE',
            'icon_url' => 'https://s3-symbol-logo.tradingview.com/bank-of-america.svg',
        ], $service->resolveSymbol('BAC'));
    }

    public function test_search_for_form_returns_ranked_ticker_options(): void
    {
        Http::fake([
            'symbol-search.tradingview.com/*' => Http::response([
                [
                    'symbol' => 'AAPL',
                    'description' => 'Apple Inc.',
                    'exchange' => 'NASDAQ',
                    'logoid' => 'apple',
                    'is_primary_listing' => true,
                    'country' => 'US',
                ],
                [
                    'symbol' => 'APLE',
                    'description' => 'Apple Hospitality REIT, Inc.',
                    'exchange' => 'NYSE',
                    'logoid' => 'apple-hospitality',
                    'is_primary_listing' => true,
                    'country' => 'US',
                ],
            ]),
        ]);

        $service = new TradingViewSymbolService;

        $this->assertSame([
            'AAPL' => 'AAPL — Apple Inc. (NASDAQ)',
            'APLE' => 'APLE — Apple Hospitality REIT, Inc. (NYSE)',
        ], $service->searchForForm('appl'));
    }

    public function test_resolve_symbol_prefers_us_exchange_on_tie(): void
    {
        Http::fake([
            'symbol-search.tradingview.com/*' => Http::response([
                [
                    'symbol' => 'ASML',
                    'description' => 'ASML Holding NV',
                    'exchange' => 'Euronext Amsterdam',
                    'logoid' => 'asml-eu',
                    'is_primary_listing' => true,
                    'country' => 'NL',
                ],
                [
                    'symbol' => 'ASML',
                    'description' => 'ASML Holding NV',
                    'exchange' => 'NASDAQ',
                    'logoid' => 'asml',
                    'is_primary_listing' => false,
                    'country' => 'US',
                ],
            ]),
        ]);

        $service = new TradingViewSymbolService;

        $result = $service->resolveSymbol('ASML');

        $this->assertSame('asml', $result['logoid']);
        $this->assertSame('NASDAQ', $result['exchange']);
    }
}
