<?php

namespace Tests\Feature\Console;

use App\Models\BankrollCashflow;
use App\Models\BankrollSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MigrateIbkrFlexOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_owner_email_or_user(): void
    {
        $this->artisan('vestix:migrate-ibkr-flex-owner')
            ->expectsOutputToContain('--email=')
            ->assertFailed();
    }

    public function test_dry_run_reports_without_writing(): void
    {
        config([
            'vestix.ibkr.flex.token' => 'secret-token',
            'vestix.ibkr.flex.query_id' => '1575288',
        ]);

        $owner = User::factory()->create([
            'email' => 'owner@vestix.test',
            'ibkr_net_liquidation' => 10634.60,
            'ibkr_last_success_at' => now(),
        ]);

        $other = User::factory()->create([
            'email' => 'other@vestix.test',
            'ibkr_net_liquidation' => 10634.60,
            'ibkr_last_success_at' => now(),
            'trading_bankroll' => 10634.60,
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $owner->id,
            'amount' => 10634.60,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-08-01',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $other->id,
            'amount' => 10634.60,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-08-01',
            'recorded_at' => now(),
        ]);

        $this->artisan('vestix:migrate-ibkr-flex-owner', [
            '--email' => 'owner@vestix.test',
        ])
            ->expectsOutputToContain('Dry-run')
            ->expectsOutputToContain('Would attach')
            ->assertSuccessful();

        $this->assertFalse($owner->fresh()->hasIbkrFlexConnection());
        $this->assertDatabaseHas('bankroll_snapshots', ['user_id' => $other->id]);
    }

    public function test_execute_attaches_credentials_and_clean_others_preserves_owner(): void
    {
        config([
            'vestix.ibkr.flex.token' => 'secret-token',
            'vestix.ibkr.flex.query_id' => '1575288',
        ]);

        $owner = User::factory()->create([
            'email' => 'owner@vestix.test',
            'ibkr_net_liquidation' => 10634.60,
            'ibkr_available_funds' => 4200,
            'ibkr_settled_cash' => 3800,
            'ibkr_last_success_at' => Carbon::parse('2026-08-04 08:00:00'),
            'trading_bankroll' => 10634.60,
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $owner->id,
            'amount' => 10634.60,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-08-01',
            'recorded_at' => now(),
        ]);

        BankrollCashflow::query()->create([
            'user_id' => $owner->id,
            'type' => 'deposit',
            'amount' => 1000,
            'occurred_on' => '2026-07-01',
            'source' => 'ibkr',
            'external_id' => 'OWNER-TX-1',
        ]);

        $other = User::factory()->create([
            'email' => 'other@vestix.test',
            'ibkr_net_liquidation' => 10634.60,
            'ibkr_available_funds' => 4200,
            'ibkr_settled_cash' => 3800,
            'ibkr_last_success_at' => Carbon::parse('2026-08-04 08:00:00'),
            'trading_bankroll' => 10634.60,
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $other->id,
            'amount' => 10634.60,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-08-01',
            'recorded_at' => now(),
        ]);

        BankrollCashflow::query()->create([
            'user_id' => $other->id,
            'type' => 'deposit',
            'amount' => 1000,
            'occurred_on' => '2026-07-01',
            'source' => 'ibkr',
            'external_id' => 'OTHER-TX-1',
        ]);

        BankrollCashflow::query()->create([
            'user_id' => $other->id,
            'type' => 'deposit',
            'amount' => 50,
            'occurred_on' => '2026-07-02',
            'source' => 'manual',
            'external_id' => null,
        ]);

        $this->artisan('vestix:migrate-ibkr-flex-owner', [
            '--email' => 'owner@vestix.test',
            '--execute' => true,
            '--clean-others' => true,
        ])->assertSuccessful();

        $owner->refresh();
        $other->refresh();

        $this->assertTrue($owner->hasIbkrFlexConnection());
        $this->assertSame([
            'token' => 'secret-token',
            'query_id' => '1575288',
        ], $owner->ibkrFlexCredentials());

        $this->assertEquals(10634.60, (float) $owner->ibkr_net_liquidation);
        $this->assertEquals(10634.60, (float) $owner->trading_bankroll);
        $this->assertDatabaseHas('bankroll_snapshots', ['user_id' => $owner->id, 'amount' => 10634.60]);
        $this->assertDatabaseHas('bankroll_cashflows', ['user_id' => $owner->id, 'external_id' => 'OWNER-TX-1']);

        $this->assertNull($other->ibkr_net_liquidation);
        $this->assertNull($other->ibkr_available_funds);
        $this->assertNull($other->ibkr_settled_cash);
        $this->assertNull($other->ibkr_last_success_at);
        $this->assertNull($other->trading_bankroll);
        $this->assertDatabaseMissing('bankroll_snapshots', ['user_id' => $other->id]);
        $this->assertDatabaseMissing('bankroll_cashflows', ['user_id' => $other->id, 'source' => 'ibkr']);
        $this->assertDatabaseHas('bankroll_cashflows', ['user_id' => $other->id, 'source' => 'manual', 'amount' => 50]);
    }
}
