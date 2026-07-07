<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->decimal('relative_volume', 6, 2)->nullable()->after('avg_volume_30d');
            $table->unsignedBigInteger('volume_sma_20')->nullable()->after('relative_volume');
            $table->string('sector_etf', 10)->nullable()->after('volume_sma_20');
            $table->string('sector_etf_override', 10)->nullable()->after('sector_etf');
            $table->decimal('sector_close', 12, 4)->nullable()->after('sector_etf_override');
            $table->decimal('sector_sma_50', 12, 4)->nullable()->after('sector_close');
            $table->boolean('sector_trend_positive')->default(false)->after('sector_sma_50');
            $table->decimal('pre_bounce_extension_atr', 5, 2)->nullable()->after('sector_trend_positive');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn([
                'relative_volume',
                'volume_sma_20',
                'sector_etf',
                'sector_etf_override',
                'sector_close',
                'sector_sma_50',
                'sector_trend_positive',
                'pre_bounce_extension_atr',
            ]);
        });
    }
};
