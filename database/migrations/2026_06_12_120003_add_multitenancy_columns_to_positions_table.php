<?php

use App\Enums\SquadRole;
use App\Models\Position;
use App\Models\Squad;
use App\Models\User;
use App\Services\SquadPermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('squad_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('visibility')->default('private')->after('status');
            $table->foreignId('cloned_from_id')->nullable()->after('visibility')->constrained('positions')->nullOnDelete();

            $table->index(['user_id', 'status']);
            $table->index(['squad_id', 'visibility', 'status']);
        });

        $commander = User::query()->orderBy('id')->first();

        if ($commander !== null) {
            $squad = Squad::query()->firstOrCreate(
                ['slug' => 'alpha-squad'],
                [
                    'name' => 'Alpha Squad',
                    'owner_id' => $commander->id,
                ],
            );

            $userIds = User::query()->pluck('id');

            foreach ($userIds as $userId) {
                DB::table('squad_user')->insertOrIgnore([
                    'squad_id' => $squad->id,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Position::query()->update([
                'user_id' => $commander->id,
                'visibility' => 'private',
            ]);

            $permissions = app(SquadPermissionService::class);
            $permissions->seedRolesForSquad($squad);
            $permissions->assignRole($commander, $squad, SquadRole::Commander);
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropForeign(['cloned_from_id']);
            $table->dropForeign(['squad_id']);
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['squad_id', 'visibility', 'status']);
            $table->dropColumn(['user_id', 'squad_id', 'visibility', 'cloned_from_id']);
        });
    }
};
