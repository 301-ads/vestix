<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\User;
use App\Support\OpenPositionsSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenPositionsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        OpenPositionsSnapshot::flush();

        parent::tearDown();
    }

    public function test_for_user_memoizes_within_request(): void
    {
        $user = User::factory()->create();
        Position::factory()->create(['user_id' => $user->id]);

        $first = OpenPositionsSnapshot::forUser($user->id);
        $second = OpenPositionsSnapshot::forUser($user->id);

        $this->assertSame($first, $second);
        $this->assertCount(1, $first);
    }
}
