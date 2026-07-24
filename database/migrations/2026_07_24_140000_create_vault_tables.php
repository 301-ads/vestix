<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('etf_ticker', 32)->default('VWCE');
            $table->decimal('default_monthly_budget', 12, 2)->default(10000);
            $table->decimal('dry_powder_balance', 12, 2)->default(0);
            $table->decimal('overheat_threshold_pct', 8, 2)->default(10);
            $table->decimal('crash_threshold_pct', 8, 2)->default(10);
            $table->decimal('overheat_invest_fraction', 5, 4)->default(0.5);
            $table->decimal('dip_dry_powder_fraction', 5, 4)->default(0.25);
            $table->decimal('crash_dry_powder_fraction', 5, 4)->default(0.5);
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('vault_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('period_month');
            $table->string('climate', 32);
            $table->decimal('deviation_pct', 8, 4)->nullable();
            $table->decimal('budget_input', 12, 2);
            $table->decimal('etf_amount', 12, 2);
            $table->decimal('dry_powder_delta', 12, 2);
            $table->decimal('dry_powder_after', 12, 2);
            $table->decimal('etf_price', 12, 4)->nullable();
            $table->decimal('sma_200', 12, 4)->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['user_id', 'period_month']);
            $table->index(['user_id', 'confirmed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_deposits');
        Schema::dropIfExists('vault_settings');
    }
};
