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

        $user = User::factory()->create([
            'ibkr_last_success_at' => Carbon::parse('2026-07-17 10:00:00', 'Europe/Amsterdam'),
            'ibkr_data_stale' => false,
        ]);

        $this->assertFalse(app(IbkrSyncHealth::class)->blocksAutomatedExecution($user));

        Carbon::setTestNow();
    }
}
