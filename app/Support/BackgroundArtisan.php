<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class BackgroundArtisan
{
    /**
     * @param  array<string, scalar|null>  $parameters
     */
    public static function dispatch(string $command, array $parameters = []): void
    {
        $arguments = [
            PHP_BINARY,
            base_path('artisan'),
            $command,
        ];

        foreach ($parameters as $name => $value) {
            if ($value === null) {
                continue;
            }

            $arguments[] = '--'.$name.'='.$value;
        }

        if (app()->environment('testing')) {
            Process::path(base_path())->start($arguments);

            return;
        }

        $logFile = storage_path('logs/background-artisan.log');
        $commandLine = implode(' ', array_map(
            static fn (string $argument): string => escapeshellarg($argument),
            $arguments,
        ));

        if (PHP_OS_FAMILY === 'Windows') {
            Process::path(base_path())->start($arguments);

            return;
        }

        $shellCommand = sprintf(
            'cd %s && nohup %s >> %s 2>&1 &',
            escapeshellarg(base_path()),
            $commandLine,
            escapeshellarg($logFile),
        );

        exec($shellCommand);

        Log::info('Background artisan command dispatched.', [
            'command' => $command,
            'parameters' => $parameters,
        ]);
    }
}
