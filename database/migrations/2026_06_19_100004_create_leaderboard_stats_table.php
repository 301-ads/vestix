<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboard_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('squad_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('win_rate', 5, 2)->default(0);
            $table->decimal('avg_roi_pct', 8, 2)->default(0);
            $table->unsignedInteger('freeride_count')->default(0);
            $table->unsignedInteger('closed_trades_count')->default(0);
            $table->unsignedInteger('rank')->default(0);
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['squad_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_stats');
    }
};
