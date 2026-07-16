<?php

namespace Tests\Feature\Console;

use App\Models\BankrollSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedAlphaDemoSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sets_baseline_and_seeds_weekly_snapshots(): void
    {
        $user = User::factory()->create();

        $this->artisan('vestix:seed-alpha-demo-snapshots', [
            '--user' => $user->id,
            '--weeks' => 3,
            '--baseline' => '3428.40',
            '--date' => '2026-07-16',
        ])->assertSuccessful();

        $user->refresh();

        $this->assertEquals(3428.40, (float) $user->baseline_capital);
        $this->assertSame('2026-07-16', $user->baseline_date->toDateString());
        $this->assertSame(4, BankrollSnapshot::query()->where('user_id', $user->id)->count());
        $this->assertEquals(
            3428.40,
            (float) BankrollSnapshot::query()
                ->where('user_id', $user->id)
                ->whereDate('recorded_on', '2026-07-16')
                ->value('amount'),
        );
    }
}
