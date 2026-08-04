<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->unsignedTinyInteger('entry_setup_score')->nullable()->after('last_setup_score');
            $table->string('entry_setup_grade', 20)->nullable()->after('entry_setup_score');
            $table->boolean('entry_setup_promoted_a')->nullable()->after('entry_setup_grade');
            $table->boolean('entry_setup_promoted_a_plus')->nullable()->after('entry_setup_promoted_a');
            $table->timestamp('entry_setup_captured_at')->nullable()->after('entry_setup_promoted_a_plus');
            $table->string('entry_setup_source', 40)->nullable()->after('entry_setup_captured_at');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn([
                'entry_setup_score',
                'entry_setup_grade',
                'entry_setup_promoted_a',
                'entry_setup_promoted_a_plus',
                'entry_setup_captured_at',
                'entry_setup_source',
            ]);
        });
    }
};
