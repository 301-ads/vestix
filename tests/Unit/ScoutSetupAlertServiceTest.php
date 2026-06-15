<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\User;
use App\Support\ScoutSetupAlertService;
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

    public function test_sends_a_minus_alert_on_transition_to_six(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        $position = Position::factory()->for($user)->scout()->create([
            'ticker' => 'APTV',
            'last_setup_score' => 5,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $scorecard = $position->evaluateSetupScore();
        $this->assertEquals(6, $scorecard['totalPoints']);

        $sent = app(ScoutSetupAlertService::class)->evaluateAndNotify($position, 5, $scorecard);

        $this->assertSame(1, $sent);
        $position->refresh();
        $this->assertNotNull($position->telegram_a_minus_alert_sent_at);
        $this->assertNull($position->telegram_a_plus_alert_sent_at);

        Http::assertSent(function ($request): bool {
            $text = $request->data()['text'] ?? '';

            return str_contains($request->url(), 'api.telegram.org')
                && str_contains($text, 'A- Setup (6/7)')
                && ! str_contains($text, 'Buy-Stop: $');
        });
    }

    public function test_sends_a_plus_alert_on_transition_to_seven(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        $position = Position::factory()->for($user)->scout()->create([
            'ticker' => 'APTV',
            'last_setup_score' => 6,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'telegram_a_minus_alert_sent_at' => now()->subHour(),
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $scorecard = $position->evaluateSetupScore();
        $this->assertEquals(7, $scorecard['totalPoints']);

        $sent = app(ScoutSetupAlertService::class)->evaluateAndNotify($position, 6, $scorecard);

        $this->assertSame(1, $sent);
        $position->refresh();
        $this->assertNotNull($position->telegram_a_plus_alert_sent_at);
    }

    public function test_stays_silent_on_transition_from_four_to_five(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        $position = Position::factory()->for($user)->scout()->create([
            'ticker' => 'APTV',
            'last_setup_score' => 4,
            'latest_close_price' => 102.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
        ]);

        Http::fake();

        $scorecard = $position->evaluateSetupScore();
        $this->assertEquals(5, $scorecard['totalPoints']);

        $sent = app(ScoutSetupAlertService::class)->evaluateAndNotify($position, 4, $scorecard);

        $this->assertSame(0, $sent);
        Http::assertNothingSent();
    }
}
