<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('ui_preferences')->nullable()->after('is_short_enabled');
        });

        Schema::table('positions', function (Blueprint $table): void {
            $table->string('execution_truth_state')->nullable()->after('broker_order_status');
            $table->timestamp('broker_submitted_at')->nullable()->after('execution_truth_state');
            $table->string('data_source_label')->nullable()->after('broker_submitted_at');
            $table->json('sniper_reject_reasons')->nullable()->after('last_setup_score');
            $table->unsignedTinyInteger('protocol_score')->nullable()->after('risk_reward_ratio');
            $table->json('protocol_score_details')->nullable()->after('protocol_score');
            $table->string('gap_herplan_action')->nullable()->after('execution_digest_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ui_preferences');
        });

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn([
                'execution_truth_state',
                'broker_submitted_at',
                'data_source_label',
                'sniper_reject_reasons',
                'protocol_score',
                'protocol_score_details',
                'gap_herplan_action',
            ]);
        });
    }
};
