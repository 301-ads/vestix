<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            if (! Schema::hasColumn('positions', 'latest_sma_200')) {
                $table->decimal('latest_sma_200', 12, 4)->nullable()->after('latest_sma_50');
            }

            if (! Schema::hasColumn('positions', 'autopsy_tag')) {
                $table->string('autopsy_tag', 40)->nullable()->after('trade_journal');
            }
        });

        Schema::table('leaderboard_stats', function (Blueprint $table) {
            if (! Schema::hasColumn('leaderboard_stats', 'total_r')) {
                $table->decimal('total_r', 10, 2)->default(0)->after('avg_roi_pct');
            }

            if (! Schema::hasColumn('leaderboard_stats', 'avg_r')) {
                $table->decimal('avg_r', 10, 2)->default(0)->after('total_r');
            }

            if (! Schema::hasColumn('leaderboard_stats', 'discipline_score_30d')) {
                $table->decimal('discipline_score_30d', 5, 2)->default(0)->after('avg_r');
            }
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            if (Schema::hasColumn('positions', 'autopsy_tag')) {
                $table->dropColumn('autopsy_tag');
            }

            if (Schema::hasColumn('positions', 'latest_sma_200')) {
                $table->dropColumn('latest_sma_200');
            }
        });

        Schema::table('leaderboard_stats', function (Blueprint $table) {
            foreach (['discipline_score_30d', 'avg_r', 'total_r'] as $column) {
                if (Schema::hasColumn('leaderboard_stats', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
