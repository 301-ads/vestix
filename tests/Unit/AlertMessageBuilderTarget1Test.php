<?php

namespace Tests\Unit;

use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Models\Position;
use App\Models\User;
use App\Support\AlertMessageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertMessageBuilderTarget1Test extends TestCase
{
    use RefreshDatabase;

    public function test_revolut_target_1_message_includes_manual_steps(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Revolut]);

        $position = Position::factory()->for($user)->create([
            'ticker' => 'AAPL',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'quantity' => 100,
        ]);

        $message = AlertMessageBuilder::forEvent(
            AlertEventType::Target1Hit,
            $position,
            ['target_1_price' => $position->target_1_price],
        );

        $this->assertStringContainsString('handmatig bij Revolut', $message);
        $this->assertStringContainsString('stop-loss tijdelijk', $message);
        $this->assertStringContainsString('Log verkoop in Vestix', $message);
    }

    public function test_non_revolut_target_1_message_keeps_limit_sell_copy(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::None]);

        $position = Position::factory()->for($user)->create([
            'ticker' => 'AAPL',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'quantity' => 100,
        ]);

        $message = AlertMessageBuilder::forEvent(
            AlertEventType::Target1Hit,
            $position,
            ['target_1_price' => $position->target_1_price],
        );

        $this->assertStringContainsString('zet stop op breakeven', $message);
        $this->assertStringNotContainsString('handmatig bij Revolut', $message);
    }
}
