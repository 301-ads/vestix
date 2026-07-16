<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bankroll_cashflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->date('occurred_on');
            $table->string('note')->nullable();
            $table->string('source', 20)->default('manual');
            $table->timestamps();

            $table->index(['user_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bankroll_cashflows');
    }
};
