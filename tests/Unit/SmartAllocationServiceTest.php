<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Enums\BrokerOrderStatus;
use App\Models\Position;
use App\Models\User;
use App\Services\SmartAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SmartAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SmartAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Local .env may use IBKR_READER=flex; sizing tests use stub + trading_bankroll.
        config([
            'vestix.ibkr.reader' => 'stub',
            'vestix.ibkr.block_automation_when_stale' => true,
        ]);

        $this->service = app(SmartAllocationService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_equal_mode_splits_pie_evenly(): void
    {
        $user = $this->userWithBankroll();
        $a = $this->scout($user, 'AAA', score: 10, sector: 'XLV');
        $b = $this->scout($user, 'BBB', score: 6, sector: 'XLK');
        $c = $this->scout($user, 'CCC', score: 8, sector: 'XLF');

        $result = $this->service->allocate($user, [$a, $b, $c], SmartAllocationService::MODE_EQUAL);

        $this->assertCount(3, $result['allocations']);
        $this->assertEqualsWithDelta(100.0, $result['pie'], 0.01);

        foreach ($result['allocations'] as $allocation) {
            $this->assertEqualsWithDelta(1 / 3, $allocation['weight_share'], 0.001);
            $this->assertEqualsWithDelta(33.33, $allocation['risk_dollars'], 0.1);
        }
    }

    public function test_smart_mode_weights_by_score_when_rr_equal(): void
    {
        $user = $this->userWithBankroll();
        $rprx = $this->scout($user, 'RPRX', score: 10, sector: 'XLK');
        $ewtx = $this->scout($user, 'EWTX', score: 8, sector: 'XLF');
        $coo = $this->scout($user, 'COO', score: 6, sector: 'XLY');

        $result = $this->service->allocate($user, [$rprx, $ewtx, $coo], SmartAllocationService::MODE_SMART);

        $byTicker = collect($result['allocations'])->keyBy('ticker');

        $this->assertEqualsWithDelta(10 / 24, $byTicker['RPRX']['weight_share'], 0.001);
        $this->assertEqualsWithDelta(8 / 24, $byTicker['EWTX']['weight_share'], 0.001);
        $this->assertEqualsWithDelta(6 / 24, $byTicker['COO']['weight_share'], 0.001);
        $this->assertEqualsWithDelta(41.67, $byTicker['RPRX']['risk_dollars'], 0.1);
        $this->assertEqualsWithDelta(33.33, $byTicker['EWTX']['risk_dollars'], 0.1);
        $this->assertEqualsWithDelta(25.0, $byTicker['COO']['risk_dollars'], 0.1);
    }

    public function test_smart_mode_boosts_higher_reward_risk(): void
    {
        $user = $this->userWithBankroll();
        $highRr = $this->scout($user, 'HIGH', score: 8, sector: 'XLK', target1Rr: 3.0);
        $lowRr = $this->scout($user, 'LOW', score: 8, sector: 'XLF', target1Rr: 1.0);

        $result = $this->service->allocate($user, [$highRr, $lowRr], SmartAllocationService::MODE_SMART);
        $byTicker = collect($result['allocations'])->keyBy('ticker');

        $this->assertEqualsWithDelta(24.0, $byTicker['HIGH']['expected_value'], 0.01);
        $this->assertEqualsWithDelta(8.0, $byTicker['LOW']['expected_value'], 0.01);
        $this->assertGreaterThan($byTicker['LOW']['weight_share'], $byTicker['HIGH']['weight_share']);
        $this->assertEqualsWithDelta(0.75, $byTicker['HIGH']['weight_share'], 0.001);
        $this->assertEqualsWithDelta(0.25, $byTicker['LOW']['weight_share'], 0.001);
    }

    public function test_sector_penalty_two_and_three_in_same_etf(): void
    {
        $user = $this->userWithBankroll();

        $two = $this->service->allocate($user, [
            $this->scout($user, 'A1', score: 8, sector: 'XLV'),
            $this->scout($user, 'A2', score: 8, sector: 'XLV'),
            $this->scout($user, 'B1', score: 8, sector: 'XLK'),
        ], SmartAllocationService::MODE_SMART);

        $twoByTicker = collect($two['allocations'])->keyBy('ticker');
        // Soft-exclude keeps one XLV scout (A1 by ticker tie-break); no peer penalty left.
        $this->assertArrayHasKey('A1', $twoByTicker->all());
        $this->assertArrayNotHasKey('A2', $twoByTicker->all());
        $this->assertEqualsWithDelta(0.0, $twoByTicker['A1']['sector_penalty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $twoByTicker['B1']['sector_penalty'], 0.001);
        $this->assertSame('A2', $two['exclusions'][0]['ticker']);

        $three = $this->service->allocate($user, [
            $this->scout($user, 'C1', score: 8, sector: 'XLV'),
            $this->scout($user, 'C2', score: 8, sector: 'XLV'),
            $this->scout($user, 'C3', score: 8, sector: 'XLV'),
        ], SmartAllocationService::MODE_SMART);

        $this->assertCount(1, $three['allocations']);
        $this->assertSame('C1', $three['allocations'][0]['ticker']);
        $this->assertEqualsWithDelta(0.0, $three['allocations'][0]['sector_penalty'], 0.001);
        $this->assertCount(2, $three['exclusions']);
    }

    public function test_open_risk_on_excludes_order_plan_scouts_in_same_sector(): void
    {
        $user = $this->userWithBankroll();

        Position::factory()->for($user)->create([
            'ticker' => 'BAC',
            'status' => 'open',
            'sector_etf' => 'XLF',
            'entry_price' => 100.00,
            'current_sl' => 95.00,
            'quantity' => 10,
            'latest_close_price' => 100.00,
        ]);

        $sfnc = $this->scout($user, 'SFNC', score: 9, sector: 'XLF');
        $tfc = $this->scout($user, 'TFC', score: 8, sector: 'XLF');
        $aapl = $this->scout($user, 'AAPL', score: 8, sector: 'XLK');

        $result = $this->service->allocate($user, [$sfnc, $tfc, $aapl], SmartAllocationService::MODE_SMART);

        $tickers = collect($result['allocations'])->pluck('ticker')->all();
        $this->assertSame(['AAPL'], $tickers);
        $excluded = collect($result['exclusions'])->pluck('ticker')->sort()->values()->all();
        $this->assertSame(['SFNC', 'TFC'], $excluded);
        $this->assertStringContainsString('BAC', collect($result['exclusions'])->first()['reason']);
    }

    public function test_open_risk_on_seeds_sector_penalty_when_slot_allows(): void
    {
        config(['vestix.portfolio_coach.max_risk_on_per_sector' => 2]);

        $user = $this->userWithBankroll();

        Position::factory()->for($user)->create([
            'ticker' => 'BAC',
            'status' => 'open',
            'sector_etf' => 'XLF',
            'entry_price' => 100.00,
            'current_sl' => 95.00,
            'quantity' => 10,
            'latest_close_price' => 100.00,
        ]);

        $sfnc = $this->scout($user, 'SFNC', score: 8, sector: 'XLF');
        $aapl = $this->scout($user, 'AAPL', score: 8, sector: 'XLK');

        $result = $this->service->allocate($user, [$sfnc, $aapl], SmartAllocationService::MODE_SMART);
        $byTicker = collect($result['allocations'])->keyBy('ticker');

        $this->assertArrayHasKey('SFNC', $byTicker->all());
        $this->assertEqualsWithDelta(0.20, $byTicker['SFNC']['sector_penalty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $byTicker['AAPL']['sector_penalty'], 0.001);
    }

    public function test_excludes_score_below_min(): void
    {
        $user = $this->userWithBankroll();
        $strong = $this->scout($user, 'GOOD', score: 7, sector: 'XLK');
        $weak = $this->scout($user, 'WEAK', score: 4, sector: 'XLV');

        $result = $this->service->allocate($user, [$strong, $weak], SmartAllocationService::MODE_SMART);

        $this->assertCount(1, $result['allocations']);
        $this->assertSame('GOOD', $result['allocations'][0]['ticker']);
        $this->assertCount(1, $result['exclusions']);
        $this->assertSame('WEAK', $result['exclusions'][0]['ticker']);
        $this->assertEqualsWithDelta(100.0, $result['allocations'][0]['risk_dollars'], 0.01);
    }

    public function test_excludes_missing_entry_or_stop(): void
    {
        $user = $this->userWithBankroll();
        $ok = $this->scout($user, 'OK', score: 8, sector: 'XLK');
        $missing = Position::factory()->for($user)->scout()->create([
            'ticker' => 'MISS',
            'last_setup_score' => 8,
            'entry_price' => null,
            'latest_sma_20' => null,
            'latest_atr_14' => null,
            'sector_etf' => 'XLV',
        ]);

        $result = $this->service->allocate($user, [$ok, $missing], SmartAllocationService::MODE_SMART);

        $this->assertCount(1, $result['allocations']);
        $this->assertSame('MISS', $result['exclusions'][0]['ticker']);
    }

    public function test_risk_per_allocation_never_exceeds_pie(): void
    {
        $user = $this->userWithBankroll();
        $solo = $this->scout($user, 'SOLO', score: 10, sector: 'XLK');

        $result = $this->service->allocate($user, [$solo], SmartAllocationService::MODE_SMART);

        $this->assertLessThanOrEqual($result['pie'] + 0.001, $result['allocations'][0]['risk_dollars']);
        $this->assertEqualsWithDelta(100.0, $result['allocations'][0]['risk_dollars'], 0.01);
    }

    public function test_apply_to_positions_writes_quantity_and_risk_budget(): void
    {
        $user = $this->userWithBankroll();
        $a = $this->scout($user, 'AAA', score: 10, sector: 'XLK');
        $b = $this->scout($user, 'BBB', score: 10, sector: 'XLF');

        $result = $this->service->allocate($user, [$a, $b], SmartAllocationService::MODE_EQUAL);
        $updated = $this->service->applyToPositions([$a, $b], $result['allocations']);

        $this->assertSame(2, $updated);
        $this->assertNotNull($a->fresh()->quantity);
        $this->assertNotNull($a->fresh()->risk_budget);
        $this->assertEqualsWithDelta(50.0, (float) $a->fresh()->risk_budget, 0.5);
    }

    public function test_unknown_sector_does_not_group_with_others(): void
    {
        $user = $this->userWithBankroll();
        $a = $this->scout($user, 'A', score: 8, sector: null);
        $b = $this->scout($user, 'B', score: 8, sector: null);
        $c = $this->scout($user, 'C', score: 8, sector: 'XLK');

        $result = $this->service->allocate($user, [$a, $b, $c], SmartAllocationService::MODE_SMART);

        foreach ($result['allocations'] as $allocation) {
            $this->assertEqualsWithDelta(0.0, $allocation['sector_penalty'], 0.001);
        }
    }

    public function test_identical_score_and_rr_makes_smart_match_equal(): void
    {
        $user = $this->userWithBankroll();
        $coo = $this->scout($user, 'COO', score: 10, sector: 'XLV');
        $rprx = $this->scout($user, 'RPRX', score: 10, sector: 'XLK');

        $smart = $this->service->allocate($user, [$coo, $rprx], SmartAllocationService::MODE_SMART);
        $equal = $this->service->allocate($user, [$coo, $rprx], SmartAllocationService::MODE_EQUAL);

        $this->assertTrue($smart['weights_uniform']);
        $this->assertEqualsWithDelta(
            $equal['allocations'][0]['risk_dollars'],
            $smart['allocations'][0]['risk_dollars'],
            0.01,
        );
        $this->assertEqualsWithDelta(
            $equal['allocations'][1]['risk_dollars'],
            $smart['allocations'][1]['risk_dollars'],
            0.01,
        );
    }

    public function test_sizing_bankroll_uses_ibkr_nlv_without_subtracting_revolut(): void
    {
        $user = $this->userWithBankroll();
        $user->update(['trading_bankroll' => 10000]);

        Position::factory()->for($user)->create([
            'ticker' => 'LEG',
            'status' => 'open',
            'broker' => Broker::Revolut,
            'is_legacy' => false,
            'quantity' => 100,
            'entry_price' => 20,
            'latest_close_price' => 20,
        ]);

        // Profile NLV is already IBKR-only; Revolut opens must not shrink the pie again.
        $this->assertEqualsWithDelta(10000.0, $this->service->resolveSizingBankroll($user->fresh()), 0.01);
    }

    public function test_unaffordable_share_is_excluded_and_budget_redistributed(): void
    {
        $user = User::factory()->create([
            'trading_bankroll' => 4578.94,
            'default_risk_percent' => 2.5,
        ]);

        // Pie ≈ $114.47 over 4 setups → LLY gets ~$27.58 < ~$31.89 risk/share → 0 stuks.
        $all = Position::factory()->for($user)->scout()->create([
            'ticker' => 'ALL',
            'last_setup_score' => 10,
            'entry_price' => 245.40,
            'latest_sma_20' => 240.00,
            'latest_atr_14' => 4.00,
            'sector_etf' => 'XLF',
            'target_1_rr' => 2.0,
        ]);

        $lly = Position::factory()->for($user)->scout()->create([
            'ticker' => 'LLY',
            'last_setup_score' => 8,
            'entry_price' => 1192.89,
            'latest_sma_20' => 1171.00,
            'latest_atr_14' => 20.00,
            'sector_etf' => 'XLV',
            'target_1_rr' => 2.0,
        ]);

        $kvue = Position::factory()->for($user)->scout()->create([
            'ticker' => 'KVUE',
            'last_setup_score' => 10,
            'entry_price' => 19.14,
            'latest_sma_20' => 18.50,
            'latest_atr_14' => 0.40,
            'sector_etf' => 'XLP',
            'target_1_rr' => 2.0,
        ]);

        $syy = Position::factory()->for($user)->scout()->create([
            'ticker' => 'SYY',
            'last_setup_score' => 9,
            'entry_price' => 82.91,
            'latest_sma_20' => 82.09,
            'latest_atr_14' => 1.77,
            'sector_etf' => 'XLY',
            'target_1_rr' => 2.0,
        ]);

        $result = $this->service->allocate($user, [$all, $lly, $kvue, $syy], SmartAllocationService::MODE_SMART);

        $tickers = collect($result['allocations'])->pluck('ticker')->all();
        $this->assertNotContains('LLY', $tickers);
        $this->assertContains('ALL', $tickers);
        $this->assertContains('KVUE', $tickers);
        $this->assertContains('SYY', $tickers);

        foreach ($result['allocations'] as $allocation) {
            $this->assertGreaterThanOrEqual(2, $allocation['quantity']);
        }

        $exclusion = collect($result['exclusions'])->firstWhere('ticker', 'LLY');
        $this->assertNotNull($exclusion);
        $this->assertStringContainsString('herverdeeld', $exclusion['reason']);
        $this->assertTrue($lly->fresh()->isOrderPlanExcludedToday());

        $allocatedRisk = array_sum(array_column($result['allocations'], 'risk_dollars'));
        $this->assertEqualsWithDelta($result['pie'], $allocatedRisk, 0.05);
    }

    public function test_sticky_exclusion_keeps_lly_out_when_only_scout_left(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 14:00:00', 'Europe/Amsterdam'));

        $user = User::factory()->create([
            'trading_bankroll' => 4578.94,
            'default_risk_percent' => 1.5,
        ]);

        $lly = Position::factory()->for($user)->scout()->create([
            'ticker' => 'LLY',
            'last_setup_score' => 8,
            'entry_price' => 1192.89,
            'latest_sma_20' => 1171.00,
            'latest_atr_14' => 20.00,
            'signal_low' => 1141.20,
            'sector_etf' => 'XLV',
            'target_1_rr' => 2.0,
            'market_open_reminder_on' => '2026-07-17',
            'order_plan_excluded_on' => '2026-07-17',
        ]);

        // Alone with full pie LLY would be affordable — sticky must still block.
        $result = $this->service->allocate($user, [$lly], SmartAllocationService::MODE_SMART);

        $this->assertSame([], $result['allocations']);
        $this->assertCount(1, $result['exclusions']);
        $this->assertSame('LLY', $result['exclusions'][0]['ticker']);
        $this->assertStringContainsString('niet opnieuw verdeeld', $result['exclusions'][0]['reason']);
    }

    public function test_active_order_plan_lists_pending_buy_stops(): void
    {
        $user = $this->userWithBankroll();

        $active = Position::factory()->for($user)->scout()->create([
            'ticker' => 'ALL',
            'broker_order_status' => BrokerOrderStatus::Pending,
            'entry_price' => 245.00,
            'quantity' => 5,
            'market_open_reminder_on' => null,
        ]);

        $cart = Position::factory()->for($user)->scout()->create([
            'ticker' => 'KVUE',
            'broker_order_status' => BrokerOrderStatus::Scout,
            'entry_price' => 19.00,
            'market_open_reminder_on' => '2026-07-17',
        ]);

        $activeList = Position::activeOrderPlanForUser((int) $user->id);

        $this->assertTrue($activeList->contains('id', $active->id));
        $this->assertFalse($activeList->contains('id', $cart->id));
    }

    public function test_allocate_reserves_risk_already_on_active_buy_stops(): void
    {
        $user = $this->userWithBankroll(); // pie = $100

        // entry 100, stop = 98 − 2/2 = 97 → $3/share × 10 = $30 planned risk
        Position::factory()->for($user)->scout()->create([
            'ticker' => 'JNJ',
            'broker_order_status' => BrokerOrderStatus::Pending,
            'entry_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'quantity' => 10,
            'risk_budget' => 30.00,
            'market_open_reminder_on' => null,
        ]);

        $pending = Position::factory()->for($user)->scout()->create([
            'ticker' => 'EMBJ',
            'last_setup_score' => 9,
            'entry_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLK',
            'target_1_rr' => 2.0,
        ]);

        $result = $this->service->allocate($user, [$pending], SmartAllocationService::MODE_SMART);

        $this->assertEqualsWithDelta(100.0, $result['pie_total'], 0.01);
        $this->assertEqualsWithDelta(30.0, $result['pie_committed'], 0.01);
        $this->assertEqualsWithDelta(70.0, $result['pie'], 0.01);
        $this->assertCount(1, $result['allocations']);
        $this->assertEqualsWithDelta(70.0, $result['allocations'][0]['risk_dollars'], 0.1);
        // Alone with full pie: floor(100/3)=33; with remaining $70: floor(70/3)=23
        $this->assertSame(23, $result['allocations'][0]['quantity']);
    }

    public function test_allocate_falls_back_to_risk_budget_when_planned_risk_unavailable(): void
    {
        $user = $this->userWithBankroll();

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'NU',
            'broker_order_status' => BrokerOrderStatus::Pending,
            'entry_price' => null,
            'quantity' => null,
            'risk_budget' => 55.00,
            'market_open_reminder_on' => null,
        ]);

        $pending = $this->scout($user, 'EMBJ', score: 9, sector: 'XLK');

        $result = $this->service->allocate($user, [$pending], SmartAllocationService::MODE_EQUAL);

        $this->assertEqualsWithDelta(55.0, $result['pie_committed'], 0.01);
        $this->assertEqualsWithDelta(45.0, $result['pie'], 0.01);
        $this->assertEqualsWithDelta(45.0, $result['allocations'][0]['risk_dollars'], 0.1);
    }

    public function test_allocate_yields_empty_when_active_orders_consume_full_pie(): void
    {
        $user = $this->userWithBankroll();

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'JNJ',
            'broker_order_status' => BrokerOrderStatus::Pending,
            'entry_price' => null,
            'risk_budget' => 100.00,
            'market_open_reminder_on' => null,
        ]);

        $pending = $this->scout($user, 'EMBJ', score: 9, sector: 'XLK');

        $result = $this->service->allocate($user, [$pending], SmartAllocationService::MODE_SMART);

        $this->assertEqualsWithDelta(0.0, $result['pie'], 0.01);
        $this->assertSame([], $result['allocations']);
    }

    public function test_uses_live_scorecard_instead_of_stale_last_setup_score(): void
    {
        $user = $this->userWithBankroll();

        $position = Position::factory()->for($user)->scout()->short()->create([
            'ticker' => 'COST',
            'last_setup_score' => 3,
            'entry_price' => 929.94,
            'latest_atr_14' => 20.72,
            'latest_sma_20' => 939.52,
            'sector_etf' => 'XLY',
            'target_1_rr' => 2.0,
            'signal_high' => 945.00,
            'latest_open_price' => 938.75,
            'latest_close_price' => 935.80,
            'sma_20_ten_days_ago' => 960.67,
            'latest_sma_50' => 976.24,
            'scout_rsi' => 45.64,
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 2.13,
        ]);

        $result = $this->service->allocate($user, [$position], SmartAllocationService::MODE_SMART);

        $this->assertCount(1, $result['allocations']);
        $this->assertSame('COST', $result['allocations'][0]['ticker']);
        $this->assertSame(10, $result['allocations'][0]['score']);
        $this->assertSame([], $result['exclusions']);
    }

    public function test_short_positions_use_separate_risk_pie(): void
    {
        $user = User::factory()->create([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1.5,
            'default_short_risk_percent' => 1.0,
            'is_short_enabled' => true,
        ]);

        $long = $this->scout($user, 'LONG', score: 10, sector: 'XLK');
        $short = Position::factory()->for($user)->scout()->short()->create([
            'ticker' => 'SHRT',
            'last_setup_score' => 10,
            'entry_price' => 100.00,
            'latest_sma_20' => 102.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'target_1_rr' => 2.0,
        ]);

        $result = $this->service->allocate($user, [$long, $short], SmartAllocationService::MODE_EQUAL);

        $this->assertEqualsWithDelta(1.5, $result['pie_breakdown']['long']['percent'], 0.001);
        $this->assertEqualsWithDelta(1.0, $result['pie_breakdown']['short']['percent'], 0.001);
        $this->assertEqualsWithDelta(150.0, $result['pie_breakdown']['long']['total'], 0.01);
        $this->assertEqualsWithDelta(100.0, $result['pie_breakdown']['short']['total'], 0.01);
        $this->assertCount(2, $result['allocations']);

        $byTicker = collect($result['allocations'])->keyBy('ticker');
        $this->assertEqualsWithDelta(150.0, $byTicker['LONG']['risk_dollars'], 0.1);
        $this->assertEqualsWithDelta(100.0, $byTicker['SHRT']['risk_dollars'], 0.1);
    }

    public function test_excludes_setups_that_cannot_reach_min_quantity_of_two(): void
    {
        $user = $this->userWithBankroll(); // pie = $100

        // entry 100, stop ≈ 97 → $3/share; half pie ($50) buys floor(50/3)=16 — OK for EMBJ
        $cheap = $this->scout($user, 'EMBJ', score: 10, sector: 'XLK');

        // entry 900, stop ≈ 870 → ~$30/share; half pie ($50) buys floor(50/30)=1 < 2 → excluded
        $expensive = Position::factory()->for($user)->scout()->create(array_merge(
            $this->scorecardAttributes(10),
            [
                'ticker' => 'COST',
                'last_setup_score' => 10,
                'entry_price' => 900.00,
                'latest_sma_20' => 880.00,
                'latest_atr_14' => 20.00,
                'sector_etf' => 'XLY',
                'target_1_rr' => 2.0,
            ],
        ));

        $result = $this->service->allocate($user, [$cheap, $expensive], SmartAllocationService::MODE_EQUAL);

        $tickers = collect($result['allocations'])->pluck('ticker')->all();
        $this->assertContains('EMBJ', $tickers);
        $this->assertNotContains('COST', $tickers);
        $this->assertGreaterThanOrEqual(2, $result['allocations'][0]['quantity']);

        $exclusion = collect($result['exclusions'])->firstWhere('ticker', 'COST');
        $this->assertNotNull($exclusion);
        $this->assertStringContainsString('Min. 2 aandelen', $exclusion['reason']);
    }

    private function userWithBankroll(): User
    {
        return User::factory()->create([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);
    }

    private function scout(
        User $user,
        string $ticker,
        int $score,
        ?string $sector,
        float $target1Rr = 2.0,
    ): Position {
        return Position::factory()->for($user)->scout()->create(array_merge(
            $this->scorecardAttributes($score),
            [
                'ticker' => $ticker,
                'last_setup_score' => $score,
                'entry_price' => 100.00,
                'latest_atr_14' => 2.00,
                'sector_etf' => $sector,
                'target_1_rr' => $target1Rr,
                'quantity' => 10,
            ],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function scorecardAttributes(int $score): array
    {
        if ($score < 5) {
            return [];
        }

        $base = [
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
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
        ];

        return match (true) {
            $score >= 10 => $base,
            $score === 9 => array_merge($base, ['pre_bounce_extension_atr' => 1.0]),
            $score === 8 => array_merge($base, [
                'pre_bounce_extension_atr' => 1.0,
                'scout_rsi' => 60.00,
            ]),
            $score === 7 => array_merge($base, [
                'sector_trend_positive' => false,
                'pre_bounce_extension_atr' => 1.0,
            ]),
            default => [
                'signal_low' => 100.50,
                'latest_open_price' => 102.00,
                'latest_close_price' => 100.50,
                'latest_sma_20' => 100.00,
                'sma_20_five_days_ago' => 99.50,
                'sma_20_ten_days_ago' => 98.00,
                'latest_sma_50' => 98.00,
                'scout_rsi' => 50.00,
                'bounce_volume_above_average' => false,
                'relative_volume' => 0.82,
                'bounce_day_volume' => 6_000_000,
                'volume_sma_20' => 10_000_000,
                'sector_trend_positive' => false,
                'pre_bounce_extension_atr' => 1.0,
            ],
        };
    }
}
