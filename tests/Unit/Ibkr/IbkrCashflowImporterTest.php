<?php

namespace Tests\Unit\Ibkr;

use App\Enums\BankrollCashflowType;
use App\Enums\Broker;
use App\Models\User;
use App\Services\Ibkr\FlexStatementParser;
use App\Services\Ibkr\IbkrCashflowImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IbkrCashflowImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_only_external_transfers_idempotently(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $xml = file_get_contents(base_path('tests/Fixtures/ibkr/flex_statement_usd.xml'));
        $snapshot = (new FlexStatementParser)->parse($xml);
        $importer = app(IbkrCashflowImporter::class);

        $first = $importer->import($user, $snapshot);
        $second = $importer->import($user, $snapshot);

        // USD deposit + USD withdrawal + EUR deposit (converted) = 3
        // dividend, interest, FX conversion skipped
        $this->assertSame(3, $first->imported);
        $this->assertSame(3, $first->skipped);
        $this->assertSame(0, $second->imported);
        $this->assertSame(6, $second->skipped);

        $flows = $user->bankrollCashflows()->orderBy('occurred_on')->orderBy('id')->get();
        $this->assertCount(3, $flows);
        $this->assertSame(BankrollCashflowType::Withdrawal, $flows[0]->type);
        $this->assertEquals(500.0, (float) $flows[0]->amount);
        $this->assertSame('ibkr', $flows[0]->source);
        $this->assertSame('TX-WDR-001', $flows[0]->external_id);
        $this->assertSame(BankrollCashflowType::Deposit, $flows[1]->type);
        $this->assertEquals(3428.40, (float) $flows[1]->amount);
        $this->assertSame(BankrollCashflowType::Deposit, $flows[2]->type);
        $this->assertEquals(2287.30, (float) $flows[2]->amount);
        $this->assertSame('TX-EUR-DEP-001', $flows[2]->external_id);
        $this->assertStringContainsString('2000.00 EUR → 2287.30 USD', (string) $flows[2]->note);
        $this->assertDatabaseMissing('bankroll_cashflows', [
            'external_id' => 'TX-DIV-001',
        ]);
        $this->assertDatabaseMissing('bankroll_cashflows', [
            'external_id' => 'TX-FX-001',
        ]);
    }

    public function test_skips_eur_deposit_without_fx_rate(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FlexQueryResponse queryName="Vestix" type="AF">
    <FlexStatements count="1">
        <FlexStatement accountId="U1" fromDate="20260701" toDate="20260720" period="Last30CalendarDays" whenGenerated="20260720;113000">
            <AccountInformation accountId="U1" currency="USD" currencyPrimary="USD" name="Test"/>
            <EquitySummaryInBase accountId="U1" currency="USD" total="5000" availableFunds="5000" endingValue="5000"/>
            <CashReport>
                <CashReportCurrency accountId="U1" currency="BASE" levelOfDetail="BaseCurrency" endingCash="5000" endingSettledCash="5000"/>
            </CashReport>
            <CashTransactions>
                <CashTransaction accountId="U1" transactionID="TX-EUR-NO-FX" type="Deposits &amp; Withdrawals" amount="1000.00" currency="EUR" reportDate="20260720" description="Electronic Fund Transfer"/>
            </CashTransactions>
        </FlexStatement>
    </FlexStatements>
</FlexQueryResponse>
XML;

        $snapshot = (new FlexStatementParser)->parse($xml);
        $result = app(IbkrCashflowImporter::class)->import($user, $snapshot);

        $this->assertSame(0, $result->imported);
        $this->assertSame(1, $result->skipped);
        $this->assertSame('missing_fx_rate_to_base', $result->details[0]['reason']);
        $this->assertSame(0, $user->bankrollCashflows()->count());
    }
}
