<?php

namespace Tests\Unit\Ibkr;

use App\Services\Ibkr\ClientPortalOpenOrdersClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientPortalOpenOrdersClientTest extends TestCase
{
    public function test_returns_empty_when_disabled(): void
    {
        config(['vestix.ibkr.client_portal.enabled' => false]);

        $this->assertSame([], app(ClientPortalOpenOrdersClient::class)->fetchOpenOrders());
    }

    public function test_parses_working_orders(): void
    {
        config([
            'vestix.ibkr.client_portal.enabled' => true,
            'vestix.ibkr.client_portal.base_url' => 'https://cp.test',
        ]);

        Http::fake([
            'https://cp.test/v1/api/iserver/account/orders*' => Http::response([
                'orders' => [
                    ['ticker' => 'rprx', 'side' => 'buy', 'orderType' => 'STP', 'status' => 'Submitted', 'totalSize' => 10, 'orderId' => 1],
                    ['ticker' => 'OCUL', 'side' => 'SELL', 'orderType' => 'LMT', 'status' => 'Filled', 'totalSize' => 5, 'orderId' => 2],
                ],
            ], 200),
        ]);

        $orders = app(ClientPortalOpenOrdersClient::class)->fetchOpenOrders();

        $this->assertCount(1, $orders);
        $this->assertSame('RPRX', $orders[0]->symbol);
    }
}
