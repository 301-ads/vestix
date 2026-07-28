<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sniper_daily_bars', function (Blueprint $table) {
            $table->id();
            $table->string('ticker', 16)->index();
            $table->date('date');
            $table->decimal('open', 16, 6);
            $table->decimal('high', 16, 6);
            $table->decimal('low', 16, 6);
            $table->decimal('close', 16, 6);
            $table->unsignedBigInteger('volume');
            $table->timestamps();

            $table->unique(['ticker', 'date']);
        });

        Schema::create('sniper_liquidity_cache', function (Blueprint $table) {
            $table->string('ticker', 16)->primary();
            $table->string('asset_type', 16)->nullable()->index();
            $table->unsignedBigInteger('avg_volume_30d')->nullable();
            $table->unsignedBigInteger('last_volume')->nullable();
            $table->decimal('market_cap', 20, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('bars_ready')->default(false)->index();
            $table->date('metrics_as_of')->nullable();
            $table->timestamp('market_cap_fetched_at')->nullable();
            $table->timestamp('split_purged_at')->nullable();
            $table->timestamps();
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('status')->index();
            $table->string('review_status', 32)->nullable()->after('source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['source', 'review_status']);
        });

        Schema::dropIfExists('sniper_liquidity_cache');
        Schema::dropIfExists('sniper_daily_bars');
    }
};
