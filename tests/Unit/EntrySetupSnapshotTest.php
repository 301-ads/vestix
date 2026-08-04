<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\User;
use App\Support\EntrySetupSnapshot;
use App\Services\EntrySetupSnapshotBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntrySetupSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_activate_freezes_live_grade_including_promotions_before_wipe(): void
    {
        $user = User::factory()->create();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'AAPL',
            'signal_low' => 97.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'latest_atr_14' => 2.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'trader_promoted_a_plus' => true,
            'trader_promoted_a' => true,
            'last_setup_score' => 10,
        ]);

        $scout->activateAsPosition(100.00, 10);
        $fresh = $scout->fresh();

        $this->assertSame('open', $fresh->status);
        $this->assertSame(10, $fresh->entry_setup_score);
        $this->assertSame('A++', $fresh->entry_setup_grade);
        $this->assertTrue($fresh->entry_setup_promoted_a_plus);
        $this->assertTrue($fresh->entry_setup_promoted_a);
        $this->assertNotNull($fresh->entry_setup_captured_at);
        $this->assertSame(EntrySetupSnapshot::SOURCE_LIVE, $fresh->entry_setup_source);
        // Live promotions are cleared when entry_price is set; entry snapshot keeps them.
        $this->assertFalse((bool) $fresh->trader_promoted_a_plus);
    }

    public function test_activate_does_not_overwrite_existing_entry_snapshot(): void
    {
        $user = User::factory()->create();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'MSFT',
            'signal_low' => 97.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'latest_atr_14' => 2.00,
            'scout_rsi' => 50.00,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'entry_setup_grade' => 'B',
            'entry_setup_score' => 7,
            'entry_setup_captured_at' => now()->subDay(),
            'entry_setup_source' => 'legacy_last_setup_score',
        ]);

        $scout->activateAsPosition(100.00, 5);
        $fresh = $scout->fresh();

        $this->assertSame('B', $fresh->entry_setup_grade);
        $this->assertSame(7, $fresh->entry_setup_score);
        $this->assertSame('legacy_last_setup_score', $fresh->entry_setup_source);
    }

    public function test_backfill_prefers_buy_stop_review_then_live_score_rules(): void
    {
        $user = User::factory()->create();

        $fromReview = Position::factory()->for($user)->closed()->create([
            'ticker' => 'REV',
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'buy_stop_review_setup_grade' => 'A',
            'buy_stop_review_setup_score' => 9,
            'last_setup_score' => 10,
        ]);
        $fromScore = Position::factory()->for($user)->closed()->create([
            'ticker' => 'SCORE',
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'last_setup_score' => 10,
            'trader_promoted_a_plus' => false,
        ]);
        $perfectPromoted = Position::factory()->for($user)->closed()->create([
            'ticker' => 'PLUS',
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'last_setup_score' => 10,
            'trader_promoted_a_plus' => true,
            'trader_promoted_a' => true,
        ]);

        $result = app(EntrySetupSnapshotBackfillService::class)->backfill($user->id);

        $this->assertSame(3, $result['updated']);
        $this->assertSame('A', $fromReview->fresh()->entry_setup_grade);
        $this->assertSame(EntrySetupSnapshot::SOURCE_LEGACY_BUY_STOP_REVIEW, $fromReview->fresh()->entry_setup_source);
        // Live rules: perfect score without A++ promotion → A (not auto A++).
        $this->assertSame('A', $fromScore->fresh()->entry_setup_grade);
        $this->assertSame(EntrySetupSnapshot::SOURCE_LEGACY_LAST_SETUP_SCORE, $fromScore->fresh()->entry_setup_source);
        $this->assertSame('A++', $perfectPromoted->fresh()->entry_setup_grade);
    }

    public function test_rollover_clears_buy_stop_review_but_keeps_entry_snapshot(): void
    {
        $user = User::factory()->create();

        $position = Position::factory()->for($user)->create([
            'status' => 'open',
            'entry_price' => 100,
            'quantity' => 10,
            'latest_atr_14' => 2,
            'signal_low' => 97,
            'entry_setup_grade' => 'A',
            'entry_setup_score' => 9,
            'entry_setup_captured_at' => now(),
            'entry_setup_source' => 'live',
            'buy_stop_review_required_on' => now()->toDateString(),
            'buy_stop_review_setup_grade' => 'B',
            'buy_stop_review_setup_score' => 7,
        ]);

        $position->rolloverBuyStop();
        $fresh = $position->fresh();

        $this->assertNull($fresh->buy_stop_review_setup_grade);
        $this->assertSame('A', $fresh->entry_setup_grade);
        $this->assertSame(9, $fresh->entry_setup_score);
    }
}
