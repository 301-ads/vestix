<?php

namespace Tests\Unit;

use App\Enums\AlertEventType;
use App\Models\Position;
use App\Support\AlertMessageBuilder;
use Tests\TestCase;

class AlertMessageBuilderPremarketTest extends TestCase
{
    public function test_gap_risk_message_uses_bounce_high(): void
    {
        $position = new Position([
            'ticker' => 'KDP',
            'signal_high' => 49.00,
            'status' => 'scout',
        ]);
        $position->id = 1;

        $message = AlertMessageBuilder::forEvent(
            AlertEventType::PremarketGapRisk,
            $position,
            ['premarket_price' => 50.50, 'bounce_high' => 49.00, 'gap_pct' => 3.06],
        );

        $this->assertStringContainsString('chasing bij KDP', $message);
        $this->assertStringContainsString('bounce high $49.00', $message);
    }

    public function test_reclamation_message(): void
    {
        $position = new Position([
            'ticker' => 'SJM',
            'status' => 'scout',
        ]);
        $position->id = 1;

        $message = AlertMessageBuilder::forEvent(
            AlertEventType::PremarketReclamation,
            $position,
            ['premarket_price' => 101.00],
        );

        $this->assertStringContainsString('Kopers actief!', $message);
        $this->assertStringContainsString('SJM herovert SMA 20', $message);
    }

    public function test_landing_message(): void
    {
        $position = new Position([
            'ticker' => 'LYV',
            'latest_sma_20' => 100.00,
            'status' => 'scout',
        ]);
        $position->id = 1;

        $message = AlertMessageBuilder::forEvent(
            AlertEventType::PremarketLanding,
            $position,
            ['premarket_price' => 99.00, 'sma_20' => 100.00, 'distance_pct' => 1.0],
        );

        $this->assertStringContainsString('Landing nadert', $message);
        $this->assertStringContainsString('LYV', $message);
    }
}
