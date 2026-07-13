<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->decimal('sma_20_ten_days_ago', 12, 2)->nullable()->after('sma_20_five_days_ago');
            $table->boolean('trader_promoted_a_plus')->default(false)->after('telegram_a_plus_alert_sent_at');
            $table->timestamp('trader_promoted_a_plus_at')->nullable()->after('trader_promoted_a_plus');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn([
                'sma_20_ten_days_ago',
                'trader_promoted_a_plus',
                'trader_promoted_a_plus_at',
            ]);
        });
    }
};
