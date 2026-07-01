<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Support\BrokerDeepLink;
use Tests\TestCase;

class BrokerDeepLinkTest extends TestCase
{
    public function test_revolut_stock_url_uses_lowercase_ticker(): void
    {
        config([
            'vestix.brokers.revolut.stock_url' => 'https://www.revolut.com/app-invest/stocks/{ticker}',
        ]);

        $this->assertSame(
            'https://www.revolut.com/app-invest/stocks/aapl',
            BrokerDeepLink::forStock(Broker::Revolut, 'AAPL'),
        );
    }

    public function test_returns_null_for_none_broker(): void
    {
        $this->assertNull(BrokerDeepLink::forStock(Broker::None, 'AAPL'));
        $this->assertNull(BrokerDeepLink::forStock(null, 'AAPL'));
    }

    public function test_link_label_for_revolut(): void
    {
        $this->assertSame('Open in Revolut', BrokerDeepLink::linkLabel(Broker::Revolut));
        $this->assertNull(BrokerDeepLink::linkLabel(Broker::None));
    }
}
