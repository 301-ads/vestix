<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->timestamp('freeride_secured_at')->nullable()->after('closed_at');
            $table->foreignId('strategy_tag_id')->nullable()->after('trade_journal')->constrained('strategy_tags')->nullOnDelete();
            $table->decimal('initial_sl', 8, 2)->nullable()->after('current_sl');
            $table->decimal('risk_reward_ratio', 8, 4)->nullable()->after('exit_price');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('strategy_tag_id');
            $table->dropColumn(['freeride_secured_at', 'initial_sl', 'risk_reward_ratio']);
        });
    }
};
