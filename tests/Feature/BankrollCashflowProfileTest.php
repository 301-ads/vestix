<?php

namespace Tests\Feature;

use App\Enums\BankrollCashflowType;
use App\Filament\Pages\EditUserProfile;
use App\Models\User;
use App\Services\BankrollCashflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class BankrollCashflowProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_loads_cashflow_section(): void
    {
        $this->authenticateFilament();

        Livewire::test(EditUserProfile::class)
            ->assertSuccessful()
            ->assertSee('Kapitaalbewegingen')
            ->assertSee('Registreer storting / opname');
    }

    public function test_profile_cashflow_table_shows_source_and_actions(): void
    {
        $user = $this->authenticateFilament();

        app(BankrollCashflowService::class)->record(
            $user,
            BankrollCashflowType::Deposit,
            3428.40,
            Carbon::parse('2026-07-15'),
            'Handmatig openingsaldo',
        );

        $user->bankrollCashflows()->create([
            'type' => BankrollCashflowType::Deposit,
            'amount' => 1143.90,
            'occurred_on' => '2026-07-17',
            'note' => 'IBKR deposit',
            'source' => 'ibkr',
            'external_id' => '6328136794',
        ]);

        Livewire::test(EditUserProfile::class)
            ->assertSuccessful()
            ->assertSee('Bron')
            ->assertSee('Handmatig')
            ->assertSee('IBKR sync')
            ->assertSee('3,428.40')
            ->assertSee('1,143.90')
            ->assertSee('Wijzig')
            ->assertSee('Verwijder')
            ->assertSee('Handmatig openingsaldo', escape: false)
            ->assertSee('IBKR deposit', escape: false);
    }

    public function test_profile_can_delete_cashflow_via_table_action(): void
    {
        $user = $this->authenticateFilament();

        $flow = app(BankrollCashflowService::class)->record(
            $user,
            BankrollCashflowType::Deposit,
            1145.10,
            Carbon::parse('2026-07-17'),
        );

        Livewire::test(EditUserProfile::class)
            ->callAction('delete_cashflow', arguments: ['cashflow' => $flow->id])
            ->assertSuccessful();

        $this->assertDatabaseMissing('bankroll_cashflows', ['id' => $flow->id]);
    }

    public function test_cashflow_service_records_deposit_for_user(): void
    {
        $user = $this->authenticateFilament();

        app(BankrollCashflowService::class)->record(
            $user,
            BankrollCashflowType::Deposit,
            3000,
            Carbon::parse('2026-07-20'),
            'IBKR top-up',
        );

        $this->assertDatabaseHas('bankroll_cashflows', [
            'user_id' => $user->id,
            'type' => 'deposit',
            'amount' => '3000.00',
            'note' => 'IBKR top-up',
            'source' => 'manual',
        ]);
    }

    public function test_cashflow_service_updates_own_flow(): void
    {
        $user = $this->authenticateFilament();

        $flow = app(BankrollCashflowService::class)->record(
            $user,
            BankrollCashflowType::Deposit,
            3428.40,
            Carbon::parse('2026-07-15'),
        );

        $updated = app(BankrollCashflowService::class)->update(
            $user,
            $flow->id,
            BankrollCashflowType::Deposit,
            3500,
            Carbon::parse('2026-07-16'),
            'Gecorrigeerd openingsaldo',
        );

        $this->assertNotNull($updated);
        $this->assertEquals(3500.0, (float) $updated->amount);
        $this->assertSame('2026-07-16', $updated->occurred_on->toDateString());
        $this->assertSame('Gecorrigeerd openingsaldo', $updated->note);
    }

    public function test_profile_can_save_baseline_date(): void
    {
        $user = $this->authenticateFilament();
        $user->forceFill(['default_risk_percent' => 1])->save();

        Livewire::test(EditUserProfile::class)
            ->fillForm([
                'baseline_date' => '2026-07-16',
                'default_risk_percent' => '1',
                'primary_broker' => 'ibkr',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-07-16', $user->fresh()->baseline_date?->toDateString());
    }

    public function test_cashflow_service_deletes_own_flow_only(): void
    {
        $user = $this->authenticateFilament();
        $other = User::factory()->create();

        $own = app(BankrollCashflowService::class)->record(
            $user,
            BankrollCashflowType::Deposit,
            100,
            Carbon::parse('2026-07-20'),
        );
        $foreign = app(BankrollCashflowService::class)->record(
            $other,
            BankrollCashflowType::Deposit,
            200,
            Carbon::parse('2026-07-20'),
        );

        $this->assertTrue(app(BankrollCashflowService::class)->deleteForUser($user, $own->id));
        $this->assertFalse(app(BankrollCashflowService::class)->deleteForUser($user, $foreign->id));
        $this->assertDatabaseMissing('bankroll_cashflows', ['id' => $own->id]);
        $this->assertDatabaseHas('bankroll_cashflows', ['id' => $foreign->id]);
    }
}
