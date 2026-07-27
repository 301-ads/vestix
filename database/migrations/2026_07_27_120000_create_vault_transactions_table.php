<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vault_deposit_id')->nullable()->constrained('vault_deposits')->nullOnDelete();
            $table->timestamp('traded_at');
            $table->decimal('shares', 16, 6);
            $table->decimal('fill_price', 12, 4);
            $table->decimal('etf_amount', 12, 2);
            $table->decimal('fee', 12, 2)->default(0);
            $table->string('source', 32);
            $table->string('ticker', 32)->default('VWCE');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'traded_at']);
            $table->index(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_transactions');
    }
};
