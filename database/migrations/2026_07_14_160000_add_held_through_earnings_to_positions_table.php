<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->date('held_through_earnings_date')->nullable()->after('initial_sl_placed_at');
            $table->timestamp('held_through_earnings_at')->nullable()->after('held_through_earnings_date');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn([
                'held_through_earnings_date',
                'held_through_earnings_at',
            ]);
        });
    }
};
