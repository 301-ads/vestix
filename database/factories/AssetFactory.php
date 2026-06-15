<?php

namespace Database\Factories;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        $ticker = strtoupper(fake()->lexify('???'));

        return [
            'ticker' => $ticker,
            'company_name' => fake()->company(),
            'icon_path' => "ticker-logos/{$ticker}.png",
            'fetched_at' => now(),
        ];
    }

    public function withoutIcon(): static
    {
        return $this->state(fn (): array => [
            'icon_path' => null,
            'logo_path' => null,
            'fetched_at' => null,
        ]);
    }
}
