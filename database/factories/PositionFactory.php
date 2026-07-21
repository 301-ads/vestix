<?php

namespace Database\Factories;

use App\Enums\BrokerOrderStatus;
use App\Enums\PositionVisibility;
use App\Enums\TradeDirection;
use App\Models\Position;
use App\Models\StrategyTag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'strategy_tag_id' => StrategyTag::query()->orderBy('sort_order')->value('id'),
            'ticker' => strtoupper(fake()->lexify('???')),
            'entry_price' => fake()->randomFloat(2, 50, 200),
            'quantity' => fake()->numberBetween(1, 100),
            'current_sl' => fake()->randomFloat(2, 40, 150),
            'latest_close_price' => fake()->randomFloat(2, 50, 200),
            'latest_sma_20' => fake()->randomFloat(2, 50, 200),
            'latest_sma_50' => fake()->randomFloat(2, 40, 190),
            'sma_20_five_days_ago' => fake()->randomFloat(2, 50, 200),
            'sma_20_ten_days_ago' => fake()->randomFloat(2, 50, 200),
            'latest_atr_14' => fake()->randomFloat(2, 1, 10),
            'scout_rsi' => fake()->randomFloat(2, 40, 65),
            'bounce_volume_above_average' => false,
            'visibility' => PositionVisibility::Private,
            'status' => 'open',
            'direction' => TradeDirection::Long,
            'is_legacy' => false,
            'initial_sl_placed_at' => now(),
        ];
    }

    public function legacy(): static
    {
        return $this->state(fn (): array => [
            'is_legacy' => true,
        ]);
    }

    public function awaitingInitialSlPlacement(): static
    {
        return $this->state(fn (): array => [
            'initial_sl_placed_at' => null,
        ]);
    }

    public function closed(): static
    {
        return $this->state(function (array $attributes): array {
            $exitPrice = $attributes['exit_price'] ?? fake()->randomFloat(2, 40, 150);

            return [
                'status' => 'closed',
                'exit_price' => $exitPrice,
                'closed_at' => now(),
            ];
        });
    }

    public function scout(): static
    {
        return $this->state(fn (): array => [
            'status' => 'scout',
            'broker_order_status' => BrokerOrderStatus::Scout,
            'entry_price' => null,
            'quantity' => null,
            'current_sl' => null,
            'initial_sl_placed_at' => null,
            'signal_high' => null,
            'signal_low' => null,
        ]);
    }

    public function short(): static
    {
        return $this->state(fn (): array => [
            'direction' => TradeDirection::Short,
        ]);
    }

    public function pendingBrokerOrder(): static
    {
        return $this->state(fn (): array => [
            'broker_order_status' => BrokerOrderStatus::Pending,
        ]);
    }

    public function requiringBuyStopReview(): static
    {
        return $this->state(fn (): array => [
            'buy_stop_review_required_on' => now()->toDateString(),
            'buy_stop_review_setup_score' => 8,
            'buy_stop_review_setup_grade' => 'A',
        ]);
    }

    public function squadShared(): static
    {
        return $this->state(fn (): array => [
            'visibility' => PositionVisibility::Squad,
        ]);
    }

    public function withChartScreenshots(): static
    {
        return $this->state(fn (): array => [
            'entry_chart_screenshot_path' => 'position-charts/entry-test.jpg',
            'exit_chart_screenshot_path' => 'position-charts/exit-test.jpg',
        ]);
    }
}
