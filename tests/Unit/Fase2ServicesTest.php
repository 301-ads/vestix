<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\StrategyTag;
use App\Models\User;
use App\Support\FreerideDetector;
use App\Support\ShareCardDataFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Fase2ServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_freeride_detector_sets_timestamp_once(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'entry_price' => 100,
            'current_sl' => 95,
            'quantity' => 10,
            'latest_sma_20' => 110,
            'latest_atr_14' => 5,
        ]);

        $detector = app(FreerideDetector::class);
        $this->assertFalse($detector->evaluate($position->fresh()));

        $position->updateQuietly(['current_sl' => 105]);
        $this->assertTrue($detector->evaluate($position->fresh()));
        $this->assertNotNull($position->fresh()->freeride_secured_at);

        $this->assertFalse($detector->evaluate($position->fresh()));
    }

    public function test_share_card_factory_excludes_quantity(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'ticker' => 'ASML',
            'entry_price' => 100,
            'current_sl' => 110,
            'quantity' => 500,
            'latest_close_price' => 150,
        ]);

        $card = ShareCardDataFactory::fromPosition($position);
        $encoded = json_encode($card);

        $this->assertStringContainsString('ASML', $card['ticker']);
        $this->assertStringNotContainsString('500', (string) $encoded);
        $this->assertArrayNotHasKey('quantity', $card);
    }

    public function test_risk_reward_ratio_computed_on_archive(): void
    {
        $user = User::factory()->create();
        $tag = StrategyTag::query()->first();
        $position = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'entry_price' => 100,
            'initial_sl' => 90,
            'current_sl' => 90,
            'quantity' => 10,
            'strategy_tag_id' => $tag?->id,
        ]);

        $position->archiveWithExitPrice(120);

        $this->assertEqualsWithDelta(2.0, (float) $position->fresh()->risk_reward_ratio, 0.01);
    }
}
