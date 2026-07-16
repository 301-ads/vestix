<?php

namespace Tests\Unit;

use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupGradePromotionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function perfectScoutAttributes(): array
    {
        return [
            'signal_low' => 101.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
        ];
    }

    public function test_can_promote_to_a_when_score_is_strong_and_unpromoted(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            ...$this->perfectScoutAttributes(),
            'scout_rsi' => 68.00,
        ]);

        $this->assertSame(8, $scout->evaluateSetupScore()['totalPoints']);
        $this->assertSame('B', $scout->evaluateSetupScore()['grade']);
        $this->assertTrue(PositionRecordActions::canPromoteToA($scout));
        $this->assertFalse(PositionRecordActions::canPromoteToAPlus($scout));
    }

    public function test_nine_points_is_already_a_without_manual_promotion(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            ...$this->perfectScoutAttributes(),
            'pre_bounce_extension_atr' => 1.0,
        ]);

        $this->assertSame(9, $scout->evaluateSetupScore()['totalPoints']);
        $this->assertSame('A', $scout->evaluateSetupScore()['grade']);
        $this->assertFalse(PositionRecordActions::canPromoteToA($scout));
        $this->assertFalse(PositionRecordActions::canPromoteToAPlus($scout));
    }

    public function test_perfect_score_is_already_a_and_only_offers_a_plus_promotion(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create($this->perfectScoutAttributes());

        $this->assertSame('A', $scout->evaluateSetupScore()['grade']);
        $this->assertFalse(PositionRecordActions::canPromoteToA($scout));
        $this->assertTrue(PositionRecordActions::canPromoteToAPlus($scout));
    }

    public function test_cannot_promote_to_a_when_already_a_plus_plus(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            ...$this->perfectScoutAttributes(),
            'trader_promoted_a_plus' => true,
        ]);

        $this->assertSame('A++', $scout->evaluateSetupScore()['grade']);
        $this->assertFalse(PositionRecordActions::canPromoteToA($scout));
        $this->assertFalse(PositionRecordActions::canPromoteToAPlus($scout));
    }

    public function test_promote_to_a_plus_also_sets_a_promotion_flag(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create($this->perfectScoutAttributes());

        $scout->promoteToAPlus();
        $scout->refresh();

        $this->assertTrue($scout->trader_promoted_a);
        $this->assertTrue($scout->trader_promoted_a_plus);
        $this->assertFalse(PositionRecordActions::canPromoteToA($scout));
    }
}
