<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->renameColumn('previous_sma_20', 'sma_20_five_days_ago');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('latest_sma_50', 8, 2)->nullable()->after('latest_sma_20');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('latest_sma_50');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->renameColumn('sma_20_five_days_ago', 'previous_sma_20');
        });
    }
};
