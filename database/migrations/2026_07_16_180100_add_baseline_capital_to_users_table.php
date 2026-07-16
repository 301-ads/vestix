<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('baseline_capital', 12, 2)->nullable()->after('trading_bankroll');
            $table->date('baseline_date')->nullable()->after('baseline_capital');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['baseline_capital', 'baseline_date']);
        });
    }
};
