<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('ticker')->index();
            $table->decimal('entry_price', 8, 2);
            $table->integer('quantity');
            $table->decimal('current_sl', 8, 2);

            $table->decimal('latest_close_price', 8, 2)->nullable();
            $table->decimal('latest_sma_20', 8, 2)->nullable();
            $table->decimal('latest_atr_14', 8, 2)->nullable();

            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
