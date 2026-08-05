<?php

namespace Tests\Unit;

use App\Enums\AutopsyTag;
use App\Models\Position;
use App\Models\User;
use App\Services\StrategyAnalyticsService;
use App\Support\AutopsyPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutopsyAndDisciplineTest extends TestCase
{
    use RefreshDatabase;

    public function test_golden_badge_for_flawless_loss(): void
    {
        $position = Position::factory()->create([
            'status' => 'closed',
            'entry_price' => 100,
            'exit_price' => 95,
            'quantity' => 10,
            'autopsy_tag' => AutopsyTag::FlawlessExecution,
        ]);

        $this->assertTrue(AutopsyPresentation::isGoldenBadge($position));
        $this->assertSame('Operatie Geslaagd', AutopsyPresentation::badgeLabel($position));
    }

    public function test_luck_shot_for_error_tag_win(): void
    {
        $position = Position::factory()->create([
            'status' => 'closed',
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'autopsy_tag' => AutopsyTag::MicroManagement,
        ]);

        $this->assertTrue(AutopsyPresentation::isLuckShot($position));
        $this->assertSame('Geluksschot', AutopsyPresentation::badgeLabel($position));
    }

    public function test_discipline_score_counts_flawless_share(): void
    {
        $user = User::factory()->create();

        Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'closed',
            'closed_at' => now()->subDay(),
            'is_legacy' => false,
            'autopsy_tag' => AutopsyTag::FlawlessExecution,
            'entry_price' => 100,
            'exit_price' => 90,
            'quantity' => 1,
        ]);
        Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'closed',
            'closed_at' => now()->subDay(),
            'is_legacy' => false,
            'autopsy_tag' => AutopsyTag::QuarantineBreach,
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 1,
        ]);

        $score = app(StrategyAnalyticsService::class)->disciplineScore($user->id);

        $this->assertSame(50.0, $score['score']);
        $this->assertSame(1, $score['flawless']);
        $this->assertSame(2, $score['tagged']);
    }
}
