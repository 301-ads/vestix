<?php

namespace Tests\Unit\Ibkr;

use App\Services\Ibkr\FlexStatementParser;
use RuntimeException;
use Tests\TestCase;

class FlexStatementParserTest extends TestCase
{
    public function test_parses_balances_positions_and_cash_transactions(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_usd.xml'));
        $snapshot = (new FlexStatementParser)->parse($xml);

        $this->assertSame(10634.60, $snapshot->netLiquidation);
        $this->assertSame(4200.00, $snapshot->availableFunds);
        $this->assertSame(3800.50, $snapshot->settledCash);
        $this->assertSame('USD', $snapshot->baseCurrency);
        $this->assertSame(3800.50, $snapshot->deployableCapital());
        $this->assertCount(2, $snapshot->openPositions);
        $this->assertSame('RPRX', $snapshot->openPositions[0]->symbol);
        $this->assertCount(4, $snapshot->cashTransactions);
    }

    public function test_rejects_non_usd_base_currency(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_eur.xml'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('base currency mismatch');

        (new FlexStatementParser)->parse($xml);
    }
}
