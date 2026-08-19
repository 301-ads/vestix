<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'primary_broker')) {
            DB::table('users')->update(['primary_broker' => 'ibkr']);
        }

        if (Schema::hasColumn('positions', 'broker')) {
            DB::table('positions')->update(['broker' => 'ibkr']);
        }
    }

    public function down(): void
    {
        // Irreversible: prior broker tags are not preserved.
    }
};
