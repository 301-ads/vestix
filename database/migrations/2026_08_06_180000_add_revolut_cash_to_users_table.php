<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'revolut_cash')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('revolut_cash', 12, 2)->nullable()->after('trading_bankroll');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'revolut_cash')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('revolut_cash');
        });
    }
};
