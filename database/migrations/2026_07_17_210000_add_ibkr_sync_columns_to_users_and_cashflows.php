<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('ibkr_net_liquidation', 12, 2)->nullable()->after('trading_bankroll');
            $table->decimal('ibkr_available_funds', 12, 2)->nullable()->after('ibkr_net_liquidation');
            $table->decimal('ibkr_settled_cash', 12, 2)->nullable()->after('ibkr_available_funds');
            $table->string('ibkr_base_currency', 3)->nullable()->after('ibkr_settled_cash');
            $table->json('ibkr_open_positions')->nullable()->after('ibkr_base_currency');
            $table->json('ibkr_open_orders')->nullable()->after('ibkr_open_positions');
            $table->timestamp('ibkr_last_success_at')->nullable()->after('ibkr_open_orders');
            $table->timestamp('ibkr_last_attempt_at')->nullable()->after('ibkr_last_success_at');
            $table->text('ibkr_last_error')->nullable()->after('ibkr_last_attempt_at');
            $table->boolean('ibkr_data_stale')->default(false)->after('ibkr_last_error');
        });

        Schema::table('bankroll_cashflows', function (Blueprint $table): void {
            $table->string('external_id', 64)->nullable()->after('source');
            $table->unique(['user_id', 'source', 'external_id'], 'bankroll_cashflows_user_source_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bankroll_cashflows', function (Blueprint $table): void {
            $table->dropUnique('bankroll_cashflows_user_source_external_unique');
            $table->dropColumn('external_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'ibkr_net_liquidation',
                'ibkr_available_funds',
                'ibkr_settled_cash',
                'ibkr_base_currency',
                'ibkr_open_positions',
                'ibkr_open_orders',
                'ibkr_last_success_at',
                'ibkr_last_attempt_at',
                'ibkr_last_error',
                'ibkr_data_stale',
            ]);
        });
    }
};
