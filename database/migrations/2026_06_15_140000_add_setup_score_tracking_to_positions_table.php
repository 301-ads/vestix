<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('last_setup_score')->nullable()->after('bounce_volume_above_average');
            $table->timestamp('telegram_a_minus_alert_sent_at')->nullable()->after('last_setup_score');
            $table->timestamp('telegram_a_plus_alert_sent_at')->nullable()->after('telegram_a_minus_alert_sent_at');
            $table->unsignedBigInteger('bounce_day_volume')->nullable()->after('telegram_a_plus_alert_sent_at');
            $table->unsignedBigInteger('avg_volume_30d')->nullable()->after('bounce_day_volume');

            $table->dropColumn('telegram_alert_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->timestamp('telegram_alert_sent_at')->nullable()->after('bounce_volume_above_average');

            $table->dropColumn([
                'last_setup_score',
                'telegram_a_minus_alert_sent_at',
                'telegram_a_plus_alert_sent_at',
                'bounce_day_volume',
                'avg_volume_30d',
            ]);
        });
    }
};
