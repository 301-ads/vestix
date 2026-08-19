<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Support\BrokerDeepLink;
use Tests\TestCase;

class BrokerDeepLinkTest extends TestCase
{
    public function test_ibkr_stock_url_uses_lowercase_ticker(): void
    {
        config([
            'vestix.brokers.ibkr.stock_url' => 'https://example.com/stocks/{ticker}',
        ]);

        $this->assertSame(
            'https://example.com/stocks/aapl',
            BrokerDeepLink::forStock(Broker::Ibkr, 'AAPL'),
        );
    }

    public function test_returns_null_for_non_ibkr_broker(): void
    {
        $this->assertNull(BrokerDeepLink::forStock(Broker::Revolut, 'AAPL'));
        $this->assertNull(BrokerDeepLink::forStock(Broker::None, 'AAPL'));
        $this->assertNull(BrokerDeepLink::forStock(null, 'AAPL'));
    }

    public function test_link_label_for_ibkr(): void
    {
        $this->assertSame('Open in IBKR', BrokerDeepLink::linkLabel(Broker::Ibkr));
        $this->assertNull(BrokerDeepLink::linkLabel(Broker::Revolut));
        $this->assertNull(BrokerDeepLink::linkLabel(Broker::None));
    }
}
