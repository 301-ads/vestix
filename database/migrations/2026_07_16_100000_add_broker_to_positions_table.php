<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'broker')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->string('broker', 20)->nullable()->after('broker_order_status');
                $table->index('broker');
            });
        }

        $positions = DB::table('positions')
            ->whereNull('broker')
            ->get(['id', 'user_id']);

        foreach ($positions as $position) {
            $broker = DB::table('users')
                ->where('id', $position->user_id)
                ->value('primary_broker');

            DB::table('positions')
                ->where('id', $position->id)
                ->update(['broker' => $broker ?? 'revolut']);
        }
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex(['broker']);
            $table->dropColumn('broker');
        });
    }
};
