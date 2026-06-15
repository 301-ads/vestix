<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class VestixDataTransfer
{
    public const EXPORT_DIRECTORY = 'vestix-export';

    /** @var list<string> */
    public const TABLES = [
        'users',
        'squads',
        'squad_user',
        'permissions',
        'roles',
        'role_has_permissions',
        'assets',
        'positions',
        'model_has_roles',
        'model_has_permissions',
        'api_credentials',
        'notifications',
    ];

    /** @var list<string> */
    private const AUTO_INCREMENT_TABLES = [
        'users',
        'squads',
        'squad_user',
        'permissions',
        'roles',
        'assets',
        'positions',
        'api_credentials',
    ];

    /**
     * @return array{path: string, counts: array<string, int>}
     */
    public function export(string $destinationPath, bool $includeNotifications = true): array
    {
        File::ensureDirectoryExists($destinationPath);

        $counts = [];

        foreach (self::TABLES as $table) {
            if ($table === 'notifications' && ! $includeNotifications) {
                continue;
            }

            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = $this->exportTableRows($table);
            $counts[$table] = count($rows);

            File::put(
                $destinationPath.DIRECTORY_SEPARATOR.$table.'.json',
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL,
            );
        }

        File::put(
            $destinationPath.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode([
                'exported_at' => now()->toIso8601String(),
                'app_version' => (string) config('app.name'),
                'laravel_version' => app()->version(),
                'tables' => $counts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL,
        );

        return [
            'path' => $destinationPath,
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function import(string $sourcePath, bool $dryRun = false): array
    {
        $manifestPath = $sourcePath.DIRECTORY_SEPARATOR.'manifest.json';

        if (! File::isFile($manifestPath)) {
            throw new RuntimeException("Manifest ontbreekt in {$sourcePath}");
        }

        $imported = [];

        if (! $dryRun) {
            $this->withoutForeignKeyChecks(function () use ($sourcePath, &$imported): void {
                foreach (self::TABLES as $table) {
                    $file = $sourcePath.DIRECTORY_SEPARATOR.$table.'.json';

                    if (! File::isFile($file) || ! Schema::hasTable($table)) {
                        continue;
                    }

                    $rows = json_decode(File::get($file), true);

                    if (! is_array($rows)) {
                        throw new RuntimeException("Ongeldige JSON in {$file}");
                    }

                    $imported[$table] = $this->importTableRows($table, $rows);
                }

                $this->resetAutoIncrements();
            });
        } else {
            foreach (self::TABLES as $table) {
                $file = $sourcePath.DIRECTORY_SEPARATOR.$table.'.json';

                if (! File::isFile($file)) {
                    continue;
                }

                $rows = json_decode(File::get($file), true);
                $imported[$table] = is_array($rows) ? count($rows) : 0;
            }
        }

        return $imported;
    }

    public function targetHasApplicationData(): bool
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('positions')) {
            return false;
        }

        return DB::table('users')->exists() || DB::table('positions')->exists();
    }

    public function wipeApplicationData(): void
    {
        $this->withoutForeignKeyChecks(function (): void {
            foreach (array_reverse(self::TABLES) as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        });
    }

    public function makeExportDirectoryName(): string
    {
        return now()->format('Y-m-d_His');
    }

    public function resolveExportRoot(): string
    {
        return storage_path('app/'.self::EXPORT_DIRECTORY);
    }

    public function findLatestExportPath(): ?string
    {
        $root = $this->resolveExportRoot();

        if (! File::isDirectory($root)) {
            return null;
        }

        $directories = collect(File::directories($root))
            ->sortDesc()
            ->values();

        return $directories->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportTableRows(string $table): array
    {
        $query = DB::table($table);

        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        }

        return $query
            ->get()
            ->map(fn ($row): array => $this->normalizeRowForExport((array) $row))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importTableRows(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $normalized = array_map(
            fn (array $row): array => $this->normalizeRowForImport($table, $row),
            $rows,
        );

        foreach (array_chunk($normalized, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        return count($normalized);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRowForExport(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value instanceof Carbon) {
                $row[$key] = $value->toDateTimeString();
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRowForImport(string $table, array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_bool($value)) {
                $row[$key] = $value ? 1 : 0;
            }
        }

        if ($table === 'positions' && array_key_exists('bounce_volume_above_average', $row)) {
            $row['bounce_volume_above_average'] = (int) (bool) $row['bounce_volume_above_average'];
        }

        return $row;
    }

    private function withoutForeignKeyChecks(callable $callback): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        try {
            $callback();
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            if ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    private function resetAutoIncrements(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::AUTO_INCREMENT_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
                continue;
            }

            $maxId = DB::table($table)->max('id');

            if ($maxId === null) {
                continue;
            }

            DB::statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = '.((int) $maxId + 1));
        }
    }
}
