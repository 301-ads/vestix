<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_short_enabled')->default(false)->after('primary_broker');
        });

        Schema::table('positions', function (Blueprint $table): void {
            $table->string('direction', 10)->default('long')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn('direction');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_short_enabled');
        });
    }
};
