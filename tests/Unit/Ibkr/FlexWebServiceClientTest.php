<?php

namespace Tests\Unit\Ibkr;

use App\Services\Ibkr\FlexWebServiceClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlexWebServiceClientTest extends TestCase
{
    public function test_fetches_statement_via_send_and_get(): void
    {
        config([
            'vestix.ibkr.flex.token' => 'token',
            'vestix.ibkr.flex.query_id' => '123',
            'vestix.ibkr.flex.base_url' => 'https://flex.test/Universal/servlet',
            'vestix.ibkr.flex.poll_delay_ms' => 1,
        ]);

        $statement = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_usd.xml'));

        Http::fake([
            'https://flex.test/Universal/servlet/FlexStatementService.SendRequest*' => Http::response(
                '<?xml version="1.0"?><FlexStatementResponse><Status>Success</Status><ReferenceCode>999</ReferenceCode></FlexStatementResponse>',
                200,
            ),
            'https://flex.test/Universal/servlet/FlexStatementService.GetStatement*' => Http::response($statement, 200),
        ]);

        $xml = app(FlexWebServiceClient::class)->fetchStatementXml();

        $this->assertStringContainsString('EquitySummaryInBase', $xml);
    }
}
