<?php

namespace Tests\Unit;

use App\Alerts\AlertDispatcher;
use App\Contracts\QuoteProvider;
use App\Enums\ExecutionDigestStatus;
use App\Models\Position;
use App\Models\User;
use App\Services\OrderPlanPremarketPruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderPlanPremarketPruneServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluates_prune_when_premarket_below_sma(): void
    {
        $service = $this->serviceWithPremarket(62.10);
        $scout = $this->scout(sma: 63.61);

        $result = $service->evaluate($scout);

        $this->assertSame('prune', $result['action']);
        $this->assertSame(ExecutionDigestStatus::CancelledTrendBreak, $result['status']);
        $this->assertStringContainsString('SMA 20', $result['reason']);
    }

    public function test_evaluates_keep_when_premarket_above_sma(): void
    {
        $service = $this->serviceWithPremarket(64.00);
        $scout = $this->scout(sma: 63.61);

        $result = $service->evaluate($scout);

        $this->assertSame('keep', $result['action']);
    }

    public function test_falls_back_to_prior_day_low_when_sma_missing(): void
    {
        $service = $this->serviceWithPremarket(48.00);
        $scout = $this->scout(sma: null, priorLow: 49.00);

        $result = $service->evaluate($scout);

        $this->assertSame('prune', $result['action']);
        $this->assertStringContainsString('prior day low', $result['reason']);
    }

    public function test_unavailable_when_no_quote(): void
    {
        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchPremarketPrice')->andReturn(null);
        $quotes->shouldReceive('fetchLivePrice')->andReturn(null);

        $service = new OrderPlanPremarketPruneService($quotes, app(AlertDispatcher::class));
        $scout = $this->scout(sma: 63.61);

        $result = $service->evaluate($scout);

        $this->assertSame('unavailable', $result['action']);
    }

    private function serviceWithPremarket(float $price): OrderPlanPremarketPruneService
    {
        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchPremarketPrice')->andReturn($price);
        $quotes->shouldReceive('fetchLivePrice')->never();

        return new OrderPlanPremarketPruneService($quotes, app(AlertDispatcher::class));
    }

    private function scout(?float $sma, ?float $priorLow = null): Position
    {
        $user = User::factory()->create();

        return Position::factory()->for($user)->scout()->create([
            'ticker' => 'EMBJ',
            'entry_price' => 64.50,
            'latest_sma_20' => $sma,
            'prior_day_low' => $priorLow,
            'latest_close_price' => 64.37,
            'latest_atr_14' => 1.2,
            'quantity' => 10,
        ]);
    }
}
