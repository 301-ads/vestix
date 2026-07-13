<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bankroll_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('benchmark_ticker', 10)->default('SPY');
            $table->decimal('benchmark_close', 10, 4)->nullable();
            $table->date('recorded_on');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['user_id', 'recorded_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bankroll_snapshots');
    }
};
