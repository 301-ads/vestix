<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->timestamp('initial_sl_placed_at')->nullable()->after('initial_sl');
        });

        DB::table('positions')
            ->where('status', 'open')
            ->whereNull('initial_sl_placed_at')
            ->update(['initial_sl_placed_at' => DB::raw('COALESCE(updated_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('initial_sl_placed_at');
        });
    }
};
