<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('trading_bankroll', 12, 2)->nullable()->after('default_risk_per_trade');
            $table->decimal('default_risk_percent', 4, 2)->nullable()->after('trading_bankroll');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('risk_percent', 4, 2)->nullable()->after('risk_budget');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['trading_bankroll', 'default_risk_percent']);
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('risk_percent');
        });
    }
};
