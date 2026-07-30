<?php

use App\Models\StrategyTag;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        StrategyTag::query()->where('slug', 'ema-200-bounce')->update(['is_active' => false]);

        StrategyTag::query()->where('slug', 'trampoline-bounce')->update([
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        StrategyTag::query()->where('slug', 'ema-200-bounce')->update(['is_active' => true]);
    }
};
