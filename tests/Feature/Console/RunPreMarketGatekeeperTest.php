<?php

namespace Tests\Feature\Console;

use App\Contracts\QuoteProvider;
use App\Enums\PremarketGapStatus;
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

    public function test_command_runs_with_force_flag(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'Europe/Amsterdam'));

        $position = Position::factory()->scout()->create([
            'entry_price' => 50.00,
            'signal_high' => 49.00,
            'armed_for_entry_on' => '2026-06-15',
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with($position->ticker)
            ->andReturn(60.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $this->artisan('vestix:premarket-gatekeeper --force')
            ->expectsOutputToContain('Pre-Market Gatekeeper gestart')
            ->assertSuccessful();

        $position->refresh();
        $this->assertSame(PremarketGapStatus::GapUp, $position->premarket_gap_status);
    }
}
