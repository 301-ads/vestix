<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Correct over-aggressive clean slate: restore active work to the live UI.
        DB::table('positions')
            ->whereIn('status', ['open', 'scout'])
            ->update(['is_legacy' => false]);
    }

    public function down(): void
    {
        // No-op: cannot safely re-hide active positions.
    }
};
