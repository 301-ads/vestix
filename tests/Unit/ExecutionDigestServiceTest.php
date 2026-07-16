<?php

namespace Tests\Unit;

use App\Alerts\AlertDispatcher;
use App\Contracts\QuoteProvider;
use App\Enums\ExecutionDigestStatus;
use App\Models\Position;
use App\Models\User;
use App\Services\ExecutionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExecutionDigestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_gap_up_when_open_at_or_above_entry(): void
    {
        $service = $this->serviceWithSessionOpen(56.50);
        $scout = $this->scout(entry: 56.45, sma: 55.00);

        $result = $service->classify($scout);

        $this->assertSame(ExecutionDigestStatus::CancelledGapUp, $result['status']);
        $this->assertEqualsWithDelta(56.50, $result['price'], 0.001);
    }

    public function test_classifies_trend_break_when_open_below_sma(): void
    {
        $service = $this->serviceWithSessionOpen(54.00);
        $scout = $this->scout(entry: 56.45, sma: 55.00);

        $result = $service->classify($scout);

        $this->assertSame(ExecutionDigestStatus::CancelledTrendBreak, $result['status']);
    }

    public function test_classifies_safe_zone_between_sma_and_entry(): void
    {
        $service = $this->serviceWithSessionOpen(55.80);
        $scout = $this->scout(entry: 56.45, sma: 55.00);

        $result = $service->classify($scout);

        $this->assertSame(ExecutionDigestStatus::Safe, $result['status']);
    }

    public function test_falls_back_to_prior_day_low_when_sma_missing(): void
    {
        $service = $this->serviceWithSessionOpen(48.00);
        $scout = $this->scout(entry: 50.00, sma: null, priorLow: 49.00);

        $result = $service->classify($scout);

        $this->assertSame(ExecutionDigestStatus::CancelledTrendBreak, $result['status']);
        $this->assertStringContainsString('prior day low', $result['reason']);
    }

    public function test_unavailable_when_no_quote(): void
    {
        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchSessionQuote')->andReturn(null);
        $quotes->shouldReceive('fetchLivePrice')->andReturn(null);

        $service = new ExecutionDigestService($quotes, app(AlertDispatcher::class));
        $scout = $this->scout(entry: 50.00, sma: 48.00);

        $result = $service->classify($scout);

        $this->assertSame(ExecutionDigestStatus::Unavailable, $result['status']);
    }

    private function serviceWithSessionOpen(float $open): ExecutionDigestService
    {
        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchSessionQuote')->andReturn([
            'open' => $open,
            'close' => $open,
            'high' => $open,
            'low' => $open,
        ]);
        $quotes->shouldReceive('fetchLivePrice')->never();

        return new ExecutionDigestService($quotes, app(AlertDispatcher::class));
    }

    private function scout(float $entry, ?float $sma, ?float $priorLow = null): Position
    {
        $user = User::factory()->create();

        return Position::factory()->for($user)->scout()->create([
            'ticker' => 'TEST',
            'entry_price' => $entry,
            'latest_sma_20' => $sma,
            'prior_day_low' => $priorLow,
            'latest_atr_14' => 1.5,
            'quantity' => 10,
        ]);
    }
}
