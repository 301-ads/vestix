<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->boolean('is_legacy')->default(false)->after('status');
            $table->index('is_legacy');
        });

        // Vestix 2.0: alleen gesloten (oude) trades naar Legacy Archief.
        // Open posities en scouts blijven actief in het live dashboard.
        DB::table('positions')->where('status', 'closed')->update(['is_legacy' => true]);
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropIndex(['is_legacy']);
            $table->dropColumn('is_legacy');
        });
    }
};
