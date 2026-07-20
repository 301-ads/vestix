<?php

namespace Tests\Unit\Ibkr;

use App\Services\Ibkr\FlexWebServiceClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FlexWebServiceClientTest extends TestCase
{
    public function test_fetches_statement_via_send_and_get(): void
    {
        config([
            'vestix.ibkr.flex.token' => 'token',
            'vestix.ibkr.flex.query_id' => '123',
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.flex.poll_delay_ms' => 1,
        ]);

        $statement = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_usd.xml'));

        Http::fake([
            'https://flex.test/AccountManagement/FlexWebService/SendRequest*' => Http::response(
                '<?xml version="1.0"?><FlexStatementResponse><Status>Success</Status><ReferenceCode>999</ReferenceCode></FlexStatementResponse>',
                200,
            ),
            'https://flex.test/AccountManagement/FlexWebService/GetStatement*' => Http::response($statement, 200),
        ]);

        $xml = app(FlexWebServiceClient::class)->fetchStatementXml();

        $this->assertStringContainsString('EquitySummaryInBase', $xml);
        Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'Vestix/1.0'));
    }

    public function test_supports_legacy_universal_servlet_base_url(): void
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

    public function test_send_request_retries_transient_1001_error(): void
    {
        config([
            'vestix.ibkr.flex.token' => 'token',
            'vestix.ibkr.flex.query_id' => '123',
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.flex.send_request_attempts' => 3,
            'vestix.ibkr.flex.poll_delay_ms' => 1,
        ]);

        $statement = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_usd.xml'));
        $attempt = 0;

        Http::fake([
            'https://flex.test/AccountManagement/FlexWebService/SendRequest*' => function () use (&$attempt) {
                $attempt++;

                if ($attempt < 3) {
                    return Http::response(
                        '<?xml version="1.0"?><FlexStatementResponse><Status>Fail</Status><ErrorCode>1001</ErrorCode><ErrorMessage>Statement could not be generated at this time. Please try again shortly.</ErrorMessage></FlexStatementResponse>',
                        200,
                    );
                }

                return Http::response(
                    '<?xml version="1.0"?><FlexStatementResponse><Status>Success</Status><ReferenceCode>999</ReferenceCode></FlexStatementResponse>',
                    200,
                );
            },
            'https://flex.test/AccountManagement/FlexWebService/GetStatement*' => Http::response($statement, 200),
        ]);

        $xml = app(FlexWebServiceClient::class)->fetchStatementXml();

        $this->assertStringContainsString('EquitySummaryInBase', $xml);
        $this->assertSame(3, $attempt);
    }

    public function test_send_request_does_not_retry_on_rate_limit_1025(): void
    {
        config([
            'vestix.ibkr.flex.token' => 'token',
            'vestix.ibkr.flex.query_id' => '123',
            'vestix.ibkr.flex.base_url' => 'https://flex.test/AccountManagement/FlexWebService',
            'vestix.ibkr.flex.send_request_attempts' => 5,
            'vestix.ibkr.flex.poll_delay_ms' => 1,
        ]);

        $attempt = 0;

        Http::fake([
            'https://flex.test/AccountManagement/FlexWebService/SendRequest*' => function () use (&$attempt) {
                $attempt++;

                return Http::response(
                    '<?xml version="1.0"?><FlexStatementResponse><Status>Fail</Status><ErrorCode>1025</ErrorCode><ErrorMessage>Too many failed attempts. Please review your configuration.</ErrorMessage></FlexStatementResponse>',
                    200,
                );
            },
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('1025');

        try {
            app(FlexWebServiceClient::class)->fetchStatementXml();
        } finally {
            $this->assertSame(1, $attempt);
        }
    }
}
