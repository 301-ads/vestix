<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Models\Position;
use App\Models\User;
use App\Services\SmartAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SmartAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SmartAllocationService::class);
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
        $this->assertEqualsWithDelta(0.20, $twoByTicker['A1']['sector_penalty'], 0.001);
        $this->assertEqualsWithDelta(0.20, $twoByTicker['A2']['sector_penalty'], 0.001);
        $this->assertEqualsWithDelta(0.0, $twoByTicker['B1']['sector_penalty'], 0.001);

        $three = $this->service->allocate($user, [
            $this->scout($user, 'C1', score: 8, sector: 'XLV'),
            $this->scout($user, 'C2', score: 8, sector: 'XLV'),
            $this->scout($user, 'C3', score: 8, sector: 'XLV'),
        ], SmartAllocationService::MODE_SMART);

        foreach ($three['allocations'] as $allocation) {
            $this->assertEqualsWithDelta(0.40, $allocation['sector_penalty'], 0.001);
        }
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
        $rprx = $this->scout($user, 'RPRX', score: 10, sector: 'XLV');

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

    public function test_sizing_bankroll_excludes_open_revolut_position_value(): void
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

        $this->assertEqualsWithDelta(8000.0, $this->service->resolveSizingBankroll($user->fresh()), 0.01);
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
        return Position::factory()->for($user)->scout()->create([
            'ticker' => $ticker,
            'last_setup_score' => $score,
            'entry_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => $sector,
            'target_1_rr' => $target1Rr,
            'quantity' => 10,
        ]);
    }
}
