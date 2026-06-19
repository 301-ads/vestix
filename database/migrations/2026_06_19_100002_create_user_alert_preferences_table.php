<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_alert_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel_type');
            $table->string('chat_id')->nullable();
            $table->string('webhook_url')->nullable();
            $table->json('active_events')->nullable();
            $table->time('daily_digest_time')->default('21:45:00');
            $table->string('timezone')->default('Europe/Amsterdam');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'channel_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_alert_preferences');
    }
};
