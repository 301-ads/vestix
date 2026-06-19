<?php

use Database\Seeders\StrategyTagSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('strategy_tags')) {
            return;
        }

        (new StrategyTagSeeder)->run();
    }

    public function down(): void
    {
        // Reference data — niet verwijderen bij rollback.
    }
};
