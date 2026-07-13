<?php

namespace Tests\Unit;

use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Asset;
use App\Models\Position;
use App\Models\StrategyTag;
use App\Models\User;
use App\Support\FreerideDetector;
use App\Support\ShareCardDataFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
        $position = Position::factory()->for($user)->create([
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
        $this->assertStringContainsString('ASML', $card['share_text']);
        $this->assertStringNotContainsString('500', (string) $encoded);
        $this->assertArrayNotHasKey('quantity', $card);
        $this->assertIsInt($card['ticker_hue']);
    }

    public function test_share_card_factory_embeds_local_ticker_icon(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $asset = Asset::factory()->create([
            'ticker' => 'NVDA',
            'icon_path' => 'ticker-logos/NVDA.png',
        ]);
        Storage::disk('public')->put($asset->icon_path, 'fake-png');

        $position = Position::factory()->for($user)->create([
            'ticker' => 'NVDA',
            'asset_id' => $asset->id,
            'status' => 'open',
            'entry_price' => 100,
            'current_sl' => 110,
            'latest_close_price' => 150,
        ]);

        $card = ShareCardDataFactory::fromPosition($position);

        $this->assertNotNull($card['ticker_icon_url']);
        $this->assertStringStartsWith('data:image/png;base64,', $card['ticker_icon_url']);
    }

    public function test_share_card_factory_builds_scout_setup_card(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'status' => 'scout',
            'ticker' => 'NVDA',
            'signal_low' => 101.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
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
            'trader_promoted_a_plus' => true,
            'entry_price' => 102.50,
            'latest_atr_14' => 5,
            'quantity' => 25,
        ]);

        $this->assertTrue(PositionRecordActions::canShareScout($position));

        $card = ShareCardDataFactory::fromScout($position);

        $this->assertSame('10/10', $card['score_formatted']);
        $this->assertSame('A++ SETUP', $card['grade_label']);
        $this->assertSame('$101.00', $card['close_price']);
        $this->assertSame('$100.00', $card['sma_20']);
        $this->assertSame('$97.50', $card['stop_loss']);
        $this->assertStringContainsString('NVDA A++ SETUP · 10/10', $card['share_text']);
        $this->assertSame('vestix-NVDA-setup.png', $card['share_filename']);
        $this->assertArrayNotHasKey('quantity', $card);
    }

    public function test_cannot_share_non_a_plus_scout(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'status' => 'scout',
            'ticker' => 'NVDA',
            'signal_low' => 99.90,
            'latest_close_price' => 99.90,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
        ]);

        $this->assertFalse(PositionRecordActions::canShareScout($position));
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
