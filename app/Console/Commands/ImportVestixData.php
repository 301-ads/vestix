<?php

namespace App\Console\Commands;

use App\Support\VestixDataTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ImportVestixData extends Command
{
    protected $signature = 'vestix:import-data
                            {path? : Pad naar export-map (standaard: nieuwste in storage/app/vestix-export)}
                            {--force : Importeer ook als er al app-data in de database staat}
                            {--dry-run : Valideer export zonder te schrijven}';

    protected $description = 'Importeer Vestix business-data vanuit een export-map';

    public function handle(VestixDataTransfer $transfer): int
    {
        try {
            $path = $this->resolvePath($transfer);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! File::isDirectory($path)) {
            $this->error("Export-map niet gevonden: {$path}");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->option('dry-run') && $transfer->targetHasApplicationData()) {
            $this->error('Doel-database bevat al users of positions. Gebruik --force om toch te importeren.');

            return self::FAILURE;
        }

        try {
            $imported = $transfer->import($path, (bool) $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry-run geslaagd voor: '.$path);
        } else {
            $this->info('Import voltooid vanuit: '.$path);
        }

        $this->newLine();

        foreach ($imported as $table => $count) {
            $this->line(sprintf('  %-24s %d', $table, $count));
        }

        if (! $this->option('dry-run')) {
            $this->newLine();
            $this->comment('Controleer daarna met: php artisan vestix:smoke-test');
        }

        return self::SUCCESS;
    }

    private function resolvePath(VestixDataTransfer $transfer): string
    {
        $path = $this->argument('path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        $latest = $transfer->findLatestExportPath();

        if ($latest === null) {
            throw new RuntimeException('Geen export gevonden in storage/app/vestix-export');
        }

        return $latest;
    }
}
