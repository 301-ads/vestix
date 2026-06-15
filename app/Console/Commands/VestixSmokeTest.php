<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

class VestixSmokeTest extends Command
{
    protected $signature = 'vestix:smoke-test {--url= : Override APP_URL for health check}';

    protected $description = 'Post-deploy productiecontroles (database, storage, scheduler, config)';

    public function handle(): int
    {
        $failed = false;

        $this->info('Vestix smoke test gestart...');
        $this->newLine();

        $failed = $this->checkAppConfig() || $failed;
        $failed = $this->checkDatabase() || $failed;
        $failed = $this->checkStorage() || $failed;
        $failed = $this->checkPhpBinary() || $failed;
        $failed = $this->checkIntegrations() || $failed;
        $failed = $this->checkScheduler() || $failed;
        $failed = $this->checkHealthEndpoint() || $failed;

        $this->newLine();

        if ($failed) {
            $this->error('Smoke test mislukt — los de gemarkeerde punten op.');

            return self::FAILURE;
        }

        $this->info('Smoke test geslaagd — productie lijkt klaar.');

        return self::SUCCESS;
    }

    private function checkAppConfig(): bool
    {
        $failed = false;

        if (config('app.env') !== 'production') {
            $this->warn('APP_ENV is niet production (huidig: '.config('app.env').').');
        } else {
            $this->line('✓ APP_ENV=production');
        }

        if (config('app.debug')) {
            $this->error('✗ APP_DEBUG staat aan — zet op false in productie.');
            $failed = true;
        } else {
            $this->line('✓ APP_DEBUG=false');
        }

        if (blank(config('app.key'))) {
            $this->error('✗ APP_KEY ontbreekt.');
            $failed = true;
        } else {
            $this->line('✓ APP_KEY geconfigureerd');
        }

        $url = (string) config('app.url');

        if (! str_starts_with($url, 'https://')) {
            $this->warn('APP_URL begint niet met https:// ('.$url.')');
        } else {
            $this->line('✓ APP_URL gebruikt HTTPS');
        }

        return $failed;
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            $this->line('✓ Databaseverbinding OK');

            return false;
        } catch (\Throwable $exception) {
            $this->error('✗ Databaseverbinding mislukt: '.$exception->getMessage());

            return true;
        }
    }

    private function checkStorage(): bool
    {
        $failed = false;
        $paths = [
            storage_path('logs'),
            storage_path('app/public'),
            storage_path('framework/cache'),
        ];

        foreach ($paths as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $this->error("✗ Niet schrijfbaar: {$path}");
                $failed = true;
            }
        }

        if (! $failed) {
            $this->line('✓ Storage-paden schrijfbaar');
        }

        $publicLink = public_path('storage');

        if (! File::exists($publicLink)) {
            $this->error('✗ storage:link ontbreekt — draai: php artisan storage:link');
            $failed = true;
        } else {
            $this->line('✓ public/storage symlink aanwezig');
        }

        return $failed;
    }

    private function checkPhpBinary(): bool
    {
        $binary = config('app.php_binary');

        if (! is_string($binary) || $binary === '') {
            $this->warn('PHP_BINARY niet gezet — dashboard sync kan falen onder PHP-FPM.');

            return false;
        }

        if (! is_executable($binary)) {
            $this->error("✗ PHP_BINARY niet uitvoerbaar: {$binary}");

            return true;
        }

        $this->line("✓ PHP_BINARY uitvoerbaar ({$binary})");

        return false;
    }

    private function checkIntegrations(): bool
    {
        $warnings = 0;

        if (blank(config('vestix.alpha_vantage.api_key'))) {
            $this->warn('ALPHA_VANTAGE_API_KEY ontbreekt — EOD sync werkt niet.');
            $warnings++;
        }

        if (blank(config('vestix.polygon.api_key'))) {
            $this->warn('POLYGON_API_KEY ontbreekt — scout-watcher en asset-sync beperkt.');
            $warnings++;
        }

        if (blank(config('vestix.telegram.bot_token'))) {
            $this->warn('TELEGRAM_BOT_TOKEN ontbreekt — Telegram-alerts uit.');
            $warnings++;
        }

        if (blank(config('vestix.telegram.webhook_secret'))) {
            $this->warn('TELEGRAM_WEBHOOK_SECRET ontbreekt — Telegram koppelen via profiel werkt niet.');
            $warnings++;
        }

        if ($warnings === 0) {
            $this->line('✓ Marktdata- en Telegram-config aanwezig');
        }

        return false;
    }

    private function checkScheduler(): bool
    {
        $events = Schedule::events();
        $commands = collect($events)
            ->map(fn ($event) => $event->command ?? null)
            ->filter()
            ->values();

        $required = ['vestix:fetch-data', 'vestix:watch-scouts'];
        $missing = collect($required)->filter(
            fn (string $command): bool => ! $commands->contains(
                fn (?string $scheduled): bool => is_string($scheduled) && str_contains($scheduled, $command),
            ),
        );

        if ($missing->isNotEmpty()) {
            $this->error('✗ Ontbrekende scheduler-taken: '.$missing->implode(', '));

            return true;
        }

        $this->line('✓ Scheduler-taken geregistreerd (fetch-data, watch-scouts)');

        return false;
    }

    private function checkHealthEndpoint(): bool
    {
        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/').'/up';

        try {
            $response = Http::timeout(10)->get($url);

            if ($response->successful()) {
                $this->line("✓ Health endpoint OK ({$url})");

                return false;
            }

            $this->error("✗ Health endpoint {$url} gaf status {$response->status()}");

            return true;
        } catch (\Throwable $exception) {
            $this->warn("Health check overgeslagen ({$url}): ".$exception->getMessage());

            return false;
        }
    }
}
