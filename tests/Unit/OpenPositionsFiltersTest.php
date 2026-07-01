<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Support\OpenPositionsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenPositionsFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_danger_zone_when_buffer_below_two_percent(): void
    {
        $position = Position::factory()->create([
            'status' => 'open',
            'latest_close_price' => 100.00,
            'current_sl' => 99.00,
        ]);

        $this->assertTrue(OpenPositionsFilters::matches($position, 'danger_zone'));
    }

    public function test_does_not_match_danger_zone_when_buffer_above_two_percent(): void
    {
        $position = Position::factory()->create([
            'status' => 'open',
            'latest_close_price' => 100.00,
            'current_sl' => 97.00,
        ]);

        $this->assertFalse(OpenPositionsFilters::matches($position, 'danger_zone'));
    }

    public function test_apply_danger_zone_filter_uses_scope(): void
    {
        $user = $this->authenticateFilament();

        $danger = Position::factory()->for($user)->create([
            'ticker' => 'DANGER',
            'status' => 'open',
            'latest_close_price' => 100.00,
            'current_sl' => 99.00,
        ]);

        $safe = Position::factory()->for($user)->create([
            'ticker' => 'SAFE',
            'status' => 'open',
            'latest_close_price' => 100.00,
            'current_sl' => 95.00,
        ]);

        $ids = OpenPositionsFilters::apply(
            Position::query()->open()->forUser($user->id),
            'danger_zone',
        )->pluck('id')->all();

        $this->assertSame([$danger->id], $ids);
        $this->assertNotContains($safe->id, $ids);
    }

    public function test_matches_winners_and_losers(): void
    {
        $winner = Position::factory()->create([
            'status' => 'open',
            'entry_price' => 100.00,
            'quantity' => 10,
            'latest_close_price' => 110.00,
        ]);

        $loser = Position::factory()->create([
            'status' => 'open',
            'entry_price' => 100.00,
            'quantity' => 10,
            'latest_close_price' => 90.00,
        ]);

        $this->assertTrue(OpenPositionsFilters::matches($winner, 'winners'));
        $this->assertFalse(OpenPositionsFilters::matches($winner, 'losers'));
        $this->assertTrue(OpenPositionsFilters::matches($loser, 'losers'));
        $this->assertFalse(OpenPositionsFilters::matches($loser, 'winners'));
    }

    public function test_matches_secured_profit_when_stop_loss_above_entry(): void
    {
        $secured = Position::factory()->create([
            'status' => 'open',
            'entry_price' => 100.00,
            'quantity' => 10,
            'current_sl' => 105.00,
        ]);

        $notSecured = Position::factory()->create([
            'status' => 'open',
            'entry_price' => 100.00,
            'quantity' => 10,
            'current_sl' => 95.00,
        ]);

        $this->assertTrue(OpenPositionsFilters::matches($secured, 'secured_profit'));
        $this->assertFalse(OpenPositionsFilters::matches($notSecured, 'secured_profit'));
    }
}
