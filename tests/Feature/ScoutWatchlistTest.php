<?php

namespace Tests\Feature;

use App\Filament\Resources\Positions\Pages\CreateScout;
use App\Filament\Resources\Positions\Pages\EditScout;
use App\Filament\Resources\Positions\Pages\ListScouts;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Widgets\SetupRadarWidget;
use App\Models\Position;
use App\Services\AlphaVantageService;
use App\Services\MarketDataFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScoutWatchlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_scout_can_be_created_with_ticker_only(): void
    {
        $user = $this->authenticateFilament();

        Livewire::test(CreateScout::class)
            ->fillForm([
                'ticker' => 'APTV',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('positions', [
            'ticker' => 'APTV',
            'status' => 'scout',
            'signal_low' => null,
            'signal_high' => null,
            'entry_price' => null,
            'quantity' => null,
            'current_sl' => null,
        ]);
    }

    public function test_scout_can_be_created_without_sl_or_quantity(): void
    {
        $user = $this->authenticateFilament();

        Livewire::test(CreateScout::class)
            ->fillForm([
                'ticker' => 'NVDA',
                'signal_low' => 118.50,
                'signal_high' => 122.00,
                'trade_journal' => 'A+ bounce setup op 20 SMA.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('positions', [
            'ticker' => 'NVDA',
            'status' => 'scout',
            'entry_price' => null,
            'quantity' => null,
            'current_sl' => null,
        ]);
    }

    public function test_activate_scout_sets_open_status_and_computed_sl(): void
    {
        $scout = Position::factory()->scout()->create([
            'ticker' => 'NVDA',
            'entry_price' => 78.00,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
        ]);

        $scout->activateAsPosition(79.50, 12);

        $scout->refresh();

        $this->assertEquals('open', $scout->status);
        $this->assertEquals(79.50, (float) $scout->entry_price);
        $this->assertEquals(12, (float) $scout->quantity);
        $this->assertEquals(76.10, (float) $scout->current_sl);
    }

    public function test_activate_scout_fails_without_market_data(): void
    {
        $scout = Position::factory()->scout()->create([
            'latest_sma_20' => null,
            'latest_atr_14' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $scout->activateAsPosition(50.00, 10);
    }

    public function test_scouts_are_excluded_from_portfolio_widgets(): void
    {
        Position::factory()->create([
            'ticker' => 'OPEN',
            'entry_price' => 100.00,
            'quantity' => 10,
            'current_sl' => 90.00,
            'latest_close_price' => 110.00,
            'status' => 'open',
        ]);

        Position::factory()->scout()->create([
            'ticker' => 'SCOUT',
            'entry_price' => 200.00,
            'quantity' => 5,
            'latest_close_price' => 210.00,
        ]);

        $openPositions = Position::open()->get();

        $this->assertCount(1, $openPositions);
        $this->assertSame('OPEN', $openPositions->first()->ticker);

        $totalInvested = $openPositions->sum(fn (Position $position) => $position->investment);

        $this->assertEquals(1000.00, $totalInvested);
    }

    public function test_planned_risk_accessors_for_scout(): void
    {
        $scout = Position::factory()->scout()->create([
            'entry_price' => 80.00,
            'quantity' => 10,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
        ]);

        $this->assertEqualsWithDelta(3.90, $scout->planned_risk_per_share, 0.001);
        $this->assertEqualsWithDelta(39.00, $scout->planned_risk_dollars, 0.001);
        $this->assertEqualsWithDelta(4.875, $scout->planned_risk_percentage, 0.001);
    }

    public function test_scout_action_command_is_scout(): void
    {
        $scout = Position::factory()->scout()->create();

        $this->assertSame('SCOUT', $scout->action_command);
    }

    public function test_edit_position_redirects_scouts_to_setup_radar(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create(['ticker' => 'PANW']);

        $this->get(PositionResource::getUrl('edit', ['record' => $scout]))
            ->assertRedirect(ScoutResource::getUrl('edit', ['record' => $scout]));
    }

    public function test_edit_scout_page_shows_scout_hud(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'AAPL',
            'entry_price' => 80.00,
            'quantity' => 10,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
        ]);
        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertOk()
            ->assertSee('Scout')
            ->assertSee('Berekende SL')
            ->assertSee('Risico bij entry')
            ->assertSee('Totale inleg')
            ->assertSee('Activeren')
            ->assertDontSee('Actie / Executie');
    }

    public function test_activate_scout_action_from_list(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 78.00,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
        ]);
        Livewire::test(ListScouts::class)
            ->callTableAction('activate_scout', $scout, data: [
                'entry_price' => 79.00,
                'quantity' => 8,
            ]);

        $scout->refresh();

        $this->assertEquals('open', $scout->status);
        $this->assertEquals(76.10, (float) $scout->current_sl);
        $this->assertEquals(8, (float) $scout->quantity);
    }

    public function test_fetch_market_data_action_updates_scout(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create(['ticker' => 'MSFT']);

        $this->mock(AlphaVantageService::class, function ($mock): void {
            $mock->shouldReceive('fetchGlobalQuote')->once()->with('MSFT')->andReturn([
                'close' => 78.20,
                'high' => 79.00,
                'low' => 76.50,
            ]);
            $mock->shouldReceive('fetchSma20Pair')->once()->with('MSFT')->andReturn([
                'latest' => 77.50,
                'five_days_ago' => 77.00,
            ]);
            $mock->shouldReceive('fetchSma50')->once()->with('MSFT')->andReturn(75.00);
            $mock->shouldReceive('fetchAtr14')->once()->with('MSFT')->andReturn(2.80);
            $mock->shouldReceive('fetchRsi14')->once()->with('MSFT')->andReturn(52.00);
        });
        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertSee('Actuele Koers')
            ->assertSee('—')
            ->callAction('fetch_market_data')
            ->assertFormSet([
                'latest_close_price' => 78.20,
                'latest_sma_20' => 77.50,
                'latest_atr_14' => 2.80,
                'scout_rsi' => 52.00,
            ])
            ->assertSee('$78.20');

        $scout->refresh();

        $this->assertEquals(78.20, (float) $scout->latest_close_price);
        $this->assertEquals(77.50, (float) $scout->latest_sma_20);
        $this->assertEquals(77.00, (float) $scout->sma_20_five_days_ago);
        $this->assertEquals(75.00, (float) $scout->latest_sma_50);
        $this->assertEquals(2.80, (float) $scout->latest_atr_14);
        $this->assertEquals(52.00, (float) $scout->scout_rsi);
    }

    public function test_fetch_market_data_on_create_scout_fills_form_without_saving(): void
    {
        $this->authenticateFilament();

        $this->mock(AlphaVantageService::class, function ($mock): void {
            $mock->shouldReceive('fetchGlobalQuote')->once()->with('MSFT')->andReturn([
                'close' => 78.20,
                'high' => 79.00,
                'low' => 76.50,
            ]);
            $mock->shouldReceive('fetchSma20Pair')->once()->with('MSFT')->andReturn([
                'latest' => 77.50,
                'five_days_ago' => 77.00,
            ]);
            $mock->shouldReceive('fetchSma50')->once()->with('MSFT')->andReturn(75.00);
            $mock->shouldReceive('fetchAtr14')->once()->with('MSFT')->andReturn(2.80);
            $mock->shouldReceive('fetchRsi14')->once()->with('MSFT')->andReturn(52.00);
        });

        Livewire::test(CreateScout::class)
            ->fillForm(['ticker' => 'MSFT'])
            ->callAction('fetch_market_data')
            ->assertFormSet([
                'latest_close_price' => 78.20,
                'latest_sma_20' => 77.50,
                'latest_atr_14' => 2.80,
                'scout_rsi' => 52.00,
            ]);

        $this->assertDatabaseCount('positions', 0);
    }

    public function test_create_scout_page_shows_scorecard(): void
    {
        $this->authenticateFilament();

        Livewire::test(CreateScout::class)
            ->assertOk()
            ->assertSee('Sluipschutter Scorecard')
            ->assertSee('Marktdata & Indicatoren')
            ->assertSeeHtml('scout-scorecard-hud');
    }

    public function test_edit_scout_page_shows_scorecard(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'AAPL',
            'entry_price' => 100.50,
            'signal_low' => 100.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
        ]);
        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertOk()
            ->assertSee('Sluipschutter Scorecard')
            ->assertSee('Marktdata & Indicatoren')
            ->assertSee('SMA 20 (5 dagen geleden)')
            ->assertSee('SMA 50')
            ->assertSee('Live Rating')
            ->assertSeeHtml('scout-scorecard-hud')
            ->assertSeeHtml('scout-scorecard-criterion')
            ->assertSee('A+ SETUP')
            ->assertSee('Trampoline-afstand');
    }

    public function test_scorecard_shows_hard_fail_for_overheated_rsi(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 100.50,
            'signal_low' => 100.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 76.00,
            'bounce_volume_above_average' => true,
        ]);
        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertOk()
            ->assertSee('RSI oververhit (>70)')
            ->assertSee('B/C Setup');
    }

    public function test_activate_button_has_a_plus_class_for_perfect_setup(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 100.50,
            'signal_low' => 100.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
        ]);
        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertOk()
            ->assertSeeHtml('scout-activate-a-plus');
    }

    public function test_setup_radar_widget_lists_scouts_only(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create(['ticker' => 'RADAR']);
        Position::factory()->create(['ticker' => 'OPEN']);
        Livewire::test(SetupRadarWidget::class)
            ->assertCanSeeTableRecords([$scout])
            ->assertSee('Setup Radar');
    }

    public function test_market_data_fetcher_syncs_position(): void
    {
        $scout = Position::factory()->scout()->create(['ticker' => 'TSLA']);

        $this->mock(AlphaVantageService::class, function ($mock): void {
            $mock->shouldReceive('fetchGlobalQuote')->once()->with('TSLA')->andReturn([
                'close' => 250.00,
                'high' => 255.00,
                'low' => 245.00,
            ]);
            $mock->shouldReceive('fetchSma20Pair')->once()->with('TSLA')->andReturn([
                'latest' => 245.00,
                'five_days_ago' => 244.00,
            ]);
            $mock->shouldReceive('fetchSma50')->once()->with('TSLA')->andReturn(240.00);
            $mock->shouldReceive('fetchAtr14')->once()->with('TSLA')->andReturn(8.00);
            $mock->shouldReceive('fetchRsi14')->once()->with('TSLA')->andReturn(48.00);
        });

        $fetcher = app(MarketDataFetcher::class);

        $this->assertTrue($fetcher->syncPosition($scout, withDelays: false));

        $scout->refresh();

        $this->assertEquals(250.00, (float) $scout->latest_close_price);
    }
}
