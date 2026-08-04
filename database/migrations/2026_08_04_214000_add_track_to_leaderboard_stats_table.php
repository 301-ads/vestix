<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leaderboard_stats', 'track')) {
            Schema::table('leaderboard_stats', function (Blueprint $table) {
                $table->string('track', 20)->default('executor')->after('user_id');
            });
        }

        // MySQL can refuse to drop leaderboard_stats_squad_id_user_id_unique while FKs rely on it.
        $this->dropForeignKeys();

        if ($this->hasIndex('leaderboard_stats', 'leaderboard_stats_squad_id_user_id_unique')) {
            Schema::table('leaderboard_stats', function (Blueprint $table) {
                $table->dropUnique(['squad_id', 'user_id']);
            });
        }

        if (! $this->hasIndex('leaderboard_stats', 'leaderboard_stats_squad_id_user_id_track_unique')) {
            Schema::table('leaderboard_stats', function (Blueprint $table) {
                $table->unique(['squad_id', 'user_id', 'track']);
            });
        }

        $this->restoreForeignKeys();
    }

    public function down(): void
    {
        $this->dropForeignKeys();

        if ($this->hasIndex('leaderboard_stats', 'leaderboard_stats_squad_id_user_id_track_unique')) {
            Schema::table('leaderboard_stats', function (Blueprint $table) {
                $table->dropUnique(['squad_id', 'user_id', 'track']);
            });
        }

        if (! $this->hasIndex('leaderboard_stats', 'leaderboard_stats_squad_id_user_id_unique')) {
            Schema::table('leaderboard_stats', function (Blueprint $table) {
                $table->unique(['squad_id', 'user_id']);
            });
        }

        if (Schema::hasColumn('leaderboard_stats', 'track')) {
            Schema::table('leaderboard_stats', function (Blueprint $table) {
                $table->dropColumn('track');
            });
        }

        $this->restoreForeignKeys();
    }

    private function dropForeignKeys(): void
    {
        Schema::table('leaderboard_stats', function (Blueprint $table) {
            if ($this->hasForeignKey('leaderboard_stats', 'leaderboard_stats_squad_id_foreign')) {
                $table->dropForeign(['squad_id']);
            }

            if ($this->hasForeignKey('leaderboard_stats', 'leaderboard_stats_user_id_foreign')) {
                $table->dropForeign(['user_id']);
            }
        });
    }

    private function restoreForeignKeys(): void
    {
        Schema::table('leaderboard_stats', function (Blueprint $table) {
            if (! $this->hasForeignKey('leaderboard_stats', 'leaderboard_stats_squad_id_foreign')) {
                $table->foreign('squad_id')->references('id')->on('squads')->cascadeOnDelete();
            }

            if (! $this->hasForeignKey('leaderboard_stats', 'leaderboard_stats_user_id_foreign')) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn ($row): bool => ($row->name ?? null) === $index);
        }

        $schema = Schema::getConnection()->getDatabaseName();
        $result = DB::selectOne(
            'select 1 as present from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
            [$schema, $table, $index],
        );

        return $result !== null;
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite migrations via Blueprint still accept dropForeign; treat as present.
            return true;
        }

        $schema = Schema::getConnection()->getDatabaseName();
        $result = DB::selectOne(
            'select 1 as present from information_schema.table_constraints where table_schema = ? and table_name = ? and constraint_name = ? and constraint_type = ? limit 1',
            [$schema, $table, $constraint, 'FOREIGN KEY'],
        );

        return $result !== null;
    }
};
