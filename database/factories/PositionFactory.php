<?php

namespace Database\Factories;

use App\Models\Position;
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
            'ticker' => strtoupper(fake()->lexify('???')),
            'entry_price' => fake()->randomFloat(2, 50, 200),
            'quantity' => fake()->numberBetween(1, 100),
            'current_sl' => fake()->randomFloat(2, 40, 150),
            'latest_close_price' => fake()->randomFloat(2, 50, 200),
            'latest_sma_20' => fake()->randomFloat(2, 50, 200),
            'latest_sma_50' => fake()->randomFloat(2, 40, 190),
            'sma_20_five_days_ago' => fake()->randomFloat(2, 50, 200),
            'latest_atr_14' => fake()->randomFloat(2, 1, 10),
            'scout_rsi' => fake()->randomFloat(2, 40, 65),
            'bounce_volume_above_average' => false,
            'status' => 'open',
        ];
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
            'entry_price' => null,
            'quantity' => null,
            'current_sl' => null,
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
