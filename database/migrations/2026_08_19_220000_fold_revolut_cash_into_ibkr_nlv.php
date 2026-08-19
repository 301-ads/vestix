<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'revolut_cash')) {
            return;
        }

        $users = DB::table('users')
            ->whereNotNull('revolut_cash')
            ->where('revolut_cash', '>', 0)
            ->get(['id', 'ibkr_net_liquidation', 'revolut_cash']);

        foreach ($users as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'ibkr_net_liquidation' => round(
                    max(0.0, (float) ($user->ibkr_net_liquidation ?? 0)) + (float) $user->revolut_cash,
                    2,
                ),
                'revolut_cash' => 0,
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible: folded cash cannot be split from NLV reliably.
    }
};
