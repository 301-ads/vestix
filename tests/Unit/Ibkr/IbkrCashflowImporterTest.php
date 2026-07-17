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

        $this->assertSame(2, $first['imported']);
        $this->assertSame(2, $first['skipped']);
        $this->assertSame(0, $second['imported']);

        $flows = $user->bankrollCashflows()->orderBy('occurred_on')->get();
        $this->assertCount(2, $flows);
        $this->assertSame(BankrollCashflowType::Withdrawal, $flows[0]->type);
        $this->assertEquals(500.0, (float) $flows[0]->amount);
        $this->assertSame('ibkr', $flows[0]->source);
        $this->assertSame('TX-WDR-001', $flows[0]->external_id);
        $this->assertSame(BankrollCashflowType::Deposit, $flows[1]->type);
        $this->assertEquals(3428.40, (float) $flows[1]->amount);
        $this->assertDatabaseMissing('bankroll_cashflows', [
            'external_id' => 'TX-DIV-001',
        ]);
    }
}
