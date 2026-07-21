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

    public function test_risk_on_open_excludes_all_scouts_in_same_sector(): void
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
        $this->assertStringContainsString('BAC', $exclusions[0]['reason']);
        $this->assertTrue($sfnc->fresh()->isOrderPlanExcludedToday());
        $this->assertTrue($tfc->fresh()->isOrderPlanExcludedToday());
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

    public function test_long_heavy_insight_when_mostly_long(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        foreach (['AAA', 'BBB', 'CCC', 'DDD', 'EEE'] as $ticker) {
            $this->openPosition($user, $ticker, 'XLK', riskOn: true, direction: TradeDirection::Long);
        }
        $this->openPosition($user, 'SHORT1', 'XLE', riskOn: true, direction: TradeDirection::Short);

        $insights = $this->service->insights($user);
        $types = collect($insights)->pluck('type')->all();

        $this->assertContains('long_heavy', $types);
        $longHeavy = collect($insights)->firstWhere('type', 'long_heavy');
        $this->assertStringContainsString('long', strtolower($longHeavy['body']));
    }

    public function test_sector_concentration_insight_for_risk_on(): void
    {
        $user = User::factory()->create();
        $this->openPosition($user, 'BAC', 'XLF', riskOn: true);

        $insights = $this->service->insights($user);
        $concentration = collect($insights)->firstWhere('type', 'sector_concentration');

        $this->assertNotNull($concentration);
        $this->assertStringContainsString('XLF', $concentration['title']);
        $this->assertStringContainsString('BAC', $concentration['body']);
    }

    public function test_sector_exposure_splits_risk_on_and_locked(): void
    {
        $user = User::factory()->create();
        $this->openPosition($user, 'BAC', 'XLF', riskOn: false);
        $this->openPosition($user, 'JPM', 'XLF', riskOn: true);

        $exposure = $this->service->sectorExposure($user);

        $this->assertSame(1, $exposure['XLF']['risk_on_count']);
        $this->assertSame(1, $exposure['XLF']['locked_count']);
        $this->assertSame(['JPM'], $exposure['XLF']['risk_on']);
        $this->assertSame(['BAC'], $exposure['XLF']['locked']);
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
    ): Position {
        return Position::factory()->for($user)->scout()->create([
            'ticker' => $ticker,
            'sector_etf' => $sector,
            'last_setup_score' => $score,
            'entry_price' => 100.00,
            'market_open_reminder_on' => now()->toDateString(),
            'quantity' => 10,
        ]);
    }
}
