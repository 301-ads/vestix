<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('previous_sma_20', 8, 2)->nullable()->after('latest_sma_20');
            $table->decimal('scout_rsi', 5, 2)->nullable()->after('latest_atr_14');
            $table->boolean('bounce_volume_above_average')->default(false)->after('scout_rsi');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn([
                'previous_sma_20',
                'scout_rsi',
                'bounce_volume_above_average',
            ]);
        });
    }
};
