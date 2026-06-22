<?php

namespace Tests\Feature\Console;

use App\Contracts\QuoteProvider;
use App\Enums\PremarketScanResult;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class RunPreMarketGatekeeperTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_skips_outside_gatekeeper_window_without_force(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'Europe/Amsterdam'));

        $this->artisan('vestix:premarket-gatekeeper')
            ->expectsOutputToContain('Buiten gatekeeper-venster')
            ->assertSuccessful();
    }

    public function test_command_warns_when_watchlist_is_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'Europe/Amsterdam'));

        $this->artisan('vestix:premarket-gatekeeper --force')
            ->expectsOutputToContain('Scouts op watchlist: 0')
            ->expectsOutputToContain('Geen scouts op de watchlist')
            ->assertSuccessful();
    }

    public function test_command_runs_with_force_flag(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'Europe/Amsterdam'));

        $position = Position::factory()->scout()->create([
            'entry_price' => 50.00,
            'signal_high' => 49.00,
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with($position->ticker)
            ->andReturn(50.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $this->artisan('vestix:premarket-gatekeeper --force')
            ->expectsOutputToContain('Pre-Market Gatekeeper gestart')
            ->expectsOutputToContain('Verwachte API-calls: 1')
            ->expectsOutputToContain('Geschatte duur: ~13s')
            ->expectsOutputToContain('Voltooid in')
            ->assertSuccessful();

        $position->refresh();
        $this->assertSame(PremarketScanResult::GapRisk, $position->premarket_scan_type);
    }
}
