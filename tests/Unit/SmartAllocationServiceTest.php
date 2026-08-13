<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Enums\BrokerOrderStatus;
use App\Enums\TradeDirection;
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
        $b = $this->scout($user, 'BBB', score: 7, sector: 'XLK');
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
        $coo = $this->scout($user, 'COO', score: 7, sector: 'XLY');

        $result = $this->service->allocate($user, [$rprx, $ewtx, $coo], SmartAllocationService::MODE_SMART);

        $byTicker = collect($result['allocations'])->keyBy('ticker');

        $this->assertEqualsWithDelta(10 / 25, $byTicker['RPRX']['weight_share'], 0.001);
        $this->assertEqualsWithDelta(8 / 25, $byTicker['EWTX']['weight_share'], 0.001);
        $this->assertEqualsWithDelta(7 / 25, $byTicker['COO']['weight_share'], 0.001);
        $this->assertEqualsWithDelta(40.0, $byTicker['RPRX']['risk_dollars'], 0.1);
        $this->assertEqualsWithDelta(32.0, $byTicker['EWTX']['risk_dollars'], 0.1);
        $this->assertEqualsWithDelta(28.0, $byTicker['COO']['risk_dollars'], 0.1);
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

    public function test_open_short_risk_on_does_not_seed_penalty_on_long_scout_same_sector(): void
    {
        config(['vestix.portfolio_coach.max_risk_on_per_sector' => 2]);

        $user = $this->userWithBankroll();
        $user->update(['is_short_enabled' => true]);

        Position::factory()->for($user)->create([
            'ticker' => 'PONY',
            'status' => 'open',
            'direction' => TradeDirection::Short,
            'sector_etf' => 'XLK',
            'entry_price' => 100.00,
            'current_sl' => 105.00,
            'quantity' => 10,
            'latest_close_price' => 100.00,
        ]);

        $embj = $this->scout($user, 'EMBJ', score: 8, sector: 'XLK');
        $aapl = $this->scout($user, 'AAPL', score: 8, sector: 'XLF');

        $result = $this->service->allocate($user, [$embj, $aapl], SmartAllocationService::MODE_SMART);
        $byTicker = collect($result['allocations'])->keyBy('ticker');

        $this->assertArrayHasKey('EMBJ', $byTicker->all());
        $this->assertEqualsWithDelta(0.0, $byTicker['EMBJ']['sector_penalty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $byTicker['AAPL']['sector_penalty'], 0.001);
    }

    public function test_open_long_allows_short_scout_same_sector_in_allocation(): void
    {
        $user = $this->userWithBankroll();
        $user->update([
            'is_short_enabled' => true,
            'default_short_risk_percent' => 1.0,
        ]);

        Position::factory()->for($user)->create([
            'ticker' => 'EMBJ',
            'status' => 'open',
            'direction' => TradeDirection::Long,
            'sector_etf' => 'XLK',
            'entry_price' => 100.00,
            'current_sl' => 95.00,
            'quantity' => 10,
            'latest_close_price' => 100.00,
        ]);

        $pony = Position::factory()->for($user)->scout()->short()->create([
            'ticker' => 'PONY',
            'last_setup_score' => 8,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 102.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLK',
            'target_1_rr' => 2.0,
            'quantity' => 10,
        ]);

        $result = $this->service->allocate($user, [$pony], SmartAllocationService::MODE_SMART);

        $this->assertCount(1, $result['allocations']);
        $this->assertSame('PONY', $result['allocations'][0]['ticker']);
        $this->assertSame([], $result['exclusions']);
    }

    public function test_excludes_score_below_min(): void
    {
        $user = $this->userWithBankroll();
        $strong = $this->scout($user, 'GOOD', score: 8, sector: 'XLK');
        $weak = $this->scout($user, 'WEAK', score: 6, sector: 'XLV');

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

    public function test_excludes_buy_stop_already_through_the_tape(): void
    {
        $user = $this->userWithBankroll();
        $ok = $this->scout($user, 'OK', score: 8, sector: 'XLK');
        $through = Position::factory()->for($user)->scout()->create([
            'ticker' => 'BRK.B',
            'last_setup_score' => 8,
            'entry_price' => 499.62,
            'signal_high' => 498.50,
            'signal_low' => 493.00,
            'latest_atr_14' => 11.20,
            'latest_close_price' => 510.00,
            'latest_sma_20' => 506.57,
            'scout_rsi' => null,
            'sector_etf' => 'XLF',
        ]);

        $result = $this->service->allocate($user, [$ok, $through], SmartAllocationService::MODE_SMART);

        $this->assertSame(['OK'], collect($result['allocations'])->pluck('ticker')->all());
        $this->assertSame('BRK.B', $result['exclusions'][0]['ticker']);
        $this->assertStringContainsString('Buy-stop al door de koers', $result['exclusions'][0]['reason']);
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

    public function test_total_investment_is_capped_by_deployable_cash(): void
    {
        $user = User::factory()->create([
            'trading_bankroll' => 10000,
            'ibkr_net_liquidation' => 10000,
            'ibkr_available_funds' => 1000,
            'ibkr_settled_cash' => 1000,
            'ibkr_last_success_at' => now(),
            'ibkr_data_stale' => false,
            'default_risk_percent' => 1,
        ]);

        // Tight stop → risk pie buys far more notional than deployable cash allows.
        $expensive = Position::factory()->for($user)->scout()->create(array_merge(
            $this->scorecardAttributes(10),
            [
                'ticker' => 'CASH',
                'last_setup_score' => 10,
                'entry_price' => 100.00,
                'signal_low' => 99.95,
                'latest_sma_20' => 99.90,
                'latest_atr_14' => 0.05,
                'sector_etf' => 'XLK',
                'target_1_rr' => 2.0,
                'quantity' => null,
            ],
        ));

        $result = $this->service->allocate($user, [$expensive], SmartAllocationService::MODE_SMART);

        $this->assertTrue($result['cash_capped']);
        $this->assertEqualsWithDelta(1000.0, $result['cash_available'], 0.01);
        $this->assertCount(1, $result['allocations']);
        $this->assertLessThanOrEqual(1000.01, $result['allocations'][0]['investment']);
        $this->assertGreaterThanOrEqual(2, $result['allocations'][0]['quantity']);
    }

    public function test_cash_cap_shrinks_lower_score_so_higher_score_keeps_min_quantity(): void
    {
        $user = User::factory()->create([
            'trading_bankroll' => 5265.33,
            'ibkr_net_liquidation' => 5265.33,
            'ibkr_available_funds' => 5265.16,
            'ibkr_settled_cash' => 5265.16,
            'ibkr_last_success_at' => now(),
            'ibkr_data_stale' => false,
            'default_risk_percent' => 1.5,
        ]);

        // Tight stop → risk pie buys a large BRK.B notional that crowds out cash for SBLK.
        $brk = $this->pricedScout(
            $user,
            ticker: 'BRK.B',
            score: 8,
            sector: 'XLF',
            entry: 499.62,
            signalLow: 497.82,
            atr: 2.00,
        );
        $sblk = $this->pricedScout(
            $user,
            ticker: 'SBLK',
            score: 10,
            sector: 'XLI',
            entry: 80.00,
            signalLow: 63.00,
            atr: 10.00,
        );

        $result = $this->service->allocate($user, [$brk, $sblk], SmartAllocationService::MODE_SMART);
        $byTicker = collect($result['allocations'])->keyBy('ticker');

        $this->assertArrayHasKey('SBLK', $byTicker->all());
        $this->assertArrayHasKey('BRK.B', $byTicker->all());
        $this->assertGreaterThanOrEqual(2, $byTicker['SBLK']['quantity']);
        $this->assertGreaterThanOrEqual(2, $byTicker['BRK.B']['quantity']);
        $this->assertLessThanOrEqual(5265.17, (float) collect($result['allocations'])->sum('investment'));
        $this->assertNull(collect($result['exclusions'])->firstWhere('ticker', 'SBLK'));
        $this->assertFalse($sblk->fresh()->isOrderPlanExcludedToday());
    }

    public function test_sticky_higher_score_is_retried_when_lower_score_can_shrink(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 10:00:00', 'Europe/Amsterdam'));

        $user = User::factory()->create([
            'trading_bankroll' => 5265.33,
            'ibkr_net_liquidation' => 5265.33,
            'ibkr_available_funds' => 5265.16,
            'ibkr_settled_cash' => 5265.16,
            'ibkr_last_success_at' => now(),
            'ibkr_data_stale' => false,
            'default_risk_percent' => 1.5,
        ]);

        $brk = $this->pricedScout(
            $user,
            ticker: 'BRK.B',
            score: 8,
            sector: 'XLF',
            entry: 499.62,
            signalLow: 493.62,
            atr: 5.00,
        );
        $sblk = $this->pricedScout(
            $user,
            ticker: 'SBLK',
            score: 10,
            sector: 'XLI',
            entry: 18.50,
            signalLow: 17.40,
            atr: 1.00,
        );
        $sblk->update(['order_plan_excluded_on' => '2026-08-13']);

        $result = $this->service->allocate($user, [$brk, $sblk], SmartAllocationService::MODE_SMART);
        $tickers = collect($result['allocations'])->pluck('ticker')->all();

        $this->assertContains('SBLK', $tickers);
        $this->assertContains('BRK.B', $tickers);
        $this->assertGreaterThanOrEqual(2, collect($result['allocations'])->firstWhere('ticker', 'SBLK')['quantity']);
        $this->assertNull($sblk->fresh()->order_plan_excluded_on);
        $this->assertFalse(collect($result['exclusions'])->contains(
            fn (array $exclusion): bool => $exclusion['ticker'] === 'SBLK'
                && str_contains($exclusion['reason'], 'niet opnieuw verdeeld'),
        ));
    }

    public function test_no_trade_hard_fail_does_not_crowd_out_live_a_setup(): void
    {
        $user = $this->userWithBankroll();

        $cor = Position::factory()->for($user)->scout()->create(array_merge(
            $this->scorecardAttributes(10),
            [
                'ticker' => 'COR',
                'last_setup_score' => 9,
                'last_setup_grade' => 'A',
                'entry_price' => 100.00,
                'latest_atr_14' => 2.00,
                'sector_etf' => 'XLV',
                'target_1_rr' => 2.0,
                'scout_rsi' => 76.00,
                'quantity' => 10,
            ],
        ));
        $this->assertSame('NO TRADE', $cor->evaluateSetupScore()['grade']);

        $sblk = $this->scout($user, 'SBLK', score: 9, sector: 'XLI');
        $brk = $this->scout($user, 'BRK.B', score: 8, sector: 'XLF');

        $result = $this->service->allocate($user, [$cor, $sblk, $brk], SmartAllocationService::MODE_SMART);
        $tickers = collect($result['allocations'])->pluck('ticker')->sort()->values()->all();

        $this->assertSame(['BRK.B', 'SBLK'], $tickers);
        $this->assertGreaterThanOrEqual(2, collect($result['allocations'])->firstWhere('ticker', 'SBLK')['quantity']);
        $this->assertSame('COR', collect($result['exclusions'])->firstWhere('ticker', 'COR')['ticker']);
        $this->assertStringContainsString('NO TRADE', collect($result['exclusions'])->firstWhere('ticker', 'COR')['reason']);
        $this->assertFalse($sblk->fresh()->isOrderPlanExcludedToday());
    }

    public function test_sticky_a_setup_is_retried_when_no_trade_is_dropped_from_pie(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 10:00:00', 'Europe/Amsterdam'));

        $user = $this->userWithBankroll();

        $cor = Position::factory()->for($user)->scout()->create(array_merge(
            $this->scorecardAttributes(10),
            [
                'ticker' => 'COR',
                'last_setup_score' => 9,
                'last_setup_grade' => 'A',
                'entry_price' => 100.00,
                'latest_atr_14' => 2.00,
                'sector_etf' => 'XLV',
                'target_1_rr' => 2.0,
                'scout_rsi' => 76.00,
                'quantity' => 10,
            ],
        ));
        $sblk = $this->scout($user, 'SBLK', score: 9, sector: 'XLI');
        $sblk->update(['order_plan_excluded_on' => '2026-08-13']);
        $brk = $this->scout($user, 'BRK.B', score: 8, sector: 'XLF');
        $ecl = $this->scout($user, 'ECL', score: 8, sector: 'XLB');

        $result = $this->service->allocate($user, [$cor, $sblk, $brk, $ecl], SmartAllocationService::MODE_SMART);
        $tickers = collect($result['allocations'])->pluck('ticker')->sort()->values()->all();

        $this->assertContains('SBLK', $tickers);
        $this->assertNotContains('COR', $tickers);
        $this->assertGreaterThanOrEqual(2, collect($result['allocations'])->firstWhere('ticker', 'SBLK')['quantity']);
        $this->assertNull($sblk->fresh()->order_plan_excluded_on);
        $this->assertStringContainsString('NO TRADE', collect($result['exclusions'])->firstWhere('ticker', 'COR')['reason']);
        $this->assertFalse(collect($result['exclusions'])->contains(
            fn (array $exclusion): bool => $exclusion['ticker'] === 'SBLK'
                && str_contains($exclusion['reason'], 'niet opnieuw verdeeld'),
        ));
    }

    public function test_persisted_no_trade_grade_is_excluded_when_live_scorecard_incomplete(): void
    {
        $user = $this->userWithBankroll();

        $cor = $this->pricedScout(
            $user,
            ticker: 'COR',
            score: 9,
            sector: 'XLV',
            entry: 100.00,
            signalLow: 97.00,
            atr: 2.00,
        );
        $cor->update(['last_setup_grade' => 'NO TRADE']);
        $sblk = $this->scout($user, 'SBLK', score: 9, sector: 'XLI');

        $result = $this->service->allocate($user, [$cor, $sblk], SmartAllocationService::MODE_SMART);

        $this->assertSame(['SBLK'], collect($result['allocations'])->pluck('ticker')->all());
        $this->assertStringContainsString('NO TRADE', collect($result['exclusions'])->firstWhere('ticker', 'COR')['reason']);
    }

    public function test_cash_cap_keeps_higher_score_when_both_min_lots_cannot_fit(): void
    {
        $user = User::factory()->create([
            'trading_bankroll' => 5265.33,
            'ibkr_net_liquidation' => 5265.33,
            'ibkr_available_funds' => 1100.00,
            'ibkr_settled_cash' => 1100.00,
            'ibkr_last_success_at' => now(),
            'ibkr_data_stale' => false,
            'default_risk_percent' => 1.5,
        ]);

        $brk = $this->pricedScout(
            $user,
            ticker: 'BRK.B',
            score: 8,
            sector: 'XLF',
            entry: 499.62,
            signalLow: 497.82,
            atr: 2.00,
        );
        $sblk = $this->pricedScout(
            $user,
            ticker: 'SBLK',
            score: 10,
            sector: 'XLI',
            entry: 80.00,
            signalLow: 79.50,
            atr: 1.00,
        );

        $result = $this->service->allocate($user, [$brk, $sblk], SmartAllocationService::MODE_SMART);
        $tickers = collect($result['allocations'])->pluck('ticker')->all();

        $this->assertSame(['SBLK'], $tickers);
        $this->assertGreaterThanOrEqual(2, $result['allocations'][0]['quantity']);
        $this->assertLessThanOrEqual(1100.01, $result['allocations'][0]['investment']);
        $this->assertSame('BRK.B', $result['exclusions'][0]['ticker']);
        $this->assertStringContainsString('deployable cash', $result['exclusions'][0]['reason']);
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
            'latest_close_price' => 245.40,
            'latest_sma_20' => 240.00,
            'latest_atr_14' => 4.00,
            'sector_etf' => 'XLF',
            'target_1_rr' => 2.0,
        ]);

        $lly = Position::factory()->for($user)->scout()->create([
            'ticker' => 'LLY',
            'last_setup_score' => 8,
            'entry_price' => 1192.89,
            'latest_close_price' => 1192.89,
            'latest_sma_20' => 1171.00,
            'latest_atr_14' => 20.00,
            'sector_etf' => 'XLV',
            'target_1_rr' => 2.0,
        ]);

        $kvue = Position::factory()->for($user)->scout()->create([
            'ticker' => 'KVUE',
            'last_setup_score' => 10,
            'entry_price' => 19.14,
            'latest_close_price' => 19.14,
            'latest_sma_20' => 18.50,
            'latest_atr_14' => 0.40,
            'sector_etf' => 'XLP',
            'target_1_rr' => 2.0,
        ]);

        $syy = Position::factory()->for($user)->scout()->create([
            'ticker' => 'SYY',
            'last_setup_score' => 9,
            'entry_price' => 82.91,
            'latest_close_price' => 82.91,
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
            'latest_close_price' => 1192.89,
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
            'latest_close_price' => 100.00,
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
            'latest_close_price' => 100.00,
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
            'sma_20_five_days_ago' => 950.00,
            'sma_20_ten_days_ago' => 960.67,
            'latest_sma_50' => 976.24,
            'scout_rsi' => 45.64,
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 2.13,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
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

        // Tight structure stops so risk-pie sizing implies more notional than cash.
        $long = Position::factory()->for($user)->scout()->create(array_merge(
            $this->scorecardAttributes(10),
            [
                'ticker' => 'LONG',
                'last_setup_score' => 10,
                'entry_price' => 100.00,
                'signal_low' => 99.90,
                'latest_atr_14' => 0.10,
                'sector_etf' => 'XLK',
                'target_1_rr' => 2.0,
            ],
        ));
        $short = Position::factory()->for($user)->scout()->short()->create(array_merge(
            $this->shortScorecardAttributes(10),
            [
                'ticker' => 'SHRT',
                'last_setup_score' => 10,
                'entry_price' => 100.00,
                'latest_atr_14' => 0.10,
                'sector_etf' => 'XLF',
                'target_1_rr' => 2.0,
            ],
        ));

        $result = $this->service->allocate($user, [$long, $short], SmartAllocationService::MODE_EQUAL);

        $this->assertEqualsWithDelta(1.5, $result['pie_breakdown']['long']['percent'], 0.001);
        $this->assertEqualsWithDelta(1.0, $result['pie_breakdown']['short']['percent'], 0.001);
        $this->assertEqualsWithDelta(150.0, $result['pie_breakdown']['long']['total'], 0.01);
        $this->assertEqualsWithDelta(100.0, $result['pie_breakdown']['short']['total'], 0.01);
        $this->assertCount(2, $result['allocations']);

        // Risk pies can imply more notional than deployable cash — quantities are cash-capped.
        $totalInvestment = (float) collect($result['allocations'])->sum('investment');
        $this->assertLessThanOrEqual(10000.01, $totalInvestment);
        $this->assertTrue($result['cash_capped']);

        $byTicker = collect($result['allocations'])->keyBy('ticker');
        $this->assertArrayHasKey('LONG', $byTicker->all());
        $this->assertArrayHasKey('SHRT', $byTicker->all());
        $this->assertGreaterThan(0, $byTicker['LONG']['risk_dollars']);
        $this->assertGreaterThan(0, $byTicker['SHRT']['risk_dollars']);
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
                'signal_low' => 852.00,
                'latest_sma_20' => 898.00,
                'latest_open_price' => 899.00,
                'latest_close_price' => 900.00,
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

    private function pricedScout(
        User $user,
        string $ticker,
        int $score,
        string $sector,
        float $entry,
        float $signalLow,
        float $atr,
    ): Position {
        return Position::factory()->for($user)->scout()->create([
            'ticker' => $ticker,
            'last_setup_score' => $score,
            'entry_price' => $entry,
            'signal_low' => $signalLow,
            'latest_atr_14' => $atr,
            'latest_sma_20' => $entry,
            'latest_close_price' => $entry,
            'sector_etf' => $sector,
            'target_1_rr' => 2.0,
            'scout_rsi' => null,
            'quantity' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shortScorecardAttributes(int $score): array
    {
        $base = [
            // signal_high must sit above entry (100) so structure SL is a valid short stop.
            'signal_high' => 103.00,
            'latest_open_price' => 101.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 102.00,
            'sma_20_five_days_ago' => 104.00,
            'sma_20_ten_days_ago' => 106.00,
            'latest_sma_50' => 110.00,
            'scout_rsi' => 45.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 2.50,
        ];

        return match (true) {
            $score >= 10 => $base,
            $score === 9 => array_merge($base, ['pre_bounce_extension_atr' => 1.0]),
            $score === 8 => array_merge($base, [
                'pre_bounce_extension_atr' => 1.0,
                'scout_rsi' => 60.00,
            ]),
            default => array_merge($base, [
                'relative_volume' => 0.82,
                'bounce_volume_above_average' => false,
                'pre_bounce_extension_atr' => 1.0,
            ]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function scorecardAttributes(int $score): array
    {
        if ($score < 6) {
            return [];
        }

        $base = [
            // signal_low must sit below entry (100) so structure SL is a valid long stop.
            // Close stays at/under the buy-stop so Smart Sizing does not treat it as through the tape.
            'signal_low' => 97.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 100.00,
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
            // Score 6: green candle but RVol under threshold + weak sector/extension.
            default => array_merge($base, [
                'bounce_volume_above_average' => false,
                'relative_volume' => 0.82,
                'bounce_day_volume' => 6_000_000,
                'volume_sma_20' => 10_000_000,
                'sector_trend_positive' => false,
                'pre_bounce_extension_atr' => 1.0,
            ]),
        };
    }
}
