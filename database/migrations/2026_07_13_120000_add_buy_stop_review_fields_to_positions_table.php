<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->date('buy_stop_review_required_on')->nullable()->after('market_open_reminder_on');
            $table->unsignedTinyInteger('buy_stop_review_setup_score')->nullable()->after('buy_stop_review_required_on');
            $table->string('buy_stop_review_setup_grade', 20)->nullable()->after('buy_stop_review_setup_score');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn([
                'buy_stop_review_required_on',
                'buy_stop_review_setup_score',
                'buy_stop_review_setup_grade',
            ]);
        });
    }
};
