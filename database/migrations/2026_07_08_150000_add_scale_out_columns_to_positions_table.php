<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('scaled_out_price', 8, 2)->nullable()->after('freeride_secured_at');
            $table->decimal('scaled_out_quantity', 12, 6)->nullable()->after('scaled_out_price');
            $table->timestamp('scaled_out_at')->nullable()->after('scaled_out_quantity');
            $table->decimal('realized_pnl', 10, 2)->nullable()->after('scaled_out_at');
            $table->decimal('target_1_rr', 6, 4)->nullable()->after('realized_pnl');
            $table->decimal('first_tranche_fraction', 5, 4)->nullable()->after('target_1_rr');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn([
                'scaled_out_price',
                'scaled_out_quantity',
                'scaled_out_at',
                'realized_pnl',
                'target_1_rr',
                'first_tranche_fraction',
            ]);
        });
    }
};
