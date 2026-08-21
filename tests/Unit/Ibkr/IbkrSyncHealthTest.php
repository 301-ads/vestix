<?php

namespace Tests\Unit\Ibkr;

use App\Models\User;
use App\Services\Ibkr\IbkrSyncHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IbkrSyncHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_stale_after_configured_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'Europe/Amsterdam'));
        config(['vestix.ibkr.reader' => 'stub']);

        $user = User::factory()->create([
            'ibkr_last_success_at' => Carbon::parse('2026-07-15 11:00:00', 'Europe/Amsterdam'),
            'ibkr_data_stale' => false,
        ]);

        $health = app(IbkrSyncHealth::class);

        $this->assertTrue($health->isStale($user));
        $this->assertTrue($health->blocksAutomatedExecution($user));

        $health->refreshStaleFlag($user);
        $this->assertTrue((bool) $user->fresh()->ibkr_data_stale);

        Carbon::setTestNow();
    }

    public function test_fresh_sync_does_not_block_automation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'Europe/Amsterdam'));
        config(['vestix.ibkr.reader' => 'stub']);

        $user = User::factory()->create([
            'ibkr_last_success_at' => Carbon::parse('2026-07-17 10:00:00', 'Europe/Amsterdam'),
            'ibkr_data_stale' => false,
        ]);

        $this->assertFalse(app(IbkrSyncHealth::class)->blocksAutomatedExecution($user));

        Carbon::setTestNow();
    }

    public function test_flex_reader_blocks_automation_without_own_connection(): void
    {
        config([
            'vestix.ibkr.reader' => 'flex',
            'vestix.ibkr.block_automation_when_stale' => true,
        ]);

        $user = User::factory()->create([
            'ibkr_last_success_at' => now(),
            'ibkr_available_funds' => 5000,
            'ibkr_data_stale' => false,
        ]);

        $this->assertTrue(app(IbkrSyncHealth::class)->blocksAutomatedExecution($user));

        $user->storeIbkrFlexCredentials('token', '123');

        $this->assertFalse(app(IbkrSyncHealth::class)->blocksAutomatedExecution($user->fresh() ?? $user));
    }
}
