<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->string('premarket_scan_type', 20)->nullable()->after('premarket_price');
            $table->decimal('premarket_reference_price', 10, 2)->nullable()->after('premarket_scan_type');
            $table->decimal('premarket_distance_pct', 8, 4)->nullable()->after('premarket_reference_price');
        });

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn([
                'armed_for_entry_on',
                'premarket_entry_trigger',
                'premarket_gap_status',
                'premarket_gap_pct',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->date('armed_for_entry_on')->nullable()->after('telegram_a_plus_alert_sent_at');
            $table->decimal('premarket_entry_trigger', 10, 2)->nullable()->after('premarket_price');
            $table->string('premarket_gap_status', 20)->nullable()->after('premarket_entry_trigger');
            $table->decimal('premarket_gap_pct', 8, 4)->nullable()->after('premarket_gap_status');
        });

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn([
                'premarket_scan_type',
                'premarket_reference_price',
                'premarket_distance_pct',
            ]);
        });
    }
};
