<?php

namespace Tests\Feature\Console;

use App\Enums\Broker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncIbkrAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_flag_prints_flex_statement_and_cashflow_reasons(): void
    {
        config([
            'vestix.ibkr.flex.token' => 'token',
            'vestix.ibkr.flex.query_id' => '123',
            'vestix.ibkr.flex.base_url' => 'https://flex.test/Universal/servlet',
            'vestix.ibkr.flex.poll_delay_ms' => 1,
            'vestix.ibkr.client_portal.enabled' => false,
            'vestix.ibkr.sync_bankroll_snapshot' => false,
        ]);

        User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'trading_bankroll' => 1000,
        ]);

        $statement = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_usd.xml'));

        Http::fake([
            'https://flex.test/Universal/servlet/FlexStatementService.SendRequest*' => Http::response(
                '<?xml version="1.0"?><FlexStatementResponse><Status>Success</Status><ReferenceCode>999</ReferenceCode></FlexStatementResponse>',
                200,
            ),
            'https://flex.test/Universal/servlet/FlexStatementService.GetStatement*' => Http::response($statement, 200),
        ]);

        $this->artisan('vestix:sync-ibkr', ['--details' => true])
            ->expectsOutputToContain('Flex statement')
            ->expectsOutputToContain('Net Liquidation')
            ->expectsOutputToContain('Cashflow classification')
            ->expectsOutputToContain('fx_conversion')
            ->expectsOutputToContain('imported')
            ->assertSuccessful();
    }
}
