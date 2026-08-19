<?php

namespace Tests\Unit;

use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Models\Position;
use App\Models\User;
use App\Support\AlertMessageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertMessageBuilderMarketOpenReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_includes_entry_investment_and_ibkr_link_when_configured(): void
    {
        config([
            'vestix.brokers.ibkr.stock_url' => 'https://example.com/stocks/{ticker}',
        ]);

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
        ]);

        $position = Position::factory()->scout()->create([
            'user_id' => $user->id,
            'ticker' => 'AAPL',
            'entry_price' => 185.50,
            'quantity' => 2,
        ]);

        $message = AlertMessageBuilder::forEvent(
            AlertEventType::MarketOpenBuyStopReminder,
            $position,
            ['user' => $user],
        );

        $this->assertStringContainsString('BUY-STOP REMINDER: AAPL', $message);
        $this->assertStringContainsString('$185.50', $message);
        $this->assertStringContainsString('Inleg: $371.00', $message);
        $this->assertStringContainsString('Open in IBKR', $message);
        $this->assertStringContainsString('example.com/stocks/aapl', $message);
    }

    public function test_message_omits_broker_link_when_ibkr_url_not_configured(): void
    {
        config([
            'vestix.brokers.ibkr.stock_url' => null,
        ]);

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
        ]);

        $position = Position::factory()->scout()->create([
            'user_id' => $user->id,
            'ticker' => 'MSFT',
            'entry_price' => 400.00,
        ]);

        $message = AlertMessageBuilder::forEvent(
            AlertEventType::MarketOpenBuyStopReminder,
            $position,
            ['user' => $user],
        );

        $this->assertStringNotContainsString('Open in IBKR', $message);
        $this->assertStringContainsString('Open setup', $message);
    }
}
