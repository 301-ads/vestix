<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Models\Position;
use App\Support\BrokerOrderTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrokerOrderTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_stop_loss_ticket_formats_overview_and_confirmation(): void
    {
        $position = Position::factory()->make([
            'ticker' => 'NVDA',
            'entry_price' => 79.50,
            'quantity' => 12,
            'current_sl' => 76.10,
        ]);

        $ticket = BrokerOrderTicket::forInitialStopLoss($position);

        $this->assertSame('NVDA — Stop-Loss plaatsen', $ticket['title']);
        $this->assertSame('12 stuks', $ticket['rows'][0]['value']);
        $this->assertSame('$79.50', $ticket['rows'][1]['value']);
        $this->assertSame('$76.10', $ticket['rows'][2]['value']);
        $this->assertTrue($ticket['rows'][2]['accent']);
        $this->assertNull($ticket['difference_label']);
        $this->assertStringContainsString('$76.10', $ticket['confirmation']);
        $this->assertStringContainsString('Lynx/IBKR', $ticket['confirmation']);
        $this->assertSame('Stop-Loss geplaatst', $ticket['submit_label']);
    }

    public function test_stop_loss_ticket_formats_overview_and_confirmation(): void
    {
        $position = Position::factory()->make([
            'ticker' => 'ASML',
            'quantity' => 2,
            'current_sl' => 1614.99,
            'latest_close_price' => 1700.00,
            'latest_sma_20' => 1689.93,
            'latest_atr_14' => 20.00,
        ]);

        $newSl = (float) $position->new_sl;
        $difference = $newSl - 1614.99;

        $ticket = BrokerOrderTicket::forStopLossUpdate($position);

        $this->assertSame('ASML — Stop-Loss Update', $ticket['title']);
        $this->assertSame('2 stuks', $ticket['rows'][0]['value']);
        $this->assertSame('$1,614.99', $ticket['rows'][1]['value']);
        $this->assertSame('old', $ticket['rows'][1]['tone']);
        $this->assertSame('$'.number_format($newSl, 2), $ticket['rows'][2]['value']);
        $this->assertSame('new', $ticket['rows'][2]['tone']);
        $this->assertSame(($difference >= 0 ? '+' : '').'$'.number_format($difference, 2), $ticket['rows'][3]['value']);
        $this->assertTrue($ticket['rows'][3]['accent']);
        $this->assertSame('Winst/Risico gereduceerd', $ticket['difference_label']);
        $this->assertStringContainsString('$'.number_format($newSl, 2), $ticket['confirmation']);
        $this->assertStringContainsString('Lynx/IBKR', $ticket['confirmation']);
        $this->assertSame('Stop-Loss Updated', $ticket['submit_label']);
    }

    public function test_limit_sell_ticket_formats_target_and_tranche(): void
    {
        $user = \App\Models\User::factory()->create(['primary_broker' => \App\Enums\Broker::None]);

        $position = Position::factory()->for($user)->make([
            'ticker' => 'GS',
            'quantity' => 100,
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'first_tranche_fraction' => 0.5,
            'target_1_rr' => 2.0,
        ]);

        $ticket = BrokerOrderTicket::forLimitSell($position);

        $this->assertSame('GS — Limit Sell', $ticket['title']);
        $this->assertSame('100 stuks', $ticket['rows'][0]['value']);
        $this->assertSame('50 stuks (50%)', $ticket['rows'][1]['value']);
        $this->assertSame('$12.00', $ticket['rows'][2]['value']);
        $this->assertSame('$9.00', $ticket['rows'][3]['value']);
        $this->assertNull($ticket['difference_label']);
        $this->assertStringContainsString('$12.00', $ticket['confirmation']);
        $this->assertStringContainsString('50 stuks', $ticket['confirmation']);
        $this->assertStringContainsString('50%', $ticket['confirmation']);
        $this->assertSame('Confirm Limit Sell', $ticket['submit_label']);
    }

    public function test_limit_sell_ticket_uses_revolut_target_1_copy_when_user_uses_revolut(): void
    {
        $user = \App\Models\User::factory()->create(['primary_broker' => \App\Enums\Broker::Revolut]);

        $position = Position::factory()->for($user)->make([
            'ticker' => 'GS',
            'quantity' => 100,
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'first_tranche_fraction' => 0.5,
            'target_1_rr' => 2.0,
        ]);

        $ticket = BrokerOrderTicket::forLimitSell($position);

        $this->assertSame('GS — Target 1 bereikt', $ticket['title']);
        $this->assertStringContainsString('Telegram of Revolut-notificatie', $ticket['confirmation']);
        $this->assertSame('Target 1 bevestigd', $ticket['submit_label']);
    }

    public function test_modal_icon_renders_ticker_avatar(): void
    {
        $position = Position::factory()->make([
            'ticker' => 'ASML',
        ]);

        $html = BrokerOrderTicket::modalIcon($position)->toHtml();

        $this->assertStringContainsString('vestix-broker-order-modal__ticker', $html);
        $this->assertStringContainsString('ticker-letter-avatar', $html);
        $this->assertStringContainsString('>A<', $html);
    }

    public function test_modal_icon_renders_asset_logo_when_available(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('ticker-logos/asml.png', 'logo');

        $position = Position::factory()->make([
            'ticker' => 'ASML',
        ]);

        $position->setRelation('asset', Asset::factory()->make([
            'icon_path' => 'ticker-logos/asml.png',
        ]));

        $html = BrokerOrderTicket::modalIcon($position)->toHtml();

        $this->assertStringContainsString('ticker-with-icon__logo', $html);
        $this->assertStringContainsString('/storage/ticker-logos/asml.png', $html);
    }

    public function test_format_quantity_trims_trailing_zeros(): void
    {
        $this->assertSame('10 stuks', BrokerOrderTicket::formatQuantity(10));
        $this->assertSame('2.5 stuks', BrokerOrderTicket::formatQuantity(2.5));
    }

    public function test_stop_loss_ticket_blade_renders_overview_and_confirmation(): void
    {
        $position = Position::factory()->make([
            'ticker' => 'WDC',
            'quantity' => 10,
            'current_sl' => 74.50,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
        ]);

        $html = view('filament.positions.broker-order-ticket', [
            'ticket' => BrokerOrderTicket::forStopLossUpdate($position),
        ])->render();

        $this->assertStringContainsString('Overzicht', $html);
        $this->assertStringContainsString('Oude Stop-Loss', $html);
        $this->assertStringContainsString('vestix-broker-order-ticket__row--old', $html);
        $this->assertStringContainsString('vestix-broker-order-ticket__row--new', $html);
        $this->assertStringContainsString('$74.50', $html);
        $this->assertStringContainsString('$76.10', $html);
        $this->assertStringContainsString('Winst/Risico gereduceerd', $html);
        $this->assertStringContainsString('Lynx/IBKR', $html);
    }

    public function test_limit_sell_ticket_blade_renders_target_details(): void
    {
        $user = \App\Models\User::factory()->create(['primary_broker' => \App\Enums\Broker::None]);

        $position = Position::factory()->for($user)->make([
            'ticker' => 'GS',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 12.00,
            'quantity' => 100,
            'first_tranche_fraction' => 0.5,
            'target_1_rr' => 2.0,
        ]);

        $html = view('filament.positions.broker-order-ticket', [
            'ticket' => BrokerOrderTicket::forLimitSell($position),
        ])->render();

        $this->assertStringContainsString('Limit prijs', $html);
        $this->assertStringContainsString('$12.00', $html);
        $this->assertStringContainsString('50 stuks (50%)', $html);
        $this->assertStringContainsString('Bevestiging', $html);
    }

    public function test_ibkr_bracket_ticket_formats_trading_view_plan(): void
    {
        $position = Position::factory()->scout()->make([
            'ticker' => 'COO',
            'entry_price' => 71.80,
            'quantity' => 34,
            'latest_sma_20' => 69.00,
            'latest_atr_14' => 1.50,
            'first_tranche_fraction' => 0.5,
            'target_1_rr' => 2.0,
        ]);

        // new_sl = 69 - 1.50/2 = 68.25; T1 = 71.80 + 2*(71.80-68.25) = 78.90
        $ticket = BrokerOrderTicket::forIbkrBracket($position);

        $this->assertSame('IBKR Bracket Order — COO', $ticket['title']);
        $this->assertStringContainsString('TradingView', $ticket['intro']);
        $this->assertStringContainsString('STOP LIMIT (Kopen)', $ticket['intro']);
        $this->assertStringContainsString('GTC', $ticket['intro']);
        $this->assertSame('STOP LIMIT (Kopen)', $ticket['rows'][0]['value']);
        $this->assertSame('34 stuks', $ticket['rows'][1]['value']);
        $this->assertSame('34', $ticket['rows'][1]['copy_value']);
        $this->assertSame('$71.80', $ticket['rows'][2]['value']);
        $this->assertSame('71.80', $ticket['rows'][2]['copy_value']);
        $this->assertSame('Limit Prijs (Max Inkoop)', $ticket['rows'][3]['label']);
        $this->assertSame('$71.95', $ticket['rows'][3]['value']);
        $this->assertSame('71.95', $ticket['rows'][3]['copy_value']);
        $this->assertSame('$78.90', $ticket['rows'][4]['value']);
        $this->assertSame('78.90', $ticket['rows'][4]['copy_value']);
        $this->assertStringContainsString('17 stuks', $ticket['rows'][4]['hint']);
        $this->assertStringContainsString('100%', $ticket['rows'][4]['hint']);
        $this->assertStringContainsString('wijzig daarna', $ticket['rows'][4]['hint']);
        $this->assertSame('$68.25', $ticket['rows'][5]['value']);
        $this->assertSame('68.25', $ticket['rows'][5]['copy_value']);
        $this->assertSame('Order geplaatst', $ticket['submit_label']);
    }

    public function test_ibkr_bracket_ticket_blade_renders_copy_buttons(): void
    {
        $position = Position::factory()->scout()->make([
            'ticker' => 'COO',
            'entry_price' => 71.80,
            'quantity' => 34,
            'latest_sma_20' => 69.00,
            'latest_atr_14' => 1.50,
        ]);

        $html = view('filament.positions.broker-order-ticket', [
            'ticket' => BrokerOrderTicket::forIbkrBracket($position),
        ])->render();

        $this->assertStringContainsString('Neem dit exact over in TradingView', $html);
        $this->assertStringContainsString('STOP LIMIT', $html);
        $this->assertStringContainsString('Limit Prijs (Max Inkoop)', $html);
        $this->assertStringContainsString('vestix-broker-order-ticket__copy-btn', $html);
        $this->assertStringContainsString('Take Profit (Target 1)', $html);
        $this->assertStringContainsString('wijzig daarna het TP-aantal', $html);
    }
}
