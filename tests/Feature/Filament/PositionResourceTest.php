<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Positions\Pages\CreatePosition;
use App\Filament\Resources\Positions\Pages\EditPosition;
use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Filament\Resources\Positions\PositionResource;
use App\Models\Position;
use App\Models\User;
use App\Services\AlphaVantageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PositionResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_render_positions_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(ListPositions::class)
            ->assertOk();
    }

    public function test_open_positions_table_uses_new_column_layout(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'ticker' => 'GS',
            'entry_price' => 76.00,
            'quantity' => 10,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        $livewire = Livewire::test(ListPositions::class)
            ->assertOk()
            ->assertSee('Ticker')
            ->assertSee('Aantal')
            ->assertSee('Entry')
            ->assertSee('Actuele Koers')
            ->assertSee('P&L (%)')
            ->assertSee('Stop-Loss')
            ->assertSee('Schild')
            ->assertSee('SMA 20')
            ->assertSee('ATR 14')
            ->assertSee('$78.20')
            ->assertSee('2.89%')
            ->assertTableColumnVisible('ticker')
            ->assertTableColumnVisible('new_sl')
            ->assertTableColumnVisible('actuele_koers')
            ->assertTableColumnVisible('unrealized_pnl_percentage')
            ->assertCanNotRenderTableColumn('action_command')
            ->assertCanNotRenderTableColumn('current_sl')
            ->assertCanNotRenderTableColumn('unrealized_pnl')
            ->assertTableColumnExists('action_command', fn ($column) => $column->isToggledHiddenByDefault())
            ->assertTableColumnExists('current_sl', fn ($column) => $column->isToggledHiddenByDefault());

        $pnlColumn = $livewire->instance()->getTable()->getColumn('unrealized_pnl_percentage');
        $pnlColumn->record($livewire->instance()->getTableRecords()->first());

        $this->assertSame('+$22.00', $pnlColumn->getTooltip());
    }

    public function test_edit_page_shows_calculator_section(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->assertOk()
            ->assertSee('Open')
            ->assertDontSee('Status')
            ->assertSee('Archiveer')
            ->assertDontSee('Archiveer Positie')
            ->assertSee('Actuele Koers')
            ->assertSee('Nieuwe SL')
            ->assertSee('Open P&L')
            ->assertSee('Actie / Executie')
            ->assertSee('Update')
            ->assertSee('Afstand:')
            ->assertSee('Huidige SL:')
            ->assertSee('$76.10')
            ->assertSee('$78.20')
            ->assertSee('Winst per aandeel:')
            ->assertSee('t.o.v. inleg');
    }

    public function test_edit_page_archive_header_action_closes_hold_position(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 76.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->assertSee('Archiveer')
            ->assertDontSee('Archiveer Positie')
            ->callAction('archive', data: [
                'exit_price' => 79.50,
            ]);

        $position->refresh();

        $this->assertEquals('closed', $position->status);
        $this->assertEquals(79.50, (float) $position->exit_price);
        $this->assertNotNull($position->closed_at);
    }

    public function test_edit_page_shows_liquidation_archive_label(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'latest_close_price' => 70.00,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->assertSee('Schild Geraakt (Sluit)');
    }

    public function test_edit_page_closed_position_shows_archive_metadata_in_header(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->closed()->create([
            'exit_price' => 90.00,
            'closed_at' => now()->setDate(2026, 6, 10),
        ]);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->assertSee('Gesloten')
            ->assertSee('Exit: $90.00')
            ->assertSee('gesloten op')
            ->assertDontSee('Close prijs')
            ->assertDontSee('Archiveer Positie');
    }

    public function test_edit_page_save_cannot_close_position_via_form(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->fillForm([
                'status' => 'closed',
                'exit_price' => 50.00,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals('open', $position->fresh()->status);
        $this->assertNull($position->fresh()->exit_price);
    }

    public function test_confirm_sl_action_updates_current_sl(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(ListPositions::class)
            ->callTableAction('mark_as_updated', $position);

        $this->assertEquals(76.10, (float) $position->fresh()->current_sl);
    }

    public function test_archive_action_closes_position(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'latest_close_price' => 70.00,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(ListPositions::class)
            ->callTableAction('archive', $position, data: [
                'exit_price' => 73.25,
            ]);

        $position->refresh();

        $this->assertEquals('closed', $position->status);
        $this->assertEquals(73.25, (float) $position->exit_price);
        $this->assertNotNull($position->closed_at);
    }

    public function test_edit_page_shows_performance_section(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'entry_price' => 70.00,
            'quantity' => 10,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->assertOk()
            ->assertSee('Open P&L')
            ->assertSee('+$82.00')
            ->assertSee('+11.71% t.o.v. inleg');
    }

    public function test_edit_page_shows_trade_journal_section(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'trade_journal' => 'Gekocht op bounce van 200 EMA.',
        ]);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->assertOk()
            ->assertSee('Trade Journal')
            ->assertSee('TradingView — entry')
            ->assertFormSet([
                'trade_journal' => 'Gekocht op bounce van 200 EMA.',
            ]);
    }

    public function test_create_position_persists_trade_journal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(CreatePosition::class)
            ->fillForm([
                'ticker' => 'AAPL',
                'entry_price' => 150,
                'quantity' => 10,
                'current_sl' => 140,
                'trade_journal' => 'Bounce from 200 EMA, sector bullish.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('positions', [
            'ticker' => 'AAPL',
            'trade_journal' => 'Bounce from 200 EMA, sector bullish.',
            'status' => 'open',
        ]);
    }

    public function test_global_search_finds_positions_by_ticker(): void
    {
        $user = User::factory()->create();
        Position::factory()->create(['ticker' => 'NVDA']);
        Position::factory()->create(['ticker' => 'AAPL']);

        $this->actingAs($user);

        $results = PositionResource::getGlobalSearchResults('NVDA');

        $this->assertCount(1, $results);
        $this->assertSame('NVDA', $results->first()->title);
    }

    public function test_global_search_finds_positions_by_trade_journal(): void
    {
        $user = User::factory()->create();
        Position::factory()->create([
            'ticker' => 'TSLA',
            'trade_journal' => 'Gekocht voor earnings, wilde een gokje wagen.',
        ]);

        $this->actingAs($user);

        $results = PositionResource::getGlobalSearchResults('earnings');

        $this->assertCount(1, $results);
        $this->assertSame('TSLA', $results->first()->title);
        $this->assertSame('Open', $results->first()->details['Status']);
    }

    public function test_positions_navigation_badge_shows_open_count(): void
    {
        Position::factory()->count(2)->create(['status' => 'open']);
        Position::factory()->count(3)->scout()->create();
        Position::factory()->closed()->create();

        $this->assertSame('2', PositionResource::getNavigationBadge());
    }

    public function test_sidebar_shows_position_and_scout_counts(): void
    {
        $user = User::factory()->create();

        Position::factory()->count(2)->create(['status' => 'open']);
        Position::factory()->count(3)->scout()->create();

        $this->actingAs($user)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('Posities')
            ->assertSee('Setup Radar');
    }

    public function test_edit_position_persists_entry_chart_screenshot(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $position = Position::factory()->create(['status' => 'open']);
        $file = UploadedFile::fake()->image('cdns-entry.jpg', 1200, 800);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->fillForm([
                'entry_chart_screenshot_path' => $file,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $position->refresh();

        $this->assertNotNull($position->entry_chart_screenshot_path);
        Storage::disk('public')->assertExists($position->entry_chart_screenshot_path);
    }

    public function test_archive_action_persists_exit_chart_screenshot(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $position = Position::factory()->create(['status' => 'open']);
        $file = UploadedFile::fake()->image('cdns-exit.jpg', 1200, 800);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->callAction('archive', data: [
                'exit_price' => 79.50,
                'exit_chart_screenshot_path' => $file,
            ]);

        $position->refresh();

        $this->assertEquals('closed', $position->status);
        $this->assertNotNull($position->exit_chart_screenshot_path);
        Storage::disk('public')->assertExists($position->exit_chart_screenshot_path);
    }

    public function test_closed_position_shows_entry_and_exit_chart_upload_fields(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->closed()->create([
            'entry_price' => 100.00,
            'exit_price' => 90.00,
            'quantity' => 10,
        ]);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->assertSee('TradingView — entry')
            ->assertSee('TradingView — exit');
    }

    public function test_closed_position_trade_journal_remains_editable(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->closed()->create([
            'entry_price' => 100.00,
            'exit_price' => 90.00,
            'quantity' => 10,
            'trade_journal' => 'Original setup note.',
        ]);

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->fillForm([
                'trade_journal' => 'Post-mortem: stopped out on sector rotation.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(
            'Post-mortem: stopped out on sector rotation.',
            $position->fresh()->trade_journal,
        );
    }

    public function test_fetch_market_data_action_updates_open_position_form_and_cockpit(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'ticker' => 'GS',
            'status' => 'open',
            'latest_close_price' => 450.00,
            'latest_sma_20' => 440.00,
            'latest_atr_14' => 8.00,
            'current_sl' => 430.00,
        ]);

        $this->mock(AlphaVantageService::class, function ($mock): void {
            $mock->shouldReceive('fetchQuote')->once()->with('GS')->andReturn(462.50);
            $mock->shouldReceive('fetchSma20Pair')->once()->with('GS')->andReturn([
                'latest' => 455.00,
                'five_days_ago' => 448.00,
            ]);
            $mock->shouldReceive('fetchSma50')->once()->with('GS')->andReturn(430.00);
            $mock->shouldReceive('fetchAtr14')->once()->with('GS')->andReturn(9.00);
            $mock->shouldReceive('fetchRsi14')->once()->with('GS')->andReturn(58.00);
        });

        $this->actingAs($user);

        Livewire::test(EditPosition::class, ['record' => $position->getKey()])
            ->assertSee('$450.00')
            ->callAction('fetch_market_data')
            ->assertFormSet([
                'latest_close_price' => 462.50,
                'latest_sma_20' => 455.00,
                'latest_atr_14' => 9.00,
            ])
            ->assertSee('$462.50');

        $this->assertEquals(462.50, (float) $position->fresh()->latest_close_price);
    }
}
