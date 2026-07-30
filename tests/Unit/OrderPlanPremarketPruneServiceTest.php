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

    public function test_prunes_peco_like_gap_down_below_sma(): void
    {
        $service = $this->serviceWithPremarket(42.40);
        $scout = $this->scout(
            sma: 42.69,
            ticker: 'PECO',
            close: 42.79,
        );

        $result = $service->evaluate($scout);

        $this->assertSame('prune', $result['action']);
        $this->assertSame(ExecutionDigestStatus::CancelledTrendBreak, $result['status']);
        $this->assertEqualsWithDelta(42.40, (float) $result['price'], 0.001);
        $this->assertStringContainsString('SMA 20', $result['reason']);
    }

    public function test_keeps_when_stale_rth_close_labeled_as_premarket_is_unavailable(): void
    {
        $quotes = Mockery::mock(QuoteProvider::class);
        // Real EH missing: provider returns null after rejecting RTH close ≈ previous close.
        $quotes->shouldReceive('fetchPremarketPrice')
            ->once()
            ->with('GNTX', 23.97)
            ->andReturn(null);
        $quotes->shouldReceive('fetchLivePrice')->never();

        $service = new OrderPlanPremarketPruneService($quotes, app(AlertDispatcher::class));
        $scout = $this->scout(sma: 23.99, ticker: 'GNTX', close: 23.97);

        $result = $service->evaluate($scout);

        $this->assertSame('unavailable', $result['action']);
        $this->assertNull($result['price']);
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
        $quotes->shouldReceive('fetchLivePrice')->never();

        $service = new OrderPlanPremarketPruneService($quotes, app(AlertDispatcher::class));
        $scout = $this->scout(sma: 63.61);

        $result = $service->evaluate($scout);

        $this->assertSame('unavailable', $result['action']);
    }

    public function test_does_not_treat_live_prior_close_as_premarket(): void
    {
        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchPremarketPrice')
            ->once()
            ->with('EMBJ', 64.37)
            ->andReturn(null);
        // Stale-close rejection in FallbackQuoteProvider returns null; live /quote must not reintroduce the slotkoers.
        $quotes->shouldReceive('fetchLivePrice')->never();

        $service = new OrderPlanPremarketPruneService($quotes, app(AlertDispatcher::class));
        $scout = $this->scout(sma: 64.39);

        $result = $service->evaluate($scout);

        $this->assertSame('unavailable', $result['action']);
        $this->assertNull($result['price']);
    }

    private function serviceWithPremarket(float $price): OrderPlanPremarketPruneService
    {
        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchPremarketPrice')->andReturn($price);
        $quotes->shouldReceive('fetchLivePrice')->never();

        return new OrderPlanPremarketPruneService($quotes, app(AlertDispatcher::class));
    }

    private function scout(
        ?float $sma,
        ?float $priorLow = null,
        string $ticker = 'EMBJ',
        float $close = 64.37,
    ): Position {
        $user = User::factory()->create();

        return Position::factory()->for($user)->scout()->create([
            'ticker' => $ticker,
            'entry_price' => $close + 0.13,
            'latest_sma_20' => $sma,
            'prior_day_low' => $priorLow,
            'latest_close_price' => $close,
            'latest_atr_14' => 1.2,
            'quantity' => 10,
        ]);
    }
}
