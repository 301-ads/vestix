<?php

namespace Database\Seeders;

use App\Models\StrategyTag;
use Illuminate\Database\Seeder;

class StrategyTagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['slug' => 'ema-200-bounce', 'name' => 'EMA 200 Bounce', 'sort_order' => 1, 'is_active' => false],
            ['slug' => 'breakout', 'name' => 'Breakout', 'sort_order' => 2, 'is_active' => false],
            ['slug' => 'earnings-play', 'name' => 'Earnings Play', 'sort_order' => 3, 'is_active' => false],
            ['slug' => 'mean-reversion', 'name' => 'Mean Reversion', 'sort_order' => 4, 'is_active' => false],
            ['slug' => 'trampoline-bounce', 'name' => 'Trampoline Bounce', 'sort_order' => 1, 'is_active' => true],
        ];

        foreach ($tags as $tag) {
            StrategyTag::query()->updateOrCreate(
                ['slug' => $tag['slug']],
                [
                    'name' => $tag['name'],
                    'sort_order' => $tag['sort_order'],
                    'is_active' => $tag['is_active'],
                ],
            );
        }
    }
}
