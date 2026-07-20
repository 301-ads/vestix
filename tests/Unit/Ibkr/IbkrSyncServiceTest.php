<?php

namespace Tests\Unit\Ibkr;

use App\Enums\Broker;
use App\Models\User;
use App\Services\Ibkr\IbkrSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IbkrSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_persists_balances_orders_and_cashflows(): void
    {
        config([
            'vestix.ibkr.flex.token' => 'token',
            'vestix.ibkr.flex.query_id' => '123',
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.flex.poll_delay_ms' => 1,
            'vestix.ibkr.client_portal.enabled' => true,
            'vestix.ibkr.client_portal.base_url' => 'https://cp.test',
            'vestix.ibkr.sync_bankroll_snapshot' => true,
        ]);

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'trading_bankroll' => 1000,
        ]);

        $statement = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_usd.xml'));

        Http::fake([
            'https://flex.test/AccountManagement/FlexWebService/SendRequest*' => Http::response(
                '<?xml version="1.0"?><FlexStatementResponse><Status>Success</Status><ReferenceCode>999</ReferenceCode></FlexStatementResponse>',
                200,
            ),
            'https://flex.test/AccountManagement/FlexWebService/GetStatement*' => Http::response($statement, 200),
            'https://cp.test/v1/api/iserver/account/orders*' => Http::response([
                'orders' => [
                    [
                        'ticker' => 'RPRX',
                        'side' => 'BUY',
                        'orderType' => 'STP LMT',
                        'status' => 'Submitted',
                        'totalSize' => 100,
                        'price' => 32.5,
                        'auxPrice' => 32.0,
                        'orderId' => '888',
                    ],
                ],
            ], 200),
        ]);

        $summary = app(IbkrSyncService::class)->sync($user);

        $this->assertTrue($summary['success']);
        $user->refresh();

        $this->assertEquals(10634.60, (float) $user->ibkr_net_liquidation);
        $this->assertEquals(4200.00, (float) $user->ibkr_available_funds);
        $this->assertEquals(3800.50, (float) $user->ibkr_settled_cash);
        $this->assertEquals(10634.60, (float) $user->trading_bankroll);
        $this->assertFalse((bool) $user->ibkr_data_stale);
        $this->assertSame('RPRX', $user->ibkr_open_orders[0]['symbol']);
        $this->assertSame(3, $summary['cashflows_imported']);
        $this->assertSame(3, $summary['cashflows_skipped']);
        $this->assertIsArray($summary['snapshot']);
        $this->assertSame(10634.60, $summary['snapshot']['net_liquidation']);
        $this->assertSame('2026-07-17', $summary['snapshot']['to_date']);
        $this->assertDatabaseHas('bankroll_snapshots', [
            'user_id' => $user->id,
            'amount' => 10634.60,
        ]);
        $this->assertDatabaseHas('bankroll_cashflows', [
            'external_id' => 'TX-EUR-DEP-001',
            'amount' => 2287.30,
        ]);
        $this->assertDatabaseMissing('bankroll_cashflows', [
            'external_id' => 'TX-FX-001',
        ]);
    }

    public function test_failed_sync_sets_stale_without_wiping_balances(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

        config([
            'vestix.ibkr.flex.token' => 'token',
            'vestix.ibkr.flex.query_id' => '123',
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.stale_after_hours' => 48,
        ]);

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'ibkr_net_liquidation' => 9000,
            'ibkr_last_success_at' => Carbon::parse('2026-07-14 10:00:00'),
            'ibkr_data_stale' => false,
        ]);

        Http::fake([
            'https://flex.test/AccountManagement/FlexWebService/SendRequest*' => Http::response(
                '<?xml version="1.0"?><FlexStatementResponse><Status>Fail</Status><ErrorCode>1015</ErrorCode><ErrorMessage>Token expired</ErrorMessage></FlexStatementResponse>',
                200,
            ),
        ]);

        $summary = app(IbkrSyncService::class)->sync($user);

        $this->assertFalse($summary['success']);
        $user->refresh();
        $this->assertEquals(9000.0, (float) $user->ibkr_net_liquidation);
        $this->assertTrue((bool) $user->ibkr_data_stale);
        $this->assertStringContainsString('Token expired', (string) $user->ibkr_last_error);

        Carbon::setTestNow();
    }
}
