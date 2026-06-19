<?php

namespace Tests;

use App\Models\StrategyTag;
use Database\Seeders\StrategyTagSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithSquads;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithSquads;

    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('strategy_tags') && StrategyTag::query()->count() === 0) {
            $this->seed(StrategyTagSeeder::class);
        }
    }

    protected function defaultStrategyTagId(): int
    {
        return (int) StrategyTag::query()->orderBy('sort_order')->value('id');
    }
}
