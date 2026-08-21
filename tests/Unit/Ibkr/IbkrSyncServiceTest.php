<?php

namespace Tests\Unit\Ibkr;

use App\Enums\Broker;
use App\Models\BankrollSnapshot;
use App\Models\User;
use App\Services\BenchmarkCloseResolver;
use App\Services\Ibkr\IbkrSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class IbkrSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_sync_persists_balances_orders_and_cashflows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 08:00:00', 'Europe/Amsterdam'));

        config([
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.flex.poll_delay_ms' => 1,
            'vestix.ibkr.flex.inter_user_delay_ms' => 0,
            'vestix.ibkr.client_portal.enabled' => true,
            'vestix.ibkr.client_portal.base_url' => 'https://cp.test',
            'vestix.ibkr.sync_bankroll_snapshot' => true,
        ]);

        $this->mock(BenchmarkCloseResolver::class, function ($mock): void {
            $mock->shouldReceive('benchmarkTicker')->andReturn('SPY');
            $mock->shouldReceive('resolveTradingDayClose')->andReturn(757.67);
            $mock->shouldReceive('warmClosesBetween')->andReturn([]);
        });

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'trading_bankroll' => 1000,
        ]);
        $user->storeIbkrFlexCredentials('token', '123');
        config(['vestix.ibkr.client_portal.owner_user_id' => $user->id]);

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

        $snapshot = BankrollSnapshot::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($snapshot);
        // Premarket Tuesday → Alpha Tracker point belongs to Monday's completed session.
        $this->assertSame('2026-08-03', $snapshot->recorded_on->toDateString());
        $this->assertSame('757.6700', $snapshot->benchmark_close);

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
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.stale_after_hours' => 48,
            'vestix.ibkr.flex.inter_user_delay_ms' => 0,
        ]);

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'ibkr_net_liquidation' => 9000,
            'ibkr_last_success_at' => Carbon::parse('2026-07-14 10:00:00'),
            'ibkr_data_stale' => false,
        ]);
        $user->storeIbkrFlexCredentials('token', '123');

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

    public function test_activity_flex_without_af_preserves_existing_available_funds(): void
    {
        config([
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.flex.poll_delay_ms' => 1,
            'vestix.ibkr.flex.inter_user_delay_ms' => 0,
            'vestix.ibkr.client_portal.enabled' => false,
            'vestix.ibkr.sync_bankroll_snapshot' => false,
        ]);

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'trading_bankroll' => 1000,
            'ibkr_available_funds' => 7609.08,
            'ibkr_settled_cash' => 5000,
        ]);
        $user->storeIbkrFlexCredentials('token', '123');

        $statement = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_real_structure.xml'));

        Http::fake([
            'https://flex.test/AccountManagement/FlexWebService/SendRequest*' => Http::response(
                '<?xml version="1.0"?><FlexStatementResponse><Status>Success</Status><ReferenceCode>999</ReferenceCode></FlexStatementResponse>',
                200,
            ),
            'https://flex.test/AccountManagement/FlexWebService/GetStatement*' => Http::response($statement, 200),
        ]);

        $summary = app(IbkrSyncService::class)->sync($user);

        $this->assertTrue($summary['success']);
        $this->assertFalse($summary['snapshot']['available_funds_explicit']);
        $user->refresh();

        // NLV + Settled/Cash from Flex; Available Funds preserved (not Cash proxy).
        $this->assertEquals(4555.29, (float) $user->ibkr_net_liquidation);
        $this->assertEquals(2723.73, (float) $user->ibkr_settled_cash);
        $this->assertEquals(7609.08, (float) $user->ibkr_available_funds);
    }

    public function test_sync_does_not_fan_out_to_users_without_credentials(): void
    {
        config([
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.flex.poll_delay_ms' => 1,
            'vestix.ibkr.flex.inter_user_delay_ms' => 0,
            'vestix.ibkr.client_portal.enabled' => false,
            'vestix.ibkr.sync_bankroll_snapshot' => false,
            // Env must not be used as a shared fallback.
            'vestix.ibkr.flex.token' => 'env-token',
            'vestix.ibkr.flex.query_id' => '999',
        ]);

        $connected = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $connected->storeIbkrFlexCredentials('user-token', '111');

        $leakedCandidate = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'trading_bankroll' => 5000,
            'ibkr_net_liquidation' => 5000,
        ]);

        $statement = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_usd.xml'));

        Http::fake([
            'https://flex.test/AccountManagement/FlexWebService/SendRequest*' => Http::response(
                '<?xml version="1.0"?><FlexStatementResponse><Status>Success</Status><ReferenceCode>999</ReferenceCode></FlexStatementResponse>',
                200,
            ),
            'https://flex.test/AccountManagement/FlexWebService/GetStatement*' => Http::response($statement, 200),
        ]);

        $summary = app(IbkrSyncService::class)->sync();

        $this->assertTrue($summary['success']);
        $this->assertSame(1, $summary['users']);
        $this->assertSame(1, $summary['synced']);

        $connected->refresh();
        $leakedCandidate->refresh();

        $this->assertEquals(10634.60, (float) $connected->ibkr_net_liquidation);
        $this->assertEquals(5000.0, (float) $leakedCandidate->ibkr_net_liquidation);
        $this->assertNull($leakedCandidate->ibkr_last_success_at);
    }

    public function test_two_users_sync_with_own_credentials_and_isolated_failures(): void
    {
        config([
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.flex.poll_delay_ms' => 1,
            'vestix.ibkr.flex.inter_user_delay_ms' => 0,
            'vestix.ibkr.client_portal.enabled' => false,
            'vestix.ibkr.sync_bankroll_snapshot' => false,
        ]);

        $userA = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $userA->storeIbkrFlexCredentials('token-a', '100');

        $userB = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $userB->storeIbkrFlexCredentials('token-b', '200');

        $statementA = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_usd.xml'));
        $statementB = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_real_structure.xml'));

        Http::fake(function ($request) use ($statementA, $statementB) {
            $url = $request->url();

            if (str_contains($url, 'SendRequest')) {
                $query = $request['q'] ?? '';

                return Http::response(
                    '<?xml version="1.0"?><FlexStatementResponse><Status>Success</Status><ReferenceCode>ref-'.$query.'</ReferenceCode></FlexStatementResponse>',
                    200,
                );
            }

            if (str_contains($url, 'GetStatement')) {
                $ref = $request['q'] ?? '';

                if (str_contains($ref, '100')) {
                    return Http::response($statementA, 200);
                }

                if (str_contains($ref, '200')) {
                    return Http::response($statementB, 200);
                }
            }

            return Http::response('unexpected', 500);
        });

        $summary = app(IbkrSyncService::class)->sync();

        $this->assertTrue($summary['success']);
        $this->assertSame(2, $summary['synced']);

        $userA->refresh();
        $userB->refresh();

        $this->assertEquals(10634.60, (float) $userA->ibkr_net_liquidation);
        $this->assertEquals(4555.29, (float) $userB->ibkr_net_liquidation);
    }

    public function test_sync_skips_user_without_credentials(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);

        Http::fake();

        $summary = app(IbkrSyncService::class)->sync($user);

        $this->assertFalse($summary['success']);
        $this->assertSame(0, $summary['users']);
        $this->assertStringContainsString('no IBKR Flex credentials', (string) $summary['error']);
        Http::assertNothingSent();
    }
}
