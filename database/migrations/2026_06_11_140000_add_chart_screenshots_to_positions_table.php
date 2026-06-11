<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('entry_chart_screenshot_path')->nullable()->after('trade_journal');
            $table->string('exit_chart_screenshot_path')->nullable()->after('entry_chart_screenshot_path');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn([
                'entry_chart_screenshot_path',
                'exit_chart_screenshot_path',
            ]);
        });
    }
};
