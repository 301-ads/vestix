<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('entry_price', 8, 2)->nullable()->change();
            $table->decimal('quantity', 16, 6)->nullable()->change();
            $table->decimal('current_sl', 8, 2)->nullable()->change();
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE positions MODIFY COLUMN status ENUM('scout', 'open', 'closed') NOT NULL DEFAULT 'open'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE positions MODIFY COLUMN status ENUM('open', 'closed') NOT NULL DEFAULT 'open'");
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('entry_price', 8, 2)->nullable(false)->change();
            $table->decimal('quantity', 16, 6)->nullable(false)->change();
            $table->decimal('current_sl', 8, 2)->nullable(false)->change();
        });
    }
};
