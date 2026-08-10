<?php

namespace Tests\Feature;

use App\Contracts\DailyBarProvider;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PositionPriceChartTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_owner_can_fetch_price_chart_payload(): void
    {
        Cache::flush();

        $user = User::factory()->create();
        $position = Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'status' => 'open',
            'entry_price' => 50.0,
            'current_sl' => 47.0,
            'quantity' => 10,
        ]);

        $bars = [];
        $start = Carbon::parse('2026-01-02');
        for ($i = 0; $i < 30; $i++) {
            $close = 48 + ($i * 0.2);
            $bars[] = [
                'open' => $close - 0.1,
                'high' => $close + 0.5,
                'low' => $close - 0.5,
                'close' => $close,
                'volume' => 1_000_000,
                'date' => $start->copy()->addWeekdays($i)->toDateString(),
            ];
        }

        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn([
            'today' => $bars[array_key_last($bars)],
            'adv30' => 1_000_000.0,
            'bars' => $bars,
        ]);
        $this->app->instance(DailyBarProvider::class, $dailyBars);

        $response = $this->actingAs($user)
            ->getJson(route('positions.price-chart', ['position' => $position, 'range' => '1M']));

        $response->assertOk()
            ->assertJsonPath('ticker', 'BAC')
            ->assertJsonPath('range', '1M')
            ->assertJsonStructure([
                'points' => [['time', 'value']],
                'period_change' => ['absolute', 'percent', 'positive'],
                'levels' => ['entry', 'stop', 'target1'],
                'markers',
            ]);
    }

    public function test_guest_cannot_fetch_price_chart(): void
    {
        $position = Position::factory()->create(['status' => 'open']);

        $this->getJson(route('positions.price-chart', $position))
            ->assertRedirect();
    }
}
