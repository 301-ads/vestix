<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->date('signal_bar_date')->nullable()->after('signal_low');
            $table->date('detected_signal_bar_date')->nullable()->after('signal_bar_date');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['signal_bar_date', 'detected_signal_bar_date']);
        });
    }
};
