<?php

use App\Models\Squad;
use App\Services\SquadPermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('squad_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('squad_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('type', 32);
            $table->string('ticker', 32);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['squad_id', 'created_at']);
        });

        Schema::create('squad_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('squad_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('role', 32);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['squad_id', 'email']);
        });

        // Refresh role matrices so Scout gains scout.share on existing squads.
        if (Schema::hasTable('squads')) {
            $permissions = app(SquadPermissionService::class);

            Squad::query()->each(fn (Squad $squad) => $permissions->seedRolesForSquad($squad));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('squad_invites');
        Schema::dropIfExists('squad_activities');
    }
};
