<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Services\TradeReplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class TradeReplayServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_demo_fallback_includes_sma_rsi_and_entry_marker(): void
    {
        Cache::flush();

        $position = Position::factory()->create([
            'ticker' => 'ALL',
            'status' => 'closed',
            'direction' => 'long',
            'entry_price' => 245.41,
            'exit_price' => 262.00,
            'initial_sl' => 235.00,
            'current_sl' => 235.00,
            'quantity' => 3,
            'signal_bar_date' => now()->subWeeks(3)->toDateString(),
            'closed_at' => now()->subWeek(),
        ]);

        $dailyBars = Mockery::mock(\App\Contracts\DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn(null);

        $payload = (new TradeReplayService($dailyBars))->build($position, allowDemoFallback: true);

        $this->assertNotNull($payload);
        $this->assertTrue($payload['demo']);
        $this->assertGreaterThan(30, count($payload['candles']));
        $this->assertNotEmpty($payload['sma20']);
        $this->assertNotEmpty($payload['rsi14']);
        $this->assertNotEmpty($payload['markers']);
        $this->assertSame('Entry', $payload['markers'][0]['text']);
        $this->assertContains($payload['markers'][0]['time'], array_column($payload['candles'], 'time'));
    }
}
