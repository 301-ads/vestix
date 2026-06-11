<?php

namespace Tests\Unit;

use App\Support\ScoutSetupScorecard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScoutSetupScorecardTest extends TestCase
{
    public function test_perfect_a_plus_setup_scores_seven(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
        ]);

        $this->assertSame(7, $result['totalPoints']);
        $this->assertSame('A+', $result['grade']);
        $this->assertSame('A+ SETUP', $result['gradeLabel']);
        $this->assertSame([], $result['hardFailReasons']);
    }

    public function test_entry_below_sma_scores_zero_trampoline_and_hard_fail(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => 99.90,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
        ]);

        $this->assertSame(0, $result['criteria'][0]['points']);
        $this->assertStringContainsString('trampoline gebroken', $result['criteria'][0]['detail']);
        $this->assertSame('B/C', $result['grade']);
        $this->assertContains('Koers onder SMA 20 — trampoline gebroken', $result['hardFailReasons']);
    }

    public function test_flat_sma_over_five_days_scores_two_points(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 100.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => false,
        ]);

        $this->assertSame(2, $result['criteria'][1]['points']);
        $this->assertStringContainsString('Flat over 5 dagen', $result['criteria'][1]['detail']);
    }

    public function test_declining_sma_over_five_days_scores_zero(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => 100.50,
            'latest_sma_20' => 99.00,
            'sma_20_five_days_ago' => 100.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => false,
        ]);

        $this->assertSame(0, $result['criteria'][1]['points']);
        $this->assertStringContainsString('Dalende SMA over 5 dagen', $result['criteria'][1]['detail']);
    }

    public function test_eog_scenario_fails_when_sma20_below_sma50(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => 100.50,
            'latest_sma_20' => 102.00,
            'sma_20_five_days_ago' => 100.00,
            'latest_sma_50' => 105.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => false,
        ]);

        $this->assertSame(0, $result['criteria'][1]['points']);
        $this->assertStringContainsString('SMA 20 onder SMA 50', $result['criteria'][1]['detail']);
    }

    public function test_fake_trend_fails_when_rising_vs_yesterday_but_declining_vs_five_days(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => 100.50,
            'latest_sma_20' => 100.50,
            'sma_20_five_days_ago' => 101.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => false,
        ]);

        $this->assertSame(0, $result['criteria'][1]['points']);
        $this->assertStringContainsString('Dalende SMA over 5 dagen', $result['criteria'][1]['detail']);
    }

    public function test_rsi_above_seventy_forces_bc_grade_despite_high_score(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 76.00,
            'bounce_volume_above_average' => true,
        ]);

        $this->assertSame(5, $result['totalPoints']);
        $this->assertSame('B/C', $result['grade']);
        $this->assertContains('RSI oververhit (>70) — geen A-setup mogelijk', $result['hardFailReasons']);
    }

    public function test_rsi_sixty_eight_can_still_be_a_minus(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 68.00,
            'bounce_volume_above_average' => true,
        ]);

        $this->assertSame(0, $result['criteria'][2]['points']);
        $this->assertSame(5, $result['totalPoints']);
        $this->assertSame('A-', $result['grade']);
    }

    #[DataProvider('trampolineDistanceProvider')]
    public function test_trampoline_distance_thresholds(float $entry, int $expectedPoints): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => $entry,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => null,
            'bounce_volume_above_average' => false,
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
        $result = ScoutSetupScorecard::evaluate([
            'entry_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => $rsi,
            'bounce_volume_above_average' => false,
        ]);

        $this->assertSame($expectedPoints, $result['criteria'][2]['points']);
    }

    public static function rsiThresholdProvider(): array
    {
        return [
            'sweet spot low' => [45.0, 2],
            'sweet spot mid' => [55.0, 2],
            'momentum zone' => [60.0, 1],
            'too hot for points' => [68.0, 0],
            'too weak' => [39.0, 0],
        ];
    }
}
