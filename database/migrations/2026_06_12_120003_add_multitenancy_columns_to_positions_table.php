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
        if (! Schema::hasColumn('positions', 'user_id')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('positions', 'squad_id')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->foreignId('squad_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('positions', 'visibility')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->string('visibility')->default('private')->after('status');
            });
        }

        if (! Schema::hasColumn('positions', 'cloned_from_id')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->foreignId('cloned_from_id')->nullable()->after('visibility')->constrained('positions')->nullOnDelete();
            });
        }

        if (! $this->indexExists('positions', ['user_id', 'status'])) {
            Schema::table('positions', function (Blueprint $table) {
                $table->index(['user_id', 'status']);
            });
        }

        if (! $this->indexExists('positions', ['squad_id', 'visibility', 'status'])) {
            Schema::table('positions', function (Blueprint $table) {
                $table->index(['squad_id', 'visibility', 'status']);
            });
        }

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

            if (Position::query()->whereNull('user_id')->exists()) {
                Position::query()
                    ->whereNull('user_id')
                    ->update([
                        'user_id' => $commander->id,
                        'visibility' => 'private',
                    ]);
            }

            $permissions = app(SquadPermissionService::class);
            $permissions->seedRolesForSquad($squad);
            $permissions->assignRole($commander, $squad, SquadRole::Commander);
        }

        $this->ensureUserIdIsRequired();
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            if (Schema::hasColumn('positions', 'cloned_from_id')) {
                $table->dropForeign(['cloned_from_id']);
            }

            if (Schema::hasColumn('positions', 'squad_id')) {
                $table->dropForeign(['squad_id']);
            }

            if (Schema::hasColumn('positions', 'user_id')) {
                $table->dropForeign(['user_id']);
            }

            if ($this->indexExists('positions', ['user_id', 'status'])) {
                $table->dropIndex(['user_id', 'status']);
            }

            if ($this->indexExists('positions', ['squad_id', 'visibility', 'status'])) {
                $table->dropIndex(['squad_id', 'visibility', 'status']);
            }

            $columns = array_filter([
                Schema::hasColumn('positions', 'user_id') ? 'user_id' : null,
                Schema::hasColumn('positions', 'squad_id') ? 'squad_id' : null,
                Schema::hasColumn('positions', 'visibility') ? 'visibility' : null,
                Schema::hasColumn('positions', 'cloned_from_id') ? 'cloned_from_id' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function indexExists(string $table, array $columns): bool
    {
        return Schema::hasIndex($table, $columns);
    }

    private function ensureUserIdIsRequired(): void
    {
        if (! Schema::hasColumn('positions', 'user_id')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE positions MODIFY user_id BIGINT UNSIGNED NOT NULL');

            return;
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
