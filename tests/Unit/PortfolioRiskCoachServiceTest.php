<?php

namespace Tests\Unit;

use App\Enums\TradeDirection;
use App\Models\Position;
use App\Models\User;
use App\Services\PortfolioRiskCoachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioRiskCoachServiceTest extends TestCase
{
    use RefreshDatabase;

    private PortfolioRiskCoachService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PortfolioRiskCoachService::class);
    }

    public function test_locked_position_does_not_block_sector_slot(): void
    {
        $user = User::factory()->create();

        $this->openPosition($user, 'BAC', 'XLF', riskOn: false);
        $scout = $this->orderPlanScout($user, 'SFNC', 'XLF', score: 8);

        $exclusions = $this->service->evaluateOrderPlanExclusions($user, [$scout]);

        $this->assertSame([], $exclusions);
        $this->assertFalse($scout->fresh()->isOrderPlanExcludedToday());
    }

    public function test_risk_on_open_excludes_all_scouts_in_same_sector_and_direction(): void
    {
        $user = User::factory()->create();

        $this->openPosition($user, 'BAC', 'XLF', riskOn: true);
        $sfnc = $this->orderPlanScout($user, 'SFNC', 'XLF', score: 9);
        $tfc = $this->orderPlanScout($user, 'TFC', 'XLF', score: 8);

        $exclusions = $this->service->evaluateOrderPlanExclusions($user, [$sfnc, $tfc]);

        $this->assertCount(2, $exclusions);
        $tickers = collect($exclusions)->pluck('ticker')->sort()->values()->all();
        $this->assertSame(['SFNC', 'TFC'], $tickers);
        $this->assertStringContainsString('XLF', $exclusions[0]['reason']);
        $this->assertStringContainsString('long', $exclusions[0]['reason']);
        $this->assertStringContainsString('BAC', $exclusions[0]['reason']);
        $this->assertTrue($sfnc->fresh()->isOrderPlanExcludedToday());
        $this->assertTrue($tfc->fresh()->isOrderPlanExcludedToday());
    }

    public function test_open_long_does_not_block_short_scout_same_sector(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        $this->openPosition($user, 'EMBJ', 'XLK', riskOn: true, direction: TradeDirection::Long);
        $pony = $this->orderPlanScout($user, 'PONY', 'XLK', score: 9, direction: TradeDirection::Short);

        $exclusions = $this->service->evaluateOrderPlanExclusions($user, [$pony]);

        $this->assertSame([], $exclusions);
        $this->assertFalse($pony->fresh()->isOrderPlanExcludedToday());
    }

    public function test_open_long_still_blocks_long_scout_same_sector(): void
    {
        $user = User::factory()->create();

        $this->openPosition($user, 'EMBJ', 'XLK', riskOn: true, direction: TradeDirection::Long);
        $other = $this->orderPlanScout($user, 'AAPL', 'XLK', score: 8, direction: TradeDirection::Long);

        $exclusions = $this->service->evaluateOrderPlanExclusions($user, [$other]);

        $this->assertCount(1, $exclusions);
        $this->assertSame('AAPL', $exclusions[0]['ticker']);
        $this->assertStringContainsString('long', $exclusions[0]['reason']);
    }

    public function test_two_scouts_same_sector_keeps_highest_score_when_slot_free(): void
    {
        $user = User::factory()->create();

        $sfnc = $this->orderPlanScout($user, 'SFNC', 'XLF', score: 9);
        $tfc = $this->orderPlanScout($user, 'TFC', 'XLF', score: 7);
        $other = $this->orderPlanScout($user, 'AAPL', 'XLK', score: 8);

        $exclusions = $this->service->evaluateOrderPlanExclusions($user, [$sfnc, $tfc, $other]);

        $this->assertCount(1, $exclusions);
        $this->assertSame('TFC', $exclusions[0]['ticker']);
        $this->assertStringContainsString('SFNC', $exclusions[0]['reason']);
        $this->assertFalse($sfnc->fresh()->isOrderPlanExcludedToday());
        $this->assertFalse($other->fresh()->isOrderPlanExcludedToday());
        $this->assertTrue($tfc->fresh()->isOrderPlanExcludedToday());
    }

    public function test_two_short_scouts_same_sector_keeps_highest_score(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        $better = $this->orderPlanScout($user, 'PONY', 'XLK', score: 9, direction: TradeDirection::Short);
        $worse = $this->orderPlanScout($user, 'AMD', 'XLK', score: 7, direction: TradeDirection::Short);

        $exclusions = $this->service->evaluateOrderPlanExclusions($user, [$better, $worse]);

        $this->assertCount(1, $exclusions);
        $this->assertSame('AMD', $exclusions[0]['ticker']);
        $this->assertStringContainsString('short', $exclusions[0]['reason']);
        $this->assertFalse($better->fresh()->isOrderPlanExcludedToday());
    }

    public function test_sector_slot_keeps_live_scorecard_over_stale_last_setup_score(): void
    {
        $user = User::factory()->create();

        $dal = $this->orderPlanScoutWithLiveScorecard($user, 'DAL', 'XLI', liveScore: 8, storedScore: 10);
        $sblk = $this->orderPlanScoutWithLiveScorecard($user, 'SBLK', 'XLI', liveScore: 9, storedScore: 7);

        $exclusions = $this->service->evaluateOrderPlanExclusions($user, [$dal, $sblk]);

        $this->assertCount(1, $exclusions);
        $this->assertSame('DAL', $exclusions[0]['ticker']);
        $this->assertStringContainsString('behouden: SBLK', $exclusions[0]['reason']);
        $this->assertFalse($sblk->fresh()->isOrderPlanExcludedToday());
        $this->assertTrue($dal->fresh()->isOrderPlanExcludedToday());
    }

    public function test_long_heavy_insight_when_mostly_long(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        foreach (['AAA', 'BBB', 'CCC', 'DDD', 'EEE'] as $i => $ticker) {
            $sector = ['XLK', 'XLF', 'XLE', 'XLV', 'XLI'][$i];
            $this->openPosition($user, $ticker, $sector, riskOn: true, direction: TradeDirection::Long);
        }
        $this->openPosition($user, 'SHORT1', 'XLY', riskOn: true, direction: TradeDirection::Short);

        $insights = $this->service->insights($user);
        $types = collect($insights)->pluck('type')->all();

        $this->assertContains('long_heavy', $types);
        $longHeavy = collect($insights)->firstWhere('type', 'long_heavy');
        $this->assertSame('OVEREXPOSURE', $longHeavy['title']);
        $this->assertStringContainsString('long', strtolower($longHeavy['body']));
    }

    public function test_sector_concentration_insight_for_risk_on(): void
    {
        $user = User::factory()->create();
        $this->openPosition($user, 'BAC', 'XLF', riskOn: true);

        $insights = $this->service->insights($user);
        $concentration = collect($insights)->firstWhere('type', 'sector_concentration');

        $this->assertNotNull($concentration);
        $this->assertSame('SECTOR BLOKKEERD (XLF)', $concentration['title']);
        $this->assertStringContainsString('BAC', $concentration['body']);
        $this->assertStringContainsString('long', strtolower($concentration['body']));
    }

    public function test_command_center_empty_portfolio(): void
    {
        $user = User::factory()->create();

        $cc = $this->service->commandCenter($user);

        $this->assertSame('GEEN OPEN POSITIES', $cc['directives'][0]['headline']);
        $this->assertSame('gray', $cc['directives'][0]['severity']);
        $this->assertSame('LAAG', $cc['vitals']['risk']['label']);
        $this->assertSame(0, $cc['vitals']['sectors']['active']);
        $this->assertSame(11, $cc['vitals']['sectors']['total']);
        $this->assertCount(11, $cc['sectors']);
        $this->assertSame('empty', $cc['sectors'][0]['state']);
    }

    public function test_command_center_sector_blocked_and_vitals(): void
    {
        $user = User::factory()->create();
        $this->openPosition($user, 'BAC', 'XLF', riskOn: true);

        $cc = $this->service->commandCenter($user);
        $blocked = collect($cc['directives'])->firstWhere('type', 'sector_concentration');
        $xlf = collect($cc['sectors'])->firstWhere('etf', 'XLF');

        $this->assertNotNull($blocked);
        $this->assertSame('SECTOR BLOKKEERD (XLF)', $blocked['headline']);
        $this->assertSame('danger', $blocked['severity']);
        $this->assertStringContainsString('Negeer nieuwe long-setups', $blocked['order']);
        $this->assertSame('full', $xlf['state']);
        $this->assertSame(['BAC'], $xlf['tickers']);
        $this->assertSame(1, $cc['vitals']['sectors']['active']);
        $this->assertSame('MATIG', $cc['vitals']['risk']['label']);
    }

    public function test_command_center_meewind_directive(): void
    {
        $user = User::factory()->create();
        $this->openPosition($user, 'AAA', 'XLK', riskOn: false);
        $this->openPosition($user, 'BBB', 'XLF', riskOn: false);

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'O',
            'sector_etf' => 'XLRE',
            'sector_trend_positive' => true,
            'direction' => TradeDirection::Long,
            'entry_price' => 100,
            'quantity' => 10,
        ]);

        $cc = $this->service->commandCenter($user);
        $meewind = collect($cc['directives'])->firstWhere('type', 'free_ammo');
        $xlre = collect($cc['sectors'])->firstWhere('etf', 'XLRE');

        $this->assertNotNull($meewind);
        $this->assertSame('MEEWIND KANS', $meewind['headline']);
        $this->assertSame('success', $meewind['severity']);
        $this->assertSame('meewind', $xlre['state']);
    }

    public function test_sector_exposure_splits_risk_on_and_locked_by_direction(): void
    {
        $user = User::factory()->create();
        $this->openPosition($user, 'BAC', 'XLF', riskOn: false);
        $this->openPosition($user, 'JPM', 'XLF', riskOn: true);

        $exposure = $this->service->sectorExposure($user);

        $this->assertSame(1, $exposure['XLF']['long']['risk_on_count']);
        $this->assertSame(1, $exposure['XLF']['long']['locked_count']);
        $this->assertSame(['JPM'], $exposure['XLF']['long']['risk_on']);
        $this->assertSame(['BAC'], $exposure['XLF']['long']['locked']);
        $this->assertSame(0, $exposure['XLF']['short']['risk_on_count']);
    }

    private function openPosition(
        User $user,
        string $ticker,
        string $sector,
        bool $riskOn,
        TradeDirection $direction = TradeDirection::Long,
    ): Position {
        $entry = 100.0;
        $sl = $direction === TradeDirection::Short
            ? ($riskOn ? 105.0 : 95.0)
            : ($riskOn ? 95.0 : 105.0);

        return Position::factory()->for($user)->create([
            'ticker' => $ticker,
            'status' => 'open',
            'direction' => $direction,
            'sector_etf' => $sector,
            'entry_price' => $entry,
            'current_sl' => $sl,
            'quantity' => 10,
            'latest_close_price' => $entry,
        ]);
    }

    private function orderPlanScout(
        User $user,
        string $ticker,
        string $sector,
        int $score,
        TradeDirection $direction = TradeDirection::Long,
    ): Position {
        return Position::factory()->for($user)->scout()->create([
            'ticker' => $ticker,
            'sector_etf' => $sector,
            'direction' => $direction,
            'last_setup_score' => $score,
            'entry_price' => 100.00,
            'market_open_reminder_on' => now()->toDateString(),
            'quantity' => 10,
        ]);
    }

    private function orderPlanScoutWithLiveScorecard(
        User $user,
        string $ticker,
        string $sector,
        int $liveScore,
        int $storedScore,
    ): Position {
        $base = [
            'signal_low' => 97.00,
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
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
        ];

        $liveAttributes = match ($liveScore) {
            9 => array_merge($base, ['pre_bounce_extension_atr' => 1.0]),
            8 => array_merge($base, [
                'pre_bounce_extension_atr' => 1.0,
                'scout_rsi' => 60.00,
            ]),
            default => $base,
        };

        return Position::factory()->for($user)->scout()->create(array_merge($liveAttributes, [
            'ticker' => $ticker,
            'sector_etf' => $sector,
            'direction' => TradeDirection::Long,
            'last_setup_score' => $storedScore,
            'entry_price' => 100.00,
            'market_open_reminder_on' => now()->toDateString(),
            'quantity' => 10,
        ]));
    }
}
