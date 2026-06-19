<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('channel_type');
            $table->json('payload')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['position_id', 'event_type', 'channel_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_alerts');
    }
};
