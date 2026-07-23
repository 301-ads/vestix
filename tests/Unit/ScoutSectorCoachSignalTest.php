<?php

namespace Tests\Unit;

use App\Enums\TradeDirection;
use App\Models\Position;
use App\Models\User;
use App\Support\ScoutSectorCoachSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoutSectorCoachSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ScoutSectorCoachSignal::clearCache();
    }

    public function test_interesting_when_meewind_and_risk_on_slot_free(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'sector_etf' => 'XLF',
            'direction' => TradeDirection::Long,
            'sector_trend_positive' => true,
        ]);

        $signal = ScoutSectorCoachSignal::for($user, $scout);

        $this->assertSame('interesting', $signal['state']);
        $this->assertSame(ScoutSectorCoachSignal::ICON, $signal['icon']);
        $this->assertSame('success', $signal['color']);
        $this->assertStringContainsString('Meewind', $signal['tooltip']);
        $this->assertStringContainsString('XLF', $signal['tooltip']);
        $this->assertStringContainsString('long', $signal['tooltip']);
    }

    public function test_full_when_risk_on_slot_occupied_same_sector_and_direction(): void
    {
        $user = User::factory()->create();
        $this->openRiskOn($user, 'BAC', 'XLF', TradeDirection::Long);

        $scout = Position::factory()->for($user)->scout()->create([
            'sector_etf' => 'XLF',
            'direction' => TradeDirection::Long,
            'sector_trend_positive' => true,
        ]);

        $signal = ScoutSectorCoachSignal::for($user, $scout);

        $this->assertSame('full', $signal['state']);
        $this->assertSame('warning', $signal['color']);
        $this->assertStringContainsString('vol', $signal['tooltip']);
        $this->assertStringContainsString('correlatierisico', $signal['tooltip']);
    }

    public function test_full_wins_over_interesting(): void
    {
        $user = User::factory()->create();
        $this->openRiskOn($user, 'BAC', 'XLF', TradeDirection::Long);

        $scout = Position::factory()->for($user)->scout()->create([
            'sector_etf' => 'XLF',
            'direction' => TradeDirection::Long,
            'sector_trend_positive' => true,
        ]);

        $this->assertSame('full', ScoutSectorCoachSignal::for($user, $scout)['state']);
    }

    public function test_none_when_tegenwind(): void
    {
        $user = User::factory()->create();

        $tegenwind = Position::factory()->for($user)->scout()->create([
            'sector_etf' => 'XLK',
            'direction' => TradeDirection::Long,
            'sector_trend_positive' => false,
        ]);

        $this->assertNull(ScoutSectorCoachSignal::for($user, $tegenwind));
        $this->assertSame('gray', ScoutSectorCoachSignal::color($user, $tegenwind));
        $this->assertNull(ScoutSectorCoachSignal::icon($user, $tegenwind));
    }

    public function test_short_interesting_when_bearish_sector_and_slot_free(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        $scout = Position::factory()->for($user)->scout()->create([
            'sector_etf' => 'XLK',
            'direction' => TradeDirection::Short,
            'sector_trend_positive' => false,
        ]);

        $signal = ScoutSectorCoachSignal::for($user, $scout);

        $this->assertSame('interesting', $signal['state']);
        $this->assertStringContainsString('short', $signal['tooltip']);
    }

    public function test_locked_position_does_not_mark_sector_full(): void
    {
        $user = User::factory()->create();
        $this->openLocked($user, 'BAC', 'XLF', TradeDirection::Long);

        $scout = Position::factory()->for($user)->scout()->create([
            'sector_etf' => 'XLF',
            'direction' => TradeDirection::Long,
            'sector_trend_positive' => true,
        ]);

        $this->assertSame('interesting', ScoutSectorCoachSignal::for($user, $scout)['state']);
    }

    public function test_open_long_does_not_mark_short_scout_full(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);
        $this->openRiskOn($user, 'EMBJ', 'XLK', TradeDirection::Long);

        $scout = Position::factory()->for($user)->scout()->create([
            'sector_etf' => 'XLK',
            'direction' => TradeDirection::Short,
            'sector_trend_positive' => false,
        ]);

        $this->assertSame('interesting', ScoutSectorCoachSignal::for($user, $scout)['state']);
    }

    public function test_none_without_sector_or_user(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->for($user)->scout()->create([
            'sector_etf' => null,
            'sector_trend_positive' => true,
        ]);

        $this->assertNull(ScoutSectorCoachSignal::for($user, $scout));
        $this->assertNull(ScoutSectorCoachSignal::for(null, $scout));
    }

    private function openRiskOn(User $user, string $ticker, string $sector, TradeDirection $direction): Position
    {
        $entry = 100.0;
        $sl = $direction === TradeDirection::Short ? 105.0 : 95.0;

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

    private function openLocked(User $user, string $ticker, string $sector, TradeDirection $direction): Position
    {
        $entry = 100.0;
        $sl = $direction === TradeDirection::Short ? 95.0 : 105.0;

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
}
