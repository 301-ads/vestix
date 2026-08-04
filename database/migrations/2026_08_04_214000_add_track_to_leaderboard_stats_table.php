<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaderboard_stats', function (Blueprint $table) {
            $table->string('track', 20)->default('executor')->after('user_id');
        });

        Schema::table('leaderboard_stats', function (Blueprint $table) {
            $table->dropUnique(['squad_id', 'user_id']);
            $table->unique(['squad_id', 'user_id', 'track']);
        });
    }

    public function down(): void
    {
        Schema::table('leaderboard_stats', function (Blueprint $table) {
            $table->dropUnique(['squad_id', 'user_id', 'track']);
            $table->unique(['squad_id', 'user_id']);
            $table->dropColumn('track');
        });
    }
};
