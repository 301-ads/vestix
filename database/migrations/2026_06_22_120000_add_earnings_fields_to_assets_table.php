<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->date('next_earnings_date')->nullable()->after('fetched_at');
            $table->string('next_earnings_hour', 10)->nullable()->after('next_earnings_date');
            $table->date('earnings_date_override')->nullable()->after('next_earnings_hour');
            $table->string('earnings_hour_override', 10)->nullable()->after('earnings_date_override');
            $table->timestamp('earnings_fetched_at')->nullable()->after('earnings_hour_override');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropColumn([
                'next_earnings_date',
                'next_earnings_hour',
                'earnings_date_override',
                'earnings_hour_override',
                'earnings_fetched_at',
            ]);
        });
    }
};
