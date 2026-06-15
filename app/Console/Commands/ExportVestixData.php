<?php

namespace App\Console\Commands;

use App\Support\VestixDataTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExportVestixData extends Command
{
    protected $signature = 'vestix:export-data
                            {--path= : Absolute pad voor export (standaard: storage/app/vestix-export/<timestamp>)}
                            {--no-notifications : Sla notifications tabel over}';

    protected $description = 'Exporteer Vestix business-data naar JSON voor productie-import';

    public function handle(VestixDataTransfer $transfer): int
    {
        $transferRoot = $transfer->resolveExportRoot();
        File::ensureDirectoryExists($transferRoot);

        $destination = $this->option('path')
            ?: $transferRoot.DIRECTORY_SEPARATOR.$transfer->makeExportDirectoryName();

        File::ensureDirectoryExists($destination);

        $includeNotifications = ! $this->option('no-notifications');

        $result = $transfer->export($destination, $includeNotifications);

        $this->info('Export voltooid: '.$result['path']);
        $this->newLine();

        foreach ($result['counts'] as $table => $count) {
            $this->line(sprintf('  %-24s %d', $table, $count));
        }

        $this->newLine();
        $this->comment('Upload daarna storage-bestanden (screenshots, ticker-logo\'s) apart naar productie.');

        return self::SUCCESS;
    }
}
