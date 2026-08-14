<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('post_signal_high', 8, 2)->nullable()->after('detected_signal_bar_date');
            $table->decimal('post_signal_low', 8, 2)->nullable()->after('post_signal_high');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['post_signal_high', 'post_signal_low']);
        });
    }
};
