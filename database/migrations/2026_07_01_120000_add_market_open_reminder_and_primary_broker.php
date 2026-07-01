<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->date('market_open_reminder_on')->nullable()->after('broker_order_status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('primary_broker', 20)->nullable()->default('revolut')->after('default_risk_percent');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('market_open_reminder_on');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('primary_broker');
        });
    }
};
