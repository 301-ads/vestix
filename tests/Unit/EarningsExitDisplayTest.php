<?php

namespace Tests\Unit;

use App\Enums\EarningsReleaseHour;
use App\Models\Asset;
use App\Models\Position;
use App\Support\EarningsExitDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EarningsExitDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cockpit_card_shows_countdown_and_exit_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05', 'Europe/Amsterdam'));

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'AAPL',
            'next_earnings_date' => '2026-03-09',
            'next_earnings_hour' => EarningsReleaseHour::Amc,
        ]);

        $position = Position::factory()->create([
            'ticker' => 'AAPL',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $this->assertTrue(EarningsExitDisplay::isRelevant($position));

        $card = EarningsExitDisplay::cockpitCardData($position);

        $this->assertSame('Earnings', $card['label']);
        $this->assertSame('4 dagen', $card['value']);
        $this->assertStringContainsString('AMC', $card['description']);
        $this->assertStringContainsString('slotbel', $card['description']);
    }

    public function test_cockpit_card_hidden_when_earnings_beyond_alert_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'Europe/Amsterdam'));

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'AAPL',
            'next_earnings_date' => '2026-03-20',
        ]);

        $position = Position::factory()->create([
            'ticker' => 'AAPL',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $this->assertFalse(EarningsExitDisplay::isRelevant($position));
        $this->assertFalse(EarningsExitDisplay::isSmartAlertVisible($position, 'edit'));
    }

    public function test_smart_alert_visible_within_fourteen_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05', 'Europe/Amsterdam'));

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'AAPL',
            'next_earnings_date' => '2026-03-09',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        $position = Position::factory()->create([
            'ticker' => 'AAPL',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $this->assertTrue(EarningsExitDisplay::isSmartAlertVisible($position, 'edit'));

        $html = EarningsExitDisplay::smartAlertContent($position)->toHtml();

        $this->assertStringContainsString('Let op: Earnings report over 4 dagen!', $html);
        $this->assertStringContainsString('Voor beurs (BMO)', $html);
        $this->assertStringContainsString('text-amber-500', $html);
    }

    public function test_smart_alert_uses_danger_styling_within_three_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-08', 'Europe/Amsterdam'));

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'AAPL',
            'next_earnings_date' => '2026-03-09',
            'next_earnings_hour' => EarningsReleaseHour::Amc,
        ]);

        $position = Position::factory()->create([
            'ticker' => 'AAPL',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $html = EarningsExitDisplay::smartAlertContent($position)->toHtml();

        $this->assertStringContainsString('text-rose-500', $html);
        $this->assertStringContainsString('1 dag', $html);
    }

    public function test_sync_status_shows_finnhub_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22', 'Europe/Amsterdam'));

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'SNDK',
            'next_earnings_date' => '2026-08-12',
            'next_earnings_hour' => EarningsReleaseHour::Unknown,
            'earnings_fetched_at' => now(),
        ]);

        $position = Position::factory()->create([
            'ticker' => 'SNDK',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $html = EarningsExitDisplay::syncStatusContent($position)->toHtml();

        $this->assertStringContainsString('12 aug. 2026', $html);
        $this->assertStringContainsString('Finnhub', $html);
    }

    public function test_section_days_badge_label_for_known_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22', 'Europe/Amsterdam'));

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'SNDK',
            'next_earnings_date' => '2026-08-12',
        ]);

        $position = Position::factory()->create([
            'ticker' => 'SNDK',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $this->assertSame('Over 51 dagen', EarningsExitDisplay::sectionDaysBadgeLabel($position));
        $this->assertSame('gray', EarningsExitDisplay::sectionDaysBadgeColor($position));
    }
}
