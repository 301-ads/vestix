<?php

namespace Tests\Unit;

use App\Enums\EarningsReleaseHour;
use App\Models\Asset;
use App\Models\Position;
use App\Services\FinnhubService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinnhubEarningsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
    public function test_fetch_next_earnings_returns_nearest_upcoming_entry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01', 'America/New_York'));

        config([
            'vestix.finnhub.api_key' => 'test-key',
            'vestix.finnhub.base_url' => 'https://finnhub.io/api/v1',
        ]);

        Http::fake([
            'finnhub.io/api/v1/calendar/earnings*' => Http::response([
                'earningsCalendar' => [
                    [
                        'date' => '2026-04-01',
                        'hour' => 'amc',
                        'symbol' => 'AAPL',
                    ],
                    [
                        'date' => '2026-05-01',
                        'hour' => 'bmo',
                        'symbol' => 'AAPL',
                    ],
                ],
            ], 200),
        ]);

        $result = app(FinnhubService::class)->fetchNextEarnings('AAPL');

        $this->assertNotNull($result);
        $this->assertSame('2026-04-01', $result['date']);
        $this->assertSame(EarningsReleaseHour::Amc, $result['hour']);
    }
}
