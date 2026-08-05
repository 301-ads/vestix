<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->date('last_earnings_date')->nullable()->after('next_earnings_hour');
            $table->string('last_earnings_hour', 10)->nullable()->after('last_earnings_date');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn([
                'last_earnings_date',
                'last_earnings_hour',
            ]);
        });
    }
};
