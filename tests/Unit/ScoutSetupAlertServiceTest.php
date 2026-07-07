<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\User;
use App\Support\ScoutSetupAlertService;
use App\Support\ScoutSetupScorecard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScoutSetupAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['vestix.telegram.bot_token' => 'test-token']);
    }

    public function test_sends_a_alert_on_transition_to_eight(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        $position = Position::factory()->for($user)->scout()->create([
            'ticker' => 'APTV',
            'last_setup_score' => 7,
            'latest_open_price' => 100.00,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 2.50,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $scorecard = $position->evaluateSetupScore();
        $this->assertEquals(8, $scorecard['totalPoints']);

        $sent = app(ScoutSetupAlertService::class)->evaluateAndNotify($position, 7, $scorecard);

        $this->assertSame(1, $sent);
        $position->refresh();
        $this->assertNotNull($position->telegram_a_minus_alert_sent_at);
        $this->assertNull($position->telegram_a_plus_alert_sent_at);

        Http::assertSent(function ($request): bool {
            $text = $request->data()['text'] ?? '';

            return str_contains($request->url(), 'api.telegram.org')
                && str_contains($text, 'A SETUP (8/'.ScoutSetupScorecard::maxPoints().')')
                && ! str_contains($text, 'Buy-Stop: $');
        });
    }

    public function test_sends_a_plus_plus_alert_on_transition_to_ten(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        $position = Position::factory()->for($user)->scout()->create([
            'ticker' => 'APTV',
            'last_setup_score' => 9,
            'latest_open_price' => 100.00,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'telegram_a_minus_alert_sent_at' => now()->subHour(),
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $scorecard = $position->evaluateSetupScore();
        $this->assertEquals(10, $scorecard['totalPoints']);

        $sent = app(ScoutSetupAlertService::class)->evaluateAndNotify($position, 9, $scorecard);

        $this->assertSame(1, $sent);
        $position->refresh();
        $this->assertNotNull($position->telegram_a_plus_alert_sent_at);
    }

    public function test_stays_silent_on_transition_from_six_to_seven(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        $position = Position::factory()->for($user)->scout()->create([
            'ticker' => 'APTV',
            'last_setup_score' => 5,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
        ]);

        Http::fake();

        $scorecard = $position->evaluateSetupScore();
        $this->assertEquals(6, $scorecard['totalPoints']);

        $sent = app(ScoutSetupAlertService::class)->evaluateAndNotify($position, 5, $scorecard);

        $this->assertSame(0, $sent);
        Http::assertNothingSent();
    }
}
