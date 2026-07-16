<?php

namespace Tests\Feature;

use App\Enums\Broker;
use App\Enums\BrokerOrderStatus;
use App\Enums\PositionVisibility;
use App\Enums\PremarketScanResult;
use App\Enums\ScoutPipelineStatus;
use App\Filament\Resources\Positions\Pages\CreateScout;
use App\Filament\Resources\Positions\Pages\EditScout;
use App\Filament\Resources\Positions\Pages\ListScouts;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Widgets\BuyStopReviewWidget;
use App\Filament\Widgets\SetupRadarWidget;
use App\Models\Position;
use App\Services\MarketDataFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MarketDataTestTime;
use Tests\Support\PolygonFixtures;
use Tests\TestCase;

class ScoutWatchlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_unchecking_squad_share_persists_toggle_state_on_reload(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $this->actingAsFilamentUser($user, $squad);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'APTV',
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->set('data.share_with_squad', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $scout->refresh();

        $this->assertSame(PositionVisibility::Private, $scout->visibility);
        $this->assertNull($scout->squad_id);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertFormSet(['visibility' => PositionVisibility::Private->value])
            ->assertSet('data.share_with_squad', false);
    }

    public function test_scout_can_be_created_with_ticker_only(): void
    {
        $user = $this->authenticateFilament();

        Livewire::test(CreateScout::class)
            ->fillForm([
                'ticker' => 'APTV',
                'strategy_tag_id' => $this->defaultStrategyTagId(),
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
                'strategy_tag_id' => $this->defaultStrategyTagId(),
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
        $this->assertNull($scout->initial_sl_placed_at);
        $this->assertSame(Position::PRIMARY_ACTION_PLACE_INITIAL_SL, $scout->primaryActionType());
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

    public function test_scout_quantity_from_planned_investment(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10942,
            'default_risk_percent' => 1,
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 231.48,
            'latest_sma_20' => 231.48,
            'latest_atr_14' => 18.94,
            'quantity' => null,
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->fillForm(['_planned_investment' => 2550])
            ->assertFormSet(['quantity' => '11']);
    }

    public function test_scout_risk_guard_green_when_within_limit(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10942,
            'default_risk_percent' => 1,
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 25.64,
            'latest_sma_20' => 26.12,
            'latest_atr_14' => 2.00,
            'quantity' => null,
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->fillForm(['_planned_investment' => 1000])
            ->assertFormSet(['quantity' => '39'])
            ->assertSee('van bankroll')
            ->assertDontSee('boven limiet');
    }

    public function test_scout_risk_guard_red_when_over_limit(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10942,
            'default_risk_percent' => 1,
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 28.85,
            'latest_sma_20' => 28.85,
            'latest_atr_14' => 6.10,
            'quantity' => null,
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->fillForm(['_planned_investment' => 3000])
            ->assertFormSet(['quantity' => '103'])
            ->assertSee('boven limiet');
    }

    public function test_scout_risk_guard_allows_save_when_over_limit(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10942,
            'default_risk_percent' => 1,
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'WULF',
            'entry_price' => 28.85,
            'latest_sma_20' => 28.85,
            'latest_atr_14' => 6.10,
            'quantity' => null,
            'strategy_tag_id' => $this->defaultStrategyTagId(),
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->fillForm(['_planned_investment' => 3000])
            ->call('save')
            ->assertHasNoFormErrors();

        $scout->refresh();

        $this->assertEquals(103, (float) $scout->quantity);
    }

    public function test_scout_without_bankroll_shows_profile_cta(): void
    {
        $user = $this->authenticateFilament();
        $user->update(['trading_bankroll' => null]);

        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 250.00,
            'latest_sma_20' => 231.48,
            'latest_atr_14' => 18.94,
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertSee('Bankroll instellen')
            ->assertSee('Position sizing')
            ->assertSee('bijv. 1000')
            ->fillForm(['_planned_investment' => 1000])
            ->assertFormSet(['quantity' => '4']);
    }

    public function test_scout_cockpit_shows_percent_of_bankroll(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10942,
            'default_risk_percent' => 1,
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 25.64,
            'quantity' => 39,
            'latest_sma_20' => 26.12,
            'latest_atr_14' => 2.00,
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertSee('van bankroll');
    }

    public function test_edit_scout_hud_shows_total_planned_risk(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10942,
            'default_risk_percent' => 1,
        ]);
        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 80.00,
            'quantity' => 10,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertSee('$39.00')
            ->assertSee('van bankroll');
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
            ->assertSee('Pending')
            ->assertSee('Geplande Entry')
            ->assertSee('Gepland risico')
            ->assertSee('Totale inleg')
            ->assertSee('Order geplaatst')
            ->assertDontSee('Activeren')
            ->assertDontSee('Actie / Executie');
    }

    public function test_scout_estafette_hides_activate_until_order_placed(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'ESTF',
            'entry_price' => 50.00,
            'quantity' => 10,
            'latest_sma_20' => 48.00,
            'latest_atr_14' => 2.00,
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertActionVisible('mark_buy_stop_placed')
            ->assertActionHidden('activate_scout')
            ->callAction('mark_buy_stop_placed')
            ->assertActionHidden('mark_buy_stop_placed')
            ->assertActionVisible('activate_scout')
            ->assertActionVisible('clear_buy_stop')
            ->callAction('clear_buy_stop')
            ->assertActionVisible('mark_buy_stop_placed')
            ->assertActionHidden('activate_scout');

        $this->assertSame(BrokerOrderStatus::Scout, $scout->fresh()->broker_order_status);
        $this->assertSame(ScoutPipelineStatus::Scout, $scout->fresh()->scoutPipelineStatus());
    }

    public function test_activate_scout_action_from_list(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->pendingBrokerOrder()->create([
            'entry_price' => 78.00,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'strategy_tag_id' => $this->defaultStrategyTagId(),
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

    public function test_activate_scout_action_works_without_strategy_tag(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->pendingBrokerOrder()->create([
            'entry_price' => 78.00,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'strategy_tag_id' => null,
        ]);

        Livewire::test(ListScouts::class)
            ->callTableAction('activate_scout', $scout, data: [
                'entry_price' => 79.00,
                'quantity' => 8,
            ])
            ->assertHasNoErrors();

        $scout->refresh();

        $this->assertEquals('open', $scout->status);
        $this->assertNull($scout->strategy_tag_id);
    }

    public function test_fetch_market_data_action_updates_scout(): void
    {
        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
        ]);

        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create(['ticker' => 'MSFT']);

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => PolygonFixtures::dailyBars(latestClose: 78.20),
            ]),
        ]);

        $component = Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertSee('Actuele Koers')
            ->assertSee('—')
            ->callAction('fetch_market_data')
            ->assertSet('pollPositionMarketData', true);

        $this->artisan('vestix:fetch-data', [
            '--position-id' => $scout->id,
            '--user-id' => $user->id,
        ])->assertSuccessful();

        $component->call('pollPositionMarketDataFetch');

        $scout->refresh();

        $this->assertNotNull($scout->latest_close_price);
        $this->assertNotNull($scout->latest_sma_20);
        $this->assertNotNull($scout->latest_atr_14);
        $this->assertNotNull($scout->scout_rsi);
    }

    public function test_create_scout_page_shows_scorecard(): void
    {
        $this->authenticateFilament();

        Livewire::test(CreateScout::class)
            ->assertOk()
            ->assertSee('Setup & Valstrik')
            ->assertSee('Sniper Scorecard')
            ->assertSee('Marktdata & Indicatoren')
            ->assertSee('Wiskundige Validatie')
            ->assertSee('Trade Journal & Notities')
            ->assertSeeHtml('scout-scorecard-hud');
    }

    public function test_edit_scout_page_shows_scorecard(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create(array_merge(
            $this->aPlusSetupAttributes(),
            [
                'ticker' => 'AAPL',
                'entry_price' => 100.50,
            ],
        ));
        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertOk()
            ->assertSee('Setup & Valstrik')
            ->assertSee('Sniper Scorecard')
            ->assertSee('Marktdata & Indicatoren')
            ->assertSee('Wiskundige Validatie')
            ->assertSee('Het Schild')
            ->assertSee('Trade Journal & Notities')
            ->assertSee('SMA 20 (5 dagen geleden)')
            ->assertSee('SMA 50')
            ->assertSee('Live Rating')
            ->assertSeeHtml('scout-scorecard-hud')
            ->assertSeeHtml('vestix-stat-card')
            ->assertSeeHtml('scout-scorecard-criterion')
            ->assertSee('A++ SETUP')
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
            ->assertSee('NO TRADE');
    }

    public function test_activate_button_has_a_plus_class_for_perfect_setup(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->pendingBrokerOrder()->create(array_merge(
            $this->aPlusSetupAttributes(),
            ['entry_price' => 100.50, 'quantity' => 10],
        ));
        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertOk()
            ->assertSeeHtml('scout-activate-a-plus');
    }

    public function test_setup_radar_widget_lists_scouts_only(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create(array_merge(
            $this->aPlusSetupAttributes(),
            ['ticker' => 'RADAR'],
        ));
        Position::factory()->create(['ticker' => 'OPEN']);
        Livewire::test(SetupRadarWidget::class)
            ->assertCanSeeTableRecords([$scout])
            ->assertSee('Setup Radar');
    }

    public function test_setup_radar_widget_defaults_to_strong_setups(): void
    {
        $user = $this->authenticateFilament();

        $aPlus = Position::factory()->for($user)->scout()->create(array_merge(
            $this->aPlusSetupAttributes(),
            ['ticker' => 'APLS'],
        ));

        $aMinus = Position::factory()->for($user)->scout()->create(array_merge(
            $this->aMinusSetupAttributes(),
            ['ticker' => 'AMNS'],
        ));

        $bSetup = Position::factory()->for($user)->scout()->create(array_merge(
            $this->bSetupAttributes(),
            ['ticker' => 'BCSC'],
        ));

        Livewire::test(SetupRadarWidget::class)
            ->assertCanSeeTableRecords([$aPlus, $aMinus])
            ->assertCanNotSeeTableRecords([$bSetup]);
    }

    public function test_setup_radar_widget_can_search_by_ticker(): void
    {
        $user = $this->authenticateFilament();

        $aPlus = Position::factory()->for($user)->scout()->create(array_merge(
            $this->aPlusSetupAttributes(),
            ['ticker' => 'FINDM'],
        ));

        $other = Position::factory()->for($user)->scout()->create(array_merge(
            $this->aPlusSetupAttributes(),
            ['ticker' => 'OTHER'],
        ));

        Livewire::test(SetupRadarWidget::class)
            ->searchTable('FINDM')
            ->assertCanSeeTableRecords([$aPlus])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_setup_radar_widget_does_not_show_berekende_sl(): void
    {
        $this->authenticateFilament();

        Livewire::test(SetupRadarWidget::class)
            ->assertDontSee('Berekende SL');
    }

    public function test_market_data_fetcher_syncs_position(): void
    {
        MarketDataTestTime::freezeBeforeUsMarketClose();

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
            'vestix.finnhub.api_key' => null,
            'vestix.alpha_vantage.api_key' => null,
        ]);

        $scout = Position::factory()->scout()->create(['ticker' => 'TSLA']);

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => PolygonFixtures::dailyBars(latestClose: 250.00),
            ]),
        ]);

        $fetcher = app(MarketDataFetcher::class);

        $this->assertTrue($fetcher->syncPosition($scout, withDelays: false));

        $scout->refresh();

        $this->assertEqualsWithDelta(250.00, (float) $scout->latest_close_price, 0.01);

        MarketDataTestTime::reset();
    }

    public function test_radar_focus_filter_shows_only_ready_scouts(): void
    {
        $user = $this->authenticateFilament();

        $ready = Position::factory()->for($user)->scout()->create([
            'ticker' => 'READY',
            'entry_price' => 100.00,
            'latest_close_price' => 100.50,
            'signal_low' => 100.50,
            'latest_open_price' => 100.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'trader_promoted_a' => true,
        ]);

        $nearButWeak = Position::factory()->for($user)->scout()->create([
            'ticker' => 'WEAK',
            'entry_price' => 100.00,
            'latest_close_price' => 100.50,
            'signal_low' => 100.50,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
            'bounce_volume_above_average' => false,
        ]);

        $waiting = Position::factory()->for($user)->scout()->create([
            'ticker' => 'WAIT',
            'entry_price' => 100.00,
            'latest_close_price' => 90.00,
        ]);

        Livewire::test(ListScouts::class)
            ->filterTable('radar_focus', 'ready')
            ->assertCanSeeTableRecords([$ready])
            ->assertCanNotSeeTableRecords([$waiting, $nearButWeak]);
    }

    public function test_toggle_radar_focus_clears_when_clicked_twice(): void
    {
        $user = $this->authenticateFilament();

        Position::factory()->for($user)->scout()->create([
            'entry_price' => 100.00,
            'latest_close_price' => 100.50,
        ]);

        Livewire::test(ListScouts::class)
            ->call('toggleRadarFocus', focus: 'ready')
            ->assertSet('tableFilters.radar_focus.value', 'ready')
            ->call('toggleRadarFocus', focus: 'ready')
            ->assertSet('tableFilters', []);
    }

    public function test_mark_buy_stop_placed_sets_pending_status(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'WIT',
            'broker' => Broker::Revolut,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'entry_price' => 50.00,
            'quantity' => 10,
            'latest_sma_20' => 48.00,
            'latest_atr_14' => 2.00,
        ]);

        Livewire::test(ListScouts::class)
            ->callTableAction('mark_buy_stop_placed', $scout);

        $scout->refresh();

        $this->assertSame(BrokerOrderStatus::Pending, $scout->broker_order_status);
        $this->assertSame(ScoutPipelineStatus::Active, $scout->scoutPipelineStatus());
    }

    public function test_ibkr_mark_buy_stop_placed_shows_bracket_modal_and_sets_active(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'COO',
            'broker' => Broker::Ibkr,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'entry_price' => 71.80,
            'quantity' => 34,
            'latest_sma_20' => 69.00,
            'latest_atr_14' => 1.50,
        ]);

        $ticket = \App\Support\BrokerOrderTicket::forIbkrBracket($scout);

        $this->assertSame('IBKR Bracket Order — COO', $ticket['title']);
        $this->assertSame('STOP LIMIT (Kopen)', $ticket['rows'][0]['value']);
        $this->assertSame('Limit Prijs (Max Inkoop)', $ticket['rows'][3]['label']);
        $this->assertSame('$71.95', $ticket['rows'][3]['value']);
        $this->assertNotNull($ticket['intro']);
        $this->assertStringContainsString('GTC', $ticket['intro']);

        Livewire::test(ListScouts::class)
            ->assertTableActionVisible('mark_buy_stop_placed', $scout)
            ->assertTableActionEnabled('mark_buy_stop_placed', $scout)
            ->callTableAction('mark_buy_stop_placed', $scout);

        $scout->refresh();

        $this->assertSame(BrokerOrderStatus::Pending, $scout->broker_order_status);
        $this->assertSame(ScoutPipelineStatus::Active, $scout->scoutPipelineStatus());
    }

    public function test_scout_broker_workflow_follows_profile_primary_broker(): void
    {
        $user = $this->authenticateFilament();
        $user->update(['primary_broker' => Broker::Ibkr]);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'COO',
            'broker' => Broker::Revolut,
            'entry_price' => 71.80,
            'quantity' => 34,
            'latest_sma_20' => 69.00,
            'latest_atr_14' => 1.50,
        ]);

        Livewire::test(EditScout::class, ['record' => $scout->getKey()])
            ->assertDontSee('Broker voor deze scout')
            ->assertSee('IBKR');

        $this->assertTrue($scout->fresh()->usesIbkrWorkflow());
        $this->assertSame(Broker::Revolut, $scout->fresh()->broker);
    }

    public function test_clear_buy_stop_resets_scout_status(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->pendingBrokerOrder()->create([
            'ticker' => 'KDP',
        ]);

        Livewire::test(ListScouts::class)
            ->callTableAction('clear_buy_stop', $scout);

        $scout->refresh();

        $this->assertSame(BrokerOrderStatus::Scout, $scout->broker_order_status);
        $this->assertSame(ScoutPipelineStatus::Scout, $scout->scoutPipelineStatus());
    }

    public function test_activated_scout_disappears_from_radar_list(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->pendingBrokerOrder()->create([
            'entry_price' => 78.00,
            'quantity' => 10,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
        ]);

        Livewire::test(ListScouts::class)
            ->assertCanSeeTableRecords([$scout])
            ->assertTableActionVisible('activate_scout', $scout)
            ->callTableAction('activate_scout', $scout, data: [
                'entry_price' => 79.00,
                'quantity' => 8,
            ]);

        $scout->refresh();

        $this->assertSame('open', $scout->status);

        Livewire::test(ListScouts::class)
            ->assertCanNotSeeTableRecords([$scout]);
    }

    public function test_radar_list_shows_simplified_stat_widgets(): void
    {
        $user = $this->authenticateFilament();

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'LIN',
            'entry_price' => 100.00,
            'latest_close_price' => 100.50,
        ]);

        Livewire::test(ListScouts::class)
            ->assertOk()
            ->assertSee('Klaar voor Executie')
            ->assertSee('Pre-market scan')
            ->assertSee('Executie')
            ->assertSee('Top Setups (A+)')
            ->assertDontSee('Actieve Scouts')
            ->assertDontSee('Gem. Gepland Risico')
            ->assertDontSee('Reminder Gepland')
            ->assertSeeHtml('vestix-stat-card');
    }

    public function test_reminder_button_visible_without_entry_price(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'NENT',
            'entry_price' => null,
        ]);

        Livewire::test(ListScouts::class)
            ->assertTableActionExists('toggle_market_open_reminder')
            ->assertTableActionDisabled('toggle_market_open_reminder', $scout);
    }

    public function test_toggle_market_open_reminder_from_table_sets_pending_status(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'LIN',
            'entry_price' => 523.66,
        ]);

        Livewire::test(ListScouts::class)
            ->callTableAction('toggle_market_open_reminder', $scout);

        $scout->refresh();

        $this->assertNotNull($scout->market_open_reminder_on);
        $this->assertSame(ScoutPipelineStatus::Pending, $scout->scoutPipelineStatus());
    }

    public function test_radar_list_shows_pipeline_status_column(): void
    {
        $user = $this->authenticateFilament();

        Position::factory()->for($user)->scout()->pendingBrokerOrder()->create([
            'ticker' => 'PEND',
        ]);

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'SCOUT',
            'market_open_reminder_on' => '2026-07-03',
        ]);

        Livewire::test(ListScouts::class)
            ->assertOk()
            ->assertSee('Status')
            ->assertSee('Active')
            ->assertSee('Pending')
            ->assertSee('Order Plan');
    }

    public function test_gap_up_filter_uses_premarket_scan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'America/New_York'));

        $user = $this->authenticateFilament();

        $gapUp = Position::factory()->for($user)->scout()->create([
            'ticker' => 'GAP',
            'premarket_scan_type' => PremarketScanResult::GapRisk,
            'premarket_checked_at' => now(),
        ]);

        $normal = Position::factory()->for($user)->scout()->create([
            'ticker' => 'NORM',
        ]);

        Livewire::test(ListScouts::class)
            ->filterTable('radar_focus', 'gap_up')
            ->assertCanSeeTableRecords([$gapUp])
            ->assertCanNotSeeTableRecords([$normal]);

        Carbon::setTestNow();
    }

    public function test_execution_pipeline_filter_shows_active_and_market_open_pending_scouts(): void
    {
        $user = $this->authenticateFilament();

        $active = Position::factory()->for($user)->scout()->pendingBrokerOrder()->create([
            'ticker' => 'ACTV',
        ]);

        $reminder = Position::factory()->for($user)->scout()->create([
            'ticker' => 'REM',
            'market_open_reminder_on' => '2026-07-03',
        ]);

        $plain = Position::factory()->for($user)->scout()->create([
            'ticker' => 'PLAIN',
        ]);

        Livewire::test(ListScouts::class)
            ->filterTable('radar_focus', 'execution_pipeline')
            ->assertCanSeeTableRecords([$active, $reminder])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    public function test_scouts_list_sorts_setup_grade_a_plus_first(): void
    {
        $user = $this->authenticateFilament();

        $cSetup = Position::factory()->for($user)->scout()->create([
            'ticker' => 'CSET',
            'signal_low' => 100.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
            'last_setup_score' => 6,
        ]);

        $bSetup = Position::factory()->for($user)->scout()->create([
            'ticker' => 'BSET',
            'signal_low' => 100.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
            'last_setup_score' => 7,
        ]);

        $weakSetup = Position::factory()->for($user)->scout()->create([
            'ticker' => 'WEAK',
            'signal_low' => 100.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
            'last_setup_score' => 2,
        ]);

        $hardFail = Position::factory()->for($user)->scout()->create([
            'ticker' => 'FAIL',
            'signal_low' => 99.90,
            'latest_close_price' => 99.90,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
            'last_setup_score' => 5,
        ]);

        $aMinus = Position::factory()->for($user)->scout()->create([
            'ticker' => 'AMNS',
            'signal_low' => 100.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 55,
            'last_setup_score' => 8,
            'trader_promoted_a' => true,
        ]);

        $aPlus = Position::factory()->for($user)->scout()->create([
            'ticker' => 'APLS',
            'signal_low' => 101.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
            'last_setup_score' => 10,
            'trader_promoted_a_plus' => true,
        ]);

        $ordered = Position::scout()
            ->forUser($user->id)
            ->orderBySetupGrade('asc')
            ->pluck('ticker')
            ->all();

        $this->assertSame(['APLS', 'AMNS', 'BSET', 'CSET', 'FAIL', 'WEAK'], $ordered);

        Livewire::test(ListScouts::class)
            ->assertOk()
            ->assertSeeInOrder(['APLS', 'AMNS', 'BSET', 'CSET', 'FAIL', 'WEAK']);
    }

    public function test_scouts_list_polls_every_ten_seconds(): void
    {
        $this->authenticateFilament();

        Livewire::test(ListScouts::class)
            ->assertOk()
            ->assertSeeHtml('wire:poll.10s');
    }

    public function test_rollover_buy_stop_clears_review_and_sets_active(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $this->actingAsFilamentUser($user, $squad);

        $scout = Position::factory()->for($user)->scout()->requiringBuyStopReview()->create([
            'ticker' => 'APTV',
        ]);

        Livewire::test(ListScouts::class)
            ->callTableAction('rollover_buy_stop', $scout);

        $scout->refresh();

        $this->assertSame(BrokerOrderStatus::Pending, $scout->broker_order_status);
        $this->assertNull($scout->buy_stop_review_required_on);
        $this->assertSame(ScoutPipelineStatus::Active, $scout->scoutPipelineStatus());
    }

    public function test_cancel_buy_stop_setup_deletes_scout(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $this->actingAsFilamentUser($user, $squad);

        $scout = Position::factory()->for($user)->scout()->requiringBuyStopReview()->create([
            'ticker' => 'APTV',
        ]);

        Livewire::test(ListScouts::class)
            ->callTableAction('cancel_buy_stop_setup', $scout);

        $this->assertDatabaseMissing('positions', ['id' => $scout->id]);
    }

    public function test_buy_stop_review_widget_lists_scouts_requiring_review(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $this->actingAsFilamentUser($user, $squad);

        Position::factory()->for($user)->scout()->requiringBuyStopReview()->create([
            'ticker' => 'APTV',
        ]);

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'HOLD',
        ]);

        Livewire::test(BuyStopReviewWidget::class)
            ->assertSee('Buy-stop review')
            ->assertSee('APTV')
            ->assertSee('Beoordeel open buy-stop')
            ->assertDontSee('HOLD — Beoordeel open buy-stop');
    }

    /**
     * @return array<string, mixed>
     */
    private function aPlusSetupAttributes(): array
    {
        return [
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
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'trader_promoted_a_plus' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aMinusSetupAttributes(): array
    {
        return [
            'signal_low' => 100.50,
            'latest_open_price' => 100.00,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'trader_promoted_a' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bSetupAttributes(): array
    {
        return [
            'signal_low' => 100.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
            'bounce_volume_above_average' => false,
        ];
    }
}
