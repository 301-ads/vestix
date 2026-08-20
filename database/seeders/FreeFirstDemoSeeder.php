<?php

namespace Database\Seeders;

use App\Enums\Broker;
use App\Enums\BrokerOrderStatus;
use App\Enums\ExecutionDigestStatus;
use App\Enums\ExecutionTruthState;
use App\Enums\PositionVisibility;
use App\Enums\ScoutReviewStatus;
use App\Enums\SquadRole;
use App\Enums\TradeDirection;
use App\Models\Asset;
use App\Models\BankrollSnapshot;
use App\Models\Position;
use App\Models\Squad;
use App\Models\StrategyTag;
use App\Models\User;
use App\Services\ProtocolComplianceService;
use App\Services\SquadPermissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Local-only demo data so every Free-First roadmap surface is visible.
 *
 *   php artisan vestix:seed-free-first-demo
 *   php artisan vestix:seed-free-first-demo --email=you@example.com
 *   php artisan vestix:seed-free-first-demo --fresh
 */
class FreeFirstDemoSeeder extends Seeder
{
    public function run(?string $email = null, bool $fresh = false): void
    {
        $this->call(StrategyTagSeeder::class);

        $user = $this->resolveUser($email);

        if ($fresh) {
            $this->wipeDemoData($user);
        }

        $trampolineId = StrategyTag::query()->where('slug', 'trampoline-bounce')->value('id');

        $this->configureUser($user);
        $this->ensureDemoSquad($user);
        $this->seedAssets([
            'AAPL', 'MSFT', 'NVDA', 'AMD', 'META', 'GOOGL', 'AMZN', 'TSLA',
            'CRM', 'SHOP', 'PLTR', 'COIN', 'SOFI', 'RIVN', 'NFLX', 'AVGO',
            'ORCL', 'INTC', 'QCOM', 'MU', 'DEMO1', 'DEMO2', 'DEMO3',
        ]);

        $this->seedActionOpenPositions($user, $trampolineId);
        $this->seedOrderPlanAndRadarScouts($user, $trampolineId);
        $this->seedGapHerplanScout($user, $trampolineId);
        $this->seedClosedTradesForEdge($user, $trampolineId);
        $this->seedBankrollSnapshots($user);

        $this->command?->info("Free-First demo geladen voor {$user->email} (id {$user->id}).");
        $this->command?->line('Login → /admin — check Dashboard, Radar, Coach, Prestaties, een open positie.');
    }

    private function resolveUser(?string $email): User
    {
        $email = $email ?: (string) env('VESTIX_DEMO_EMAIL', 'davy@301.digital');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create([
                'name' => 'Free-First Demo',
                'email' => $email,
                'password' => 'password',
            ]);
            $this->command?->warn("User {$email} bestond niet — aangemaakt (password: password).");
        }

        return $user;
    }

    private function wipeDemoData(User $user): void
    {
        Position::query()->forUser($user->id)->delete();
        BankrollSnapshot::query()->where('user_id', $user->id)->delete();
        $this->command?->warn('Bestaande posities + bankroll snapshots van deze user gewist (--fresh).');
    }

    private function configureUser(User $user): void
    {
        // Checklist: 3/4 gedaan → widget zichtbaar (alerts nog open).
        $user->forceFill([
            'default_risk_percent' => 1.0,
            'primary_broker' => Broker::Ibkr,
            'trading_bankroll' => 25000,
            'ibkr_net_liquidation' => 25000,
            'ibkr_available_funds' => 8000,
            'ibkr_settled_cash' => 8000,
            'ibkr_base_currency' => 'USD',
            // No fake Flex snapshot — reconcile widget stays hidden unless real IBKR sync drifts.
            'ibkr_last_success_at' => null,
            'ibkr_last_attempt_at' => null,
            'ibkr_data_stale' => false,
            'ibkr_last_error' => null,
            'ibkr_open_positions' => null,
            'ibkr_open_orders' => null,
            'telegram_chat_id' => null, // houdt first-run checklist zichtbaar
            'ui_preferences' => [
                'first_run' => [
                    'dismissed' => false,
                ],
                'sniper_last_rejects' => [
                    'date' => now('America/New_York')->toDateString(),
                    'samples' => [
                        ['ticker' => 'XYZ', 'reasons' => ['Long: RSI 62.0 buiten 40–55', 'Short: Groene kaars zonder sterke close (Röntgenfoto)']],
                        ['ticker' => 'ABC', 'reasons' => ['Long: Close >3% boven SMA20 (te extended)']],
                        ['ticker' => 'DEF', 'reasons' => ['Long: SMA20 niet boven SMA50 (uptrend ontbreekt)']],
                    ],
                ],
                'plan' => 'free',
            ],
        ])->save();
    }

    private function ensureDemoSquad(User $user): void
    {
        $squad = Squad::query()->firstOrCreate(
            ['slug' => 'alpha-squad'],
            [
                'name' => 'Alpha Squad',
                'owner_id' => $user->id,
            ],
        );

        if ($squad->owner_id === null) {
            $squad->forceFill(['owner_id' => $user->id])->save();
        }

        if (! $squad->users()->whereKey($user->id)->exists()) {
            $squad->users()->attach($user->id);
        }

        app(SquadPermissionService::class)->assignRole($user, $squad, SquadRole::Commander);
    }

    /**
     * @param  list<string>  $tickers
     */
    private function seedAssets(array $tickers): void
    {
        foreach ($tickers as $ticker) {
            Asset::query()->updateOrCreate(
                ['ticker' => $ticker],
                ['fetched_at' => now()],
            );
        }
    }

    private function seedActionOpenPositions(User $user, ?int $tagId): void
    {
        // Qty drift vs IBKR (8 vs 10) + truth label
        $this->upsertOpen($user, 'AAPL', [
            'strategy_tag_id' => $tagId,
            'entry_price' => 190.00,
            'quantity' => 8,
            'initial_sl' => 185.00,
            'current_sl' => 185.00,
            'latest_close_price' => 192.00,
            'latest_sma_20' => 188.00,
            'latest_atr_14' => 3.50,
            'initial_sl_placed_at' => now()->subDay(),
            'execution_truth_state' => ExecutionTruthState::SyncedOpen,
            'data_source_label' => 'broker-synced',
            'broker' => Broker::Ibkr,
        ]);

        // Ghost in Vestix (niet in IBKR Flex)
        $this->upsertOpen($user, 'DEMO3', [
            'strategy_tag_id' => $tagId,
            'entry_price' => 45.00,
            'quantity' => 20,
            'initial_sl' => 42.00,
            'current_sl' => 42.00,
            'latest_close_price' => 46.00,
            'latest_sma_20' => 44.00,
            'latest_atr_14' => 1.20,
            'initial_sl_placed_at' => now()->subDay(),
            'execution_truth_state' => ExecutionTruthState::SubmittedAtBroker,
            'broker_submitted_at' => now()->subHours(6),
            'data_source_label' => 'handmatig',
            'broker' => Broker::Ibkr,
        ]);

        // PLACE_INITIAL_SL todo
        $this->upsertOpen($user, 'AMD', [
            'strategy_tag_id' => $tagId,
            'entry_price' => 160.00,
            'quantity' => 12,
            'initial_sl' => 154.00,
            'current_sl' => 154.00,
            'latest_close_price' => 161.50,
            'latest_sma_20' => 158.00,
            'latest_atr_14' => 4.00,
            'initial_sl_placed_at' => null,
            'execution_truth_state' => ExecutionTruthState::SubmittedAtBroker,
            'broker_submitted_at' => now()->subHour(),
            'data_source_label' => 'handmatig',
            'broker' => Broker::Ibkr,
        ]);

        // TARGET_1 hit (entry 100, SL 95 → risk 5 → T1 @ 110 with 2R)
        $this->upsertOpen($user, 'META', [
            'strategy_tag_id' => $tagId,
            'entry_price' => 100.00,
            'quantity' => 40,
            'initial_sl' => 95.00,
            'current_sl' => 95.00,
            'latest_close_price' => 112.00,
            'latest_sma_20' => 102.00,
            'latest_atr_14' => 3.00,
            'target_1_rr' => 2.0,
            'initial_sl_placed_at' => now()->subDays(2),
            'target_1_limit_placed_at' => null,
            'execution_truth_state' => ExecutionTruthState::SyncedOpen,
            'data_source_label' => 'broker-synced',
            'broker' => Broker::Ibkr,
        ]);

        // STOPPED OUT liquidation todo
        $this->upsertOpen($user, 'TSLA', [
            'strategy_tag_id' => $tagId,
            'entry_price' => 250.00,
            'quantity' => 6,
            'initial_sl' => 240.00,
            'current_sl' => 240.00,
            'latest_close_price' => 235.00,
            'latest_sma_20' => 245.00,
            'latest_atr_14' => 8.00,
            'initial_sl_placed_at' => now()->subDays(3),
            'execution_truth_state' => ExecutionTruthState::SyncedOpen,
            'broker' => Broker::Ibkr,
        ]);

        // Freeride / scaled-out runner for share-card + truth partial
        $this->upsertOpen($user, 'NVDA', [
            'strategy_tag_id' => $tagId,
            'entry_price' => 120.00,
            'quantity' => 10,
            'initial_sl' => 114.00,
            'current_sl' => 120.00,
            'latest_close_price' => 135.00,
            'latest_sma_20' => 125.00,
            'latest_atr_14' => 5.00,
            'scaled_out_price' => 132.00,
            'scaled_out_quantity' => 5,
            'scaled_out_at' => now()->subDay(),
            'realized_pnl' => 60.00,
            'freeride_secured_at' => now()->subDay(),
            'initial_sl_placed_at' => now()->subDays(4),
            'execution_truth_state' => ExecutionTruthState::SyncedPartial,
            'data_source_label' => 'broker-synced',
            'broker' => Broker::Ibkr,
        ]);
    }

    private function seedOrderPlanAndRadarScouts(User $user, ?int $trampolineId): void
    {
        $strong = [
            'signal_low' => 101.00,
            'signal_high' => 103.50,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'latest_atr_14' => 2.80,
            'entry_price' => 103.50,
            'current_sl' => 98.50,
            'quantity' => 15,
            'last_setup_score' => 10,
            'trader_promoted_a_plus' => true,
            'trader_promoted_a_plus_at' => now(),
        ];

        // Setup Radar (strong A++)
        $this->upsertScout($user, 'CRM', array_merge($strong, [
            'strategy_tag_id' => $trampolineId,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'visibility' => PositionVisibility::Private,
        ]));

        $this->upsertScout($user, 'AVGO', array_merge($strong, [
            'strategy_tag_id' => $trampolineId,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'latest_close_price' => 101.20,
            'signal_low' => 101.20,
        ]));

        // Order Plan (pending / submitted)
        $this->upsertScout($user, 'SHOP', array_merge($strong, [
            'strategy_tag_id' => $trampolineId,
            'broker_order_status' => BrokerOrderStatus::Pending,
            'execution_truth_state' => ExecutionTruthState::SubmittedAtBroker,
            'broker_submitted_at' => now()->subMinutes(30),
            'data_source_label' => 'handmatig',
            'market_open_reminder_on' => null,
            'order_plan_excluded_on' => null,
        ]));

        $this->upsertScout($user, 'PLTR', array_merge($strong, [
            'strategy_tag_id' => $trampolineId,
            'broker_order_status' => BrokerOrderStatus::Pending,
            'execution_truth_state' => ExecutionTruthState::SubmittedAtBroker,
            'broker_submitted_at' => now()->subMinutes(10),
        ]));

        // Buy-stop review
        $this->upsertScout($user, 'COIN', array_merge($strong, [
            'strategy_tag_id' => $trampolineId,
            'broker_order_status' => BrokerOrderStatus::Pending,
            'buy_stop_review_required_on' => now('Europe/Amsterdam')->toDateString(),
            'buy_stop_review_setup_score' => 9,
            'buy_stop_review_setup_grade' => 'A',
        ]));
    }

    private function seedGapHerplanScout(User $user, ?int $tagId): void
    {
        $this->upsertScout($user, 'SOFI', [
            'strategy_tag_id' => $tagId,
            'signal_low' => 12.40,
            'signal_high' => 12.90,
            'latest_open_price' => 12.20,
            'latest_close_price' => 12.50,
            'latest_sma_20' => 12.30,
            'sma_20_ten_days_ago' => 11.80,
            'latest_sma_50' => 11.50,
            'scout_rsi' => 48.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.3,
            'sector_etf' => 'XLF',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 1.5,
            'latest_atr_14' => 0.55,
            'entry_price' => 12.90,
            'current_sl' => 12.00,
            'quantity' => 100,
            'broker_order_status' => BrokerOrderStatus::Pending,
            'execution_digest_status' => ExecutionDigestStatus::CancelledGapUp,
            'execution_digest_price' => 13.40,
            'execution_digest_at' => now()->subMinutes(5),
            'gap_herplan_action' => null,
            'broker_submitted_at' => now()->subHours(1),
            'execution_truth_state' => ExecutionTruthState::SubmittedAtBroker,
        ]);
    }

    private function seedClosedTradesForEdge(User $user, ?int $trampolineId): void
    {
        $tickers = ['GOOGL', 'AMZN', 'NFLX', 'ORCL', 'INTC', 'QCOM', 'MU', 'RIVN', 'DEMO1', 'DEMO2'];
        $protocol = app(ProtocolComplianceService::class);

        foreach ($tickers as $i => $ticker) {
            $win = $i % 3 !== 0;
            $entry = 50 + ($i * 7);
            $exit = $win ? $entry * 1.08 : $entry * 0.96;
            $tagId = $trampolineId;
            $grade = match ($i % 4) {
                0 => 'A++',
                1 => 'A',
                2 => 'B',
                default => 'A',
            };

            $position = $this->upsertClosed($user, $ticker, [
                'strategy_tag_id' => $tagId,
                'entry_price' => round($entry, 2),
                'exit_price' => round($exit, 2),
                'quantity' => 10 + $i,
                'initial_sl' => round($entry * 0.95, 2),
                'current_sl' => $win ? round($entry, 2) : round($entry * 0.95, 2),
                'latest_close_price' => round($exit, 2),
                'closed_at' => now()->subDays(20 - $i),
                'initial_sl_placed_at' => now()->subDays(25 - $i),
                'freeride_secured_at' => $win ? now()->subDays(22 - $i) : null,
                'scaled_out_at' => $win && $i % 2 === 0 ? now()->subDays(21 - $i) : null,
                'scaled_out_price' => $win && $i % 2 === 0 ? round($entry * 1.04, 2) : null,
                'scaled_out_quantity' => $win && $i % 2 === 0 ? 5 : null,
                'trade_journal' => $win ? "Demo win {$grade}" : "Demo loss {$grade}",
                'last_setup_score' => $grade === 'A++' ? 10 : ($grade === 'A' ? 9 : 7),
                'trader_promoted_a' => in_array($grade, ['A++', 'A'], true),
                'trader_promoted_a_plus' => $grade === 'A++',
                'buy_stop_review_setup_grade' => $grade,
                'execution_truth_state' => ExecutionTruthState::Closed,
                'data_source_label' => 'handmatig',
                'broker' => Broker::Ibkr,
                'direction' => TradeDirection::Long,
            ]);

            // Store setup grade via last_setup_score; protocol score
            $protocol->persistForClosed($position->fresh());
        }

        // Extra closed trades to approach Coach unlock (20)
        for ($n = 1; $n <= 12; $n++) {
            $ticker = 'X'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            Asset::query()->firstOrCreate(['ticker' => $ticker], ['fetched_at' => now()]);
            $entry = 30 + $n;
            $win = $n % 2 === 0;
            $position = $this->upsertClosed($user, $ticker, [
                'strategy_tag_id' => $trampolineId,
                'entry_price' => $entry,
                'exit_price' => $win ? $entry + 3 : $entry - 2,
                'quantity' => 8,
                'initial_sl' => $entry - 2,
                'current_sl' => $win ? $entry : $entry - 2,
                'latest_close_price' => $win ? $entry + 3 : $entry - 2,
                'closed_at' => now()->subDays(40 - $n),
                'initial_sl_placed_at' => now()->subDays(45 - $n),
                'trade_journal' => 'Bulk demo trade',
                'execution_truth_state' => ExecutionTruthState::Closed,
                'broker' => Broker::Ibkr,
            ]);
            $protocol->persistForClosed($position->fresh());
        }
    }

    private function seedBankrollSnapshots(User $user): void
    {
        $points = [
            ['on' => now()->subWeeks(3)->toDateString(), 'amount' => 22000, 'spy' => 510],
            ['on' => now()->subWeeks(2)->toDateString(), 'amount' => 23500, 'spy' => 518],
            ['on' => now()->subWeek()->toDateString(), 'amount' => 24800, 'spy' => 525],
            ['on' => now()->toDateString(), 'amount' => 25000, 'spy' => 528],
        ];

        foreach ($points as $point) {
            BankrollSnapshot::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'recorded_on' => Carbon::parse($point['on'], 'Europe/Amsterdam')->startOfDay(),
                ],
                [
                    'amount' => $point['amount'],
                    'benchmark_ticker' => 'SPY',
                    'benchmark_close' => $point['spy'],
                    'recorded_at' => Carbon::parse($point['on'])->setTime(12, 0),
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function upsertOpen(User $user, string $ticker, array $attrs): Position
    {
        $asset = Asset::query()->where('ticker', $ticker)->first();

        $position = Position::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'ticker' => $ticker,
                'status' => 'open',
            ],
            array_merge([
                'asset_id' => $asset?->id,
                'visibility' => PositionVisibility::Private,
                'direction' => TradeDirection::Long,
                'is_legacy' => false,
                'broker' => Broker::Ibkr,
            ], $attrs),
        );

        return $position;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function upsertScout(User $user, string $ticker, array $attrs): Position
    {
        $asset = Asset::query()->where('ticker', $ticker)->first();

        return Position::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'ticker' => $ticker,
                'status' => 'scout',
            ],
            array_merge([
                'asset_id' => $asset?->id,
                'visibility' => PositionVisibility::Private,
                'direction' => TradeDirection::Long,
                'is_legacy' => false,
                'broker' => Broker::Ibkr,
                'broker_order_status' => BrokerOrderStatus::Scout,
                'source' => null,
                'review_status' => null,
                'execution_truth_state' => ExecutionTruthState::Planned,
                'data_source_label' => 'planned',
            ], $attrs),
        );
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function upsertClosed(User $user, string $ticker, array $attrs): Position
    {
        $asset = Asset::query()->where('ticker', $ticker)->first();

        return Position::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'ticker' => $ticker,
                'status' => 'closed',
            ],
            array_merge([
                'asset_id' => $asset?->id,
                'visibility' => PositionVisibility::Private,
                'direction' => TradeDirection::Long,
                'is_legacy' => false,
                'broker' => Broker::Ibkr,
                'status' => 'closed',
            ], $attrs),
        );
    }
}
