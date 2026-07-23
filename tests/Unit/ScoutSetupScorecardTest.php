<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\User;
use App\Support\ScoutSetupScorecard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScoutSetupScorecardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseInputs(array $overrides = []): array
    {
        return array_merge([
            'signal_low' => 100.50,
            'latest_open_price' => 100.00,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_etf' => 'XLF',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
        ], $overrides);
    }

    public function test_perfect_setup_scores_ten(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 101.00,
            'latest_close_price' => 101.00,
        ]));

        $this->assertSame(10, $result['totalPoints']);
        $this->assertSame(10, $result['maxPoints']);
        $this->assertSame('A', $result['grade']);
        $this->assertSame('A SETUP', $result['gradeLabel']);
        $this->assertSame([], $result['hardFailReasons']);
    }

    public function test_nine_points_is_automatic_a_setup(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 101.00,
            'latest_close_price' => 101.00,
            'pre_bounce_extension_atr' => 1.0,
        ]));

        $this->assertSame(9, $result['totalPoints']);
        $this->assertSame('A', $result['grade']);
        $this->assertSame('A SETUP', $result['gradeLabel']);
    }

    public function test_close_below_sma_scores_zero_trampoline_and_hard_fail(): void
    {
        // 1% under SMA — outside near-miss band (0.25%).
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 99.00,
            'latest_open_price' => 99.50,
            'latest_close_price' => 99.00,
        ]));

        $this->assertSame(0, $result['criteria'][0]['points']);
        $this->assertStringContainsString('trampoline gebroken', $result['criteria'][0]['detail']);
        $this->assertSame('NO TRADE', $result['grade']);
        $this->assertContains('Close onder SMA 20 — trampoline gebroken', $result['hardFailReasons']);
    }

    public function test_green_near_miss_below_sma_gets_benefit_of_the_doubt(): void
    {
        // LLY-achtig: ~0.18% onder SMA op groene kaars.
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 1160.00,
            'latest_open_price' => 1165.00,
            'latest_close_price' => 1169.17,
            'latest_sma_20' => 1171.25,
        ]));

        $this->assertSame(2, $result['criteria'][0]['points']);
        $this->assertSame('pass', $result['criteria'][0]['status']);
        $this->assertStringContainsString('Voordeel van de twijfel', $result['criteria'][0]['detail']);
        $this->assertSame([], $result['hardFailReasons']);
        $this->assertSame(10, $result['totalPoints']);
        $this->assertSame('A', $result['grade']);
    }

    public function test_deep_miss_below_sma_still_hard_fails(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 99.00,
            'latest_open_price' => 99.20,
            'latest_close_price' => 99.50,
            'latest_sma_20' => 100.00,
        ]));

        $this->assertSame(0, $result['criteria'][0]['points']);
        $this->assertContains('Close onder SMA 20 — trampoline gebroken', $result['hardFailReasons']);
        $this->assertSame('NO TRADE', $result['grade']);
    }

    public function test_red_candle_near_miss_does_not_get_benefit_of_the_doubt(): void
    {
        // 0.18% onder SMA maar rode kaars — geen near-miss.
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 1160.00,
            'latest_open_price' => 1172.00,
            'latest_close_price' => 1169.17,
            'latest_sma_20' => 1171.25,
        ]));

        $this->assertSame(0, $result['criteria'][0]['points']);
        $this->assertContains('Close onder SMA 20 — trampoline gebroken', $result['hardFailReasons']);
        $this->assertSame('NO TRADE', $result['grade']);
    }

    public function test_low_below_sma_but_close_above_is_not_hard_fail(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 99.50,
            'latest_close_price' => 100.50,
        ]));

        $this->assertSame([], $result['hardFailReasons']);
        $this->assertSame(2, $result['criteria'][0]['points']);
        $this->assertStringContainsString('Rejection bounce', $result['criteria'][0]['detail']);
    }

    public function test_buy_stop_entry_does_not_affect_trampoline_score(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 66.65,
            'latest_close_price' => 66.65,
            'latest_sma_20' => 66.32,
            'entry_price' => 68.19,
        ]));

        $this->assertSame(2, $result['criteria'][0]['points']);
        $this->assertStringContainsString('perfecte landing', $result['criteria'][0]['detail']);
    }

    public function test_trampoline_falls_back_to_close_when_signal_low_missing(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => null,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
        ]));

        $this->assertSame(2, $result['criteria'][0]['points']);
        $this->assertStringContainsString('perfecte landing', $result['criteria'][0]['detail']);
    }

    public function test_hard_fail_uses_close_not_signal_low(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 99.90,
            'latest_close_price' => 101.00,
            'entry_price' => 105.00,
        ]));

        $this->assertSame([], $result['hardFailReasons']);
        $this->assertSame(2, $result['criteria'][0]['points']);
        $this->assertStringContainsString('Rejection bounce', $result['criteria'][0]['detail']);
    }

    public function test_rejection_bounce_scores_full_when_close_within_one_point_five_percent(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 99.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
        ]));

        $this->assertSame(2, $result['criteria'][0]['points']);
        $this->assertStringContainsString('Rejection bounce', $result['criteria'][0]['detail']);
        $this->assertSame([], $result['hardFailReasons']);
    }

    public function test_rejection_bounce_scores_one_point_when_close_between_one_point_five_and_three_percent(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 99.50,
            'latest_close_price' => 102.00,
            'latest_sma_20' => 100.00,
        ]));

        $this->assertSame(1, $result['criteria'][0]['points']);
        $this->assertStringContainsString('Rejection bounce', $result['criteria'][0]['detail']);
        $this->assertSame([], $result['hardFailReasons']);
    }

    public function test_close_missing_does_not_hard_fail_when_low_below_sma(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 99.00,
            'latest_close_price' => null,
        ]));

        $this->assertSame([], $result['hardFailReasons']);
        $this->assertSame(0, $result['criteria'][0]['points']);
        $this->assertStringContainsString('Wacht op slotkoers', $result['criteria'][0]['detail']);
    }

    public function test_positive_sma_slope_over_ten_days_scores_two_points(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'sma_20_ten_days_ago' => 97.00,
            'bounce_volume_above_average' => false,
            'relative_volume' => null,
        ]));

        $this->assertSame(2, $result['criteria'][1]['points']);
        $this->assertStringContainsString('Stijgende trend', $result['criteria'][1]['detail']);
        $this->assertStringContainsString('over 10d', $result['criteria'][1]['detail']);
    }

    public function test_declining_sma_over_ten_days_scores_zero(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'latest_sma_20' => 99.00,
            'sma_20_ten_days_ago' => 100.00,
            'bounce_volume_above_average' => false,
            'relative_volume' => null,
        ]));

        $this->assertSame(0, $result['criteria'][1]['points']);
        $this->assertStringContainsString('Dalende SMA over 10 dagen', $result['criteria'][1]['detail']);
    }

    public function test_eog_scenario_fails_when_sma20_below_sma50(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'latest_sma_20' => 102.00,
            'sma_20_five_days_ago' => 100.00,
            'sma_20_ten_days_ago' => 101.00,
            'latest_sma_50' => 105.00,
            'bounce_volume_above_average' => false,
            'relative_volume' => null,
        ]));

        $this->assertSame(0, $result['criteria'][1]['points']);
        $this->assertStringContainsString('SMA 20 onder SMA 50', $result['criteria'][1]['detail']);
    }

    public function test_fake_trend_fails_when_rising_vs_yesterday_but_declining_vs_ten_days(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'latest_sma_20' => 100.50,
            'sma_20_ten_days_ago' => 101.00,
            'bounce_volume_above_average' => false,
            'relative_volume' => null,
        ]));

        $this->assertSame(0, $result['criteria'][1]['points']);
        $this->assertStringContainsString('Dalende SMA over 10 dagen', $result['criteria'][1]['detail']);
    }

    public function test_rsi_above_seventy_forces_no_trade_despite_high_score(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'scout_rsi' => 76.00,
        ]));

        $this->assertSame('NO TRADE', $result['grade']);
        $this->assertContains('RSI oververhit (>70) — geen A-setup mogelijk', $result['hardFailReasons']);
    }

    public function test_rsi_sixty_eight_scores_zero_rsi_points(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'scout_rsi' => 68.00,
        ]));

        $this->assertSame(0, $result['criteria'][2]['points']);
        $this->assertSame(8, $result['totalPoints']);
        $this->assertSame('B', $result['grade']);
    }

    public function test_sector_sync_scores_two_when_trend_positive(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs());

        $this->assertSame(2, $result['criteria'][4]['points']);
        $this->assertStringContainsString('Windkracht', $result['criteria'][4]['detail']);
    }

    public function test_sector_sync_scores_zero_when_trend_negative(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'sector_trend_positive' => false,
        ]));

        $this->assertSame(0, $result['criteria'][4]['points']);
        $this->assertStringContainsString('Tegenwind', $result['criteria'][4]['detail']);
    }

    public function test_extension_scores_one_when_above_threshold(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'pre_bounce_extension_atr' => 3.0,
        ]));

        $this->assertSame(1, $result['criteria'][5]['points']);
        $this->assertStringContainsString('hoge veer-potentie', $result['criteria'][5]['detail']);
    }

    #[DataProvider('trampolineDistanceProvider')]
    public function test_trampoline_distance_thresholds(float $landing, int $expectedPoints): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'signal_low' => $landing,
            'latest_open_price' => $landing,
            'latest_close_price' => $landing,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => null,
            'bounce_volume_above_average' => false,
            'relative_volume' => null,
            'sector_etf' => null,
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => null,
        ]);

        $this->assertSame($expectedPoints, $result['criteria'][0]['points']);
    }

    public static function trampolineDistanceProvider(): array
    {
        return [
            'perfect landing 1.5%' => [101.50, 2],
            'suboptimal 2%' => [102.00, 1],
            'too far 3.1%' => [103.10, 0],
        ];
    }

    #[DataProvider('rsiThresholdProvider')]
    public function test_rsi_thresholds(float $rsi, int $expectedPoints): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'scout_rsi' => $rsi,
            'bounce_volume_above_average' => false,
            'relative_volume' => null,
        ]));

        $this->assertSame($expectedPoints, $result['criteria'][2]['points']);
    }

    public static function rsiThresholdProvider(): array
    {
        return [
            'sweet spot low' => [40.0, 2],
            'sweet spot mid' => [50.0, 2],
            'momentum zone' => [60.0, 1],
            'too hot for points' => [68.0, 0],
            'too weak' => [39.0, 0],
        ];
    }

    public function test_volume_score_green_candle_with_rvol(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.45,
            'latest_open_price' => 100.00,
            'latest_close_price' => 102.00,
        ]));

        $this->assertSame(1, $result['criteria'][3]['points']);
        $this->assertSame('pass', $result['criteria'][3]['status']);
        $this->assertStringContainsString('geen institutionele dump', $result['criteria'][3]['detail']);
    }

    public function test_volume_score_green_candle_without_rvol_scores_zero(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 101.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'bounce_volume_above_average' => false,
            'relative_volume' => null,
            'bounce_day_volume' => null,
            'volume_sma_20' => null,
        ]));

        $this->assertSame(0, $result['criteria'][3]['points']);
        $this->assertSame('warn', $result['criteria'][3]['status']);
        $this->assertStringContainsString('wacht op data', $result['criteria'][3]['detail']);
        $this->assertSame(9, $result['totalPoints']);
        $this->assertSame('A', $result['grade']);
    }

    public function test_volume_score_green_candle_with_low_rvol_passes(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'bounce_volume_above_average' => false,
            'relative_volume' => '88%',
            'latest_open_price' => 100.00,
            'latest_close_price' => 102.00,
            'bounce_day_volume' => 6_180_000,
            'volume_sma_20' => 11_331_347,
        ]));

        $this->assertSame(1, $result['criteria'][3]['points']);
        $this->assertSame('pass', $result['criteria'][3]['status']);
        $this->assertStringContainsString('RVol 88%', $result['criteria'][3]['detail']);
        $this->assertStringContainsString('geen institutionele dump', $result['criteria'][3]['detail']);
        $this->assertSame([], $result['hardFailReasons']);
    }

    public function test_volume_score_treats_percent_polluted_form_state_as_ratio_on_green_candle(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'bounce_volume_above_average' => false,
            'relative_volume' => '88%',
            'latest_open_price' => 100.00,
            'latest_close_price' => 102.00,
        ]));

        $this->assertSame(1, $result['criteria'][3]['points']);
        $this->assertStringContainsString('RVol 88%', $result['criteria'][3]['detail']);
        $this->assertStringContainsString('geen institutionele dump', $result['criteria'][3]['detail']);
    }

    public function test_volume_score_red_candle_with_high_volume(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.45,
            'latest_open_price' => 102.00,
            'latest_close_price' => 100.00,
            'bounce_day_volume' => 15_000_000,
            'volume_sma_20' => 10_000_000,
        ]));

        $this->assertSame(0, $result['criteria'][3]['points']);
        $this->assertSame('fail', $result['criteria'][3]['status']);
        $this->assertStringContainsString('Vallend mes', $result['criteria'][3]['detail']);
        $this->assertContains('Vallend mes — hoog volume maar slotkoers onder openingskoers', $result['hardFailReasons']);
    }

    public function test_volume_score_requires_open_price_when_volume_confirmed(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.45,
            'latest_open_price' => null,
            'latest_close_price' => 100.50,
        ]));

        $this->assertSame(0, $result['criteria'][3]['points']);
        $this->assertSame('fail', $result['criteria'][3]['status']);
        $this->assertStringContainsString('Open/slotkoers ontbreekt', $result['criteria'][3]['detail']);
        $this->assertNotSame('A++', $result['grade']);
    }

    public function test_b_setup_scores_seven(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 1.0,
        ]));

        $this->assertSame(7, $result['totalPoints']);
        $this->assertSame('B', $result['grade']);
        $this->assertSame('B SETUP', $result['gradeLabel']);
    }

    public function test_c_setup_scores_five_to_six(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 1.0,
            'latest_open_price' => 102.00,
            'latest_close_price' => 100.50,
            'bounce_volume_above_average' => false,
            'relative_volume' => 0.82,
            'bounce_day_volume' => 6_000_000,
            'volume_sma_20' => 10_000_000,
        ]));

        $this->assertSame(6, $result['totalPoints']);
        $this->assertSame('C', $result['grade']);
        $this->assertSame('C SETUP', $result['gradeLabel']);
        $this->assertSame([], $result['hardFailReasons']);
    }

    public function test_score_below_five_without_hard_fail_is_no_trade(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'signal_low' => 103.10,
            'latest_open_price' => 103.10,
            'latest_close_price' => 103.10,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => false,
            'relative_volume' => null,
            'sector_etf' => null,
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => null,
        ]);

        $this->assertSame(4, $result['totalPoints']);
        $this->assertSame('NO TRADE', $result['grade']);
        $this->assertSame([], $result['hardFailReasons']);
    }

    public function test_earnings_within_window_is_hard_fail_despite_perfect_score(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 101.00,
            'latest_close_price' => 101.00,
            'days_until_earnings' => 8,
        ]));

        $this->assertSame(10, $result['totalPoints']);
        $this->assertSame('NO TRADE', $result['grade']);
        $this->assertSame('NO TRADE', $result['gradeLabel']);
        $this->assertContains('Earnings over 8 dagen — te weinig runway voor entry', $result['hardFailReasons']);
    }

    public function test_earnings_beyond_window_is_not_hard_fail(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 101.00,
            'latest_close_price' => 101.00,
            'days_until_earnings' => 15,
        ]));

        $this->assertSame(10, $result['totalPoints']);
        $this->assertSame('A', $result['grade']);
        $this->assertSame([], $result['hardFailReasons']);
    }

    public function test_missing_earnings_data_is_not_hard_fail(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseInputs([
            'signal_low' => 101.00,
            'latest_close_price' => 101.00,
            'days_until_earnings' => null,
        ]));

        $this->assertSame(10, $result['totalPoints']);
        $this->assertSame('A', $result['grade']);
        $this->assertSame([], $result['hardFailReasons']);
    }

    public function test_position_display_grade_is_a_for_perfect_score_without_manual_promotion(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->for($user)->scout()->create([
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
            'trader_promoted_a' => false,
        ]);

        $result = $position->evaluateSetupScore();

        $this->assertSame(10, $result['totalPoints']);
        $this->assertSame('A', $result['grade']);
        $this->assertSame('A SETUP', $result['gradeLabel']);
    }

    public function test_position_display_grade_promotes_to_a_when_confirmed(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->for($user)->scout()->create([
            'signal_low' => 101.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 68.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'trader_promoted_a' => true,
        ]);

        $result = $position->evaluateSetupScore();

        $this->assertSame(8, $result['totalPoints']);
        $this->assertSame('A', $result['grade']);
        $this->assertSame('A SETUP', $result['gradeLabel']);
    }

    public function test_position_display_grade_promotes_to_a_plus_plus_when_confirmed(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->for($user)->scout()->create([
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
            'trader_promoted_a_plus' => true,
        ]);

        $result = $position->evaluateSetupScore();

        $this->assertSame(10, $result['totalPoints']);
        $this->assertSame('A++', $result['grade']);
        $this->assertSame('A++ SETUP', $result['gradeLabel']);
    }

    public function test_short_setup_scores_well_when_price_is_below_sma_with_headwind(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseShortInputs());

        $this->assertSame(10, $result['totalPoints']);
        $this->assertSame('A', $result['grade']);
        $this->assertSame([], $result['hardFailReasons']);
        $this->assertSame(2, $result['criteria'][0]['points']);
        $this->assertSame(2, $result['criteria'][1]['points']);
        $this->assertSame(2, $result['criteria'][4]['points']);
        $this->assertStringContainsString('Tegenwind', $result['criteria'][4]['detail']);
        $this->assertStringContainsString('Rejection', $result['criteria'][0]['detail']);
        $this->assertStringContainsString('Glijbaan', $result['criteria'][1]['detail']);
    }

    public function test_short_close_above_sma_is_hard_fail_for_long_logic_but_not_for_short(): void
    {
        $longResult = ScoutSetupScorecard::evaluate($this->baseInputs([
            'latest_open_price' => 101.00,
            'latest_close_price' => 99.00,
        ]));

        $shortResult = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'latest_open_price' => 101.00,
            'latest_close_price' => 99.00,
        ]));

        $this->assertContains('Close onder SMA 20 — trampoline gebroken', $longResult['hardFailReasons']);
        $this->assertSame([], $shortResult['hardFailReasons']);
    }

    public function test_short_red_candle_scores_volume_point(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseShortInputs());

        $this->assertSame(1, $result['criteria'][3]['points']);
        $this->assertStringContainsString('rode kaars', strtolower($result['criteria'][3]['detail']));
    }

    public function test_short_red_candle_without_rvol_scores_zero(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'relative_volume' => null,
            'bounce_volume_above_average' => false,
            'bounce_day_volume' => null,
            'volume_sma_20' => null,
        ]));

        $this->assertSame(0, $result['criteria'][3]['points']);
        $this->assertSame('warn', $result['criteria'][3]['status']);
        $this->assertStringContainsString('wacht op data', $result['criteria'][3]['detail']);
        $this->assertLessThanOrEqual(9, $result['totalPoints']);
    }

    public function test_short_waterfall_requires_today_lt_five_lt_ten(): void
    {
        $pass = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 102.00,
            'sma_20_ten_days_ago' => 105.00,
            'latest_sma_50' => 110.00,
            'signal_high' => 101.00,
            'latest_open_price' => 99.50,
            'latest_close_price' => 99.00,
        ]));

        $fail = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00, // breaks today < 5d
            'sma_20_ten_days_ago' => 105.00,
            'latest_sma_50' => 110.00,
            'signal_high' => 101.00,
            'latest_open_price' => 99.50,
            'latest_close_price' => 99.00,
        ]));

        $this->assertSame([], $pass['hardFailReasons']);
        $this->assertSame(2, $pass['criteria'][1]['points']);
        $this->assertContains('SMA-waterval ontbreekt — geen glijbaan (chop-risico)', $fail['hardFailReasons']);
        $this->assertSame(0, $fail['criteria'][1]['points']);
        $this->assertSame('NO TRADE', $fail['grade']);
    }

    public function test_short_missing_five_day_sma_is_hard_fail(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'sma_20_five_days_ago' => null,
        ]));

        $this->assertContains('Haal marktdata op voor 5-daagse SMA-waterval', $result['hardFailReasons']);
        $this->assertSame('NO TRADE', $result['grade']);
    }

    public function test_short_rejection_requires_high_to_tag_sma_ceiling(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'signal_high' => 938.00, // below SMA 939.52
            'latest_open_price' => 937.00,
            'latest_close_price' => 935.80,
        ]));

        $this->assertContains('Geen SMA-afwijzing — High raakt plafond niet', $result['hardFailReasons']);
        $this->assertSame(0, $result['criteria'][0]['points']);
    }

    public function test_short_rejection_requires_close_below_sma(): void
    {
        // High tags SMA, but close sits on/above SMA — distinct message from high-miss.
        $result = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'signal_high' => 6.20,
            'latest_open_price' => 5.90,
            'latest_close_price' => 5.92,
            'latest_sma_20' => 5.92,
            'sma_20_five_days_ago' => 6.36,
            'sma_20_ten_days_ago' => 6.81,
            'latest_sma_50' => 7.11,
        ]));

        $this->assertContains('Geen SMA-afwijzing — Close moet onder SMA 20', $result['hardFailReasons']);
        $this->assertNotContains('Geen SMA-afwijzing — High raakt plafond niet', $result['hardFailReasons']);
        $this->assertSame(0, $result['criteria'][0]['points']);
        $this->assertSame('NO TRADE', $result['grade']);
    }

    public function test_short_rejection_requires_sufficient_upper_wick(): void
    {
        // High tags SMA, red close under SMA, but tiny upper wick.
        $result = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'signal_high' => 939.60,
            'latest_open_price' => 939.50,
            'latest_close_price' => 935.80,
        ]));

        $this->assertContains('Upper wick te kort — geen institutionele afstraffing', $result['hardFailReasons']);
        $this->assertSame(0, $result['criteria'][0]['points']);
    }

    public function test_short_near_miss_above_sma_no_longer_escapes_hard_fail(): void
    {
        // Red candle ~0.18% above SMA — previously near-miss could pass.
        $result = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'signal_high' => 1173.00,
            'latest_open_price' => 1172.00,
            'latest_close_price' => 1173.00,
            'latest_sma_20' => 1171.25,
            'sma_20_five_days_ago' => 1180.00,
            'sma_20_ten_days_ago' => 1190.00,
            'latest_sma_50' => 1200.00,
        ]));

        $this->assertContains('Close boven SMA 20 — geen short-trampoline', $result['hardFailReasons']);
        $this->assertSame('NO TRADE', $result['grade']);
        $this->assertSame(0, $result['criteria'][0]['points']);
    }

    public function test_short_earnings_within_window_still_hard_fails(): void
    {
        $result = ScoutSetupScorecard::evaluate($this->baseShortInputs([
            'days_until_earnings' => 8,
        ]));

        $this->assertContains('Earnings over 8 dagen — te weinig runway voor entry', $result['hardFailReasons']);
        $this->assertSame('NO TRADE', $result['grade']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseShortInputs(array $overrides = []): array
    {
        return array_merge([
            'direction' => 'short',
            'signal_high' => 945.00,
            'latest_open_price' => 938.75,
            'latest_close_price' => 935.80,
            'latest_sma_20' => 939.52,
            'sma_20_five_days_ago' => 950.00,
            'sma_20_ten_days_ago' => 960.67,
            'latest_sma_50' => 976.24,
            'scout_rsi' => 45.64,
            'relative_volume' => 0.95,
            'sector_etf' => 'XLY',
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 2.13,
        ], $overrides);
    }
}
