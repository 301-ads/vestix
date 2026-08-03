<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->index(['user_id', 'status', 'is_legacy'], 'positions_user_status_legacy_index');
            $table->index('buy_stop_review_required_on', 'positions_buy_stop_review_required_on_index');
            $table->index('broker_order_status', 'positions_broker_order_status_index');
            $table->index('market_open_reminder_on', 'positions_market_open_reminder_on_index');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropIndex('positions_user_status_legacy_index');
            $table->dropIndex('positions_buy_stop_review_required_on_index');
            $table->dropIndex('positions_broker_order_status_index');
            $table->dropIndex('positions_market_open_reminder_on_index');
        });
    }
};
