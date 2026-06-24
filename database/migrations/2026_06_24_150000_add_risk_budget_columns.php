<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('default_risk_per_trade', 10, 2)->nullable()->after('telegram_link_token');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('risk_budget', 10, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('default_risk_per_trade');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('risk_budget');
        });
    }
};
