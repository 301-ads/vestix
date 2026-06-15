<?php

namespace Tests\Feature;

use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Events\PositionLiquidated;
use App\Events\SquadRadarTargetPosted;
use App\Models\Position;
use App\Models\User;
use App\Services\SquadPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_position_liquidated_has_single_telegram_listener(): void
    {
        $this->assertCount(1, Event::getListeners(PositionLiquidated::class));
    }

    public function test_squad_radar_target_posted_has_single_telegram_listener(): void
    {
        $this->assertCount(1, Event::getListeners(SquadRadarTargetPosted::class));
    }

    public function test_position_liquidated_sends_single_telegram_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['vestix.telegram.bot_token' => 'test-token']);

        $user = User::factory()->create(['telegram_chat_id' => '123456']);
        $position = Position::factory()->for($user)->create([
            'ticker' => 'NVDA',
            'status' => 'closed',
        ]);

        PositionLiquidated::dispatch($position);

        $this->assertTelegramSentCount(1);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_contains($request->url(), 'api.telegram.org')
                && ($data['chat_id'] ?? null) === '123456'
                && str_contains((string) ($data['text'] ?? ''), 'NVDA');
        });
    }

    public function test_squad_radar_target_posted_sends_single_telegram_per_recipient(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['vestix.telegram.bot_token' => 'test-token']);

        ['user' => $analyst, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create(['telegram_chat_id' => '999']);
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $scout = Position::factory()->for($analyst)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'ASML',
            'latest_sma_20' => 100,
            'scout_rsi' => 55,
            'signal_low' => 95,
        ]);

        SquadRadarTargetPosted::dispatch($scout);

        $this->assertTelegramSentCount(1);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_contains($request->url(), 'api.telegram.org')
                && ($data['chat_id'] ?? null) === '999'
                && str_contains((string) ($data['text'] ?? ''), 'ASML');
        });
    }

    private function assertTelegramSentCount(int $expected): void
    {
        $count = collect(Http::recorded())
            ->filter(fn (array $record): bool => str_contains($record[0]->url(), 'api.telegram.org'))
            ->count();

        $this->assertSame($expected, $count);
    }
}
