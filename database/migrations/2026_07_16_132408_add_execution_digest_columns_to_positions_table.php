<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('execution_digest_status', 32)->nullable()->after('market_open_reminder_on');
            $table->string('execution_digest_reason')->nullable()->after('execution_digest_status');
            $table->decimal('execution_digest_price', 12, 4)->nullable()->after('execution_digest_reason');
            $table->timestamp('execution_digest_at')->nullable()->after('execution_digest_price');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn([
                'execution_digest_status',
                'execution_digest_reason',
                'execution_digest_price',
                'execution_digest_at',
            ]);
        });
    }
};
