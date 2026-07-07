<?php

use App\Models\StrategyTag;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        StrategyTag::query()
            ->where('slug', '!=', 'trampoline-bounce')
            ->update(['is_active' => false]);

        StrategyTag::query()
            ->where('slug', 'trampoline-bounce')
            ->update(['is_active' => true, 'sort_order' => 1]);
    }

    public function down(): void
    {
        StrategyTag::query()->update(['is_active' => true]);
    }
};
