<?php

namespace Tests\Unit;

use App\Enums\BrokerOrderStatus;
use App\Enums\EarningsReleaseHour;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Asset;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScoutActivationEarningsOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_quarantine_does_not_disable_activation_but_requires_confirmation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05', 'Europe/Amsterdam'));
        config(['vestix.earnings_quarantine.trading_days' => 2]);

        $position = $this->makeScout(
            lastEarnings: '2026-08-04',
            nextEarnings: '2026-11-04',
            brokerStatus: BrokerOrderStatus::Scout,
        );

        $this->assertTrue($position->isInEarningsEntryQuarantine());
        $this->assertFalse(PositionRecordActions::scoutActivationDisabled($position));
        $this->assertTrue(PositionRecordActions::scoutEarningsOverrideRequired($position));
        $this->assertTrue(PositionRecordActions::scoutEarningsGateBlocks($position));

        $tooltip = PositionRecordActions::scoutActivationTooltip($position);
        $this->assertStringContainsString('Earnings-risico', $tooltip);
        $this->assertStringNotContainsString('Promotie geblokkeerd', $tooltip);
    }

    public function test_pending_buy_stop_also_stays_enabled_during_quarantine(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05', 'Europe/Amsterdam'));
        config(['vestix.earnings_quarantine.trading_days' => 2]);

        $position = $this->makeScout(
            lastEarnings: '2026-08-04',
            nextEarnings: '2026-11-04',
            brokerStatus: BrokerOrderStatus::Pending,
        );

        $this->assertFalse(PositionRecordActions::scoutActivationDisabled($position));
        $this->assertTrue(PositionRecordActions::scoutEarningsOverrideRequired($position));
    }

    public function test_fourteen_day_runway_requires_confirmation_without_hard_disable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));
        config(['vestix.earnings_quarantine.trading_days' => 2]);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'AAPL',
            'next_earnings_date' => '2026-03-09',
        ]);

        $position = Position::factory()->create([
            'ticker' => 'AAPL',
            'asset_id' => $asset->id,
            'status' => 'scout',
            'broker_order_status' => BrokerOrderStatus::Scout,
        ]);

        $this->assertFalse($position->isInEarningsEntryQuarantine());
        $this->assertTrue(PositionRecordActions::scoutEarningsGateBlocks($position));
        $this->assertFalse(PositionRecordActions::scoutActivationDisabled($position));
        $this->assertTrue(PositionRecordActions::scoutEarningsOverrideRequired($position));
        $this->assertStringContainsString('runway', PositionRecordActions::scoutActivationTooltip($position));
        $this->assertStringNotContainsString('Promotie geblokkeerd', PositionRecordActions::scoutActivationTooltip($position));
    }

    private function makeScout(
        string $lastEarnings,
        string $nextEarnings,
        BrokerOrderStatus $brokerStatus,
    ): Position {
        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'EC',
            'last_earnings_date' => $lastEarnings,
            'last_earnings_hour' => EarningsReleaseHour::Amc,
            'next_earnings_date' => $nextEarnings,
        ]);

        return Position::factory()->create([
            'ticker' => 'EC',
            'asset_id' => $asset->id,
            'status' => 'scout',
            'broker_order_status' => $brokerStatus,
            'buy_stop_review_required_on' => null,
        ]);
    }
}
