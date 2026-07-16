<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->boolean('trader_promoted_a')->default(false)->after('trader_promoted_a_plus_at');
            $table->timestamp('trader_promoted_a_at')->nullable()->after('trader_promoted_a');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn([
                'trader_promoted_a',
                'trader_promoted_a_at',
            ]);
        });
    }
};
