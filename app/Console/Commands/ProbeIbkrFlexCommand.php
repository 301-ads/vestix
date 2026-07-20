<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ProbeIbkrFlexCommand extends Command
{
    protected $signature = 'vestix:probe-ibkr-flex
        {--send : Actually call SendRequest once (no retries)}';

    protected $description = 'Show effective IBKR Flex config and optionally probe SendRequest once.';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('vestix.ibkr.flex.base_url'), '/');
        $token = (string) config('vestix.ibkr.flex.token', '');
        $queryId = (string) config('vestix.ibkr.flex.query_id', '');
        $userAgent = (string) config('vestix.ibkr.flex.user_agent', 'Vestix/1.0');
        $sendUrl = str_contains($baseUrl, 'Universal/servlet')
            ? "{$baseUrl}/FlexStatementService.SendRequest"
            : "{$baseUrl}/SendRequest";

        $this->table(['Field', 'Value'], [
            ['base_url', $baseUrl !== '' ? $baseUrl : '—'],
            ['send_url', $sendUrl],
            ['legacy_url', str_contains($baseUrl, 'Universal/servlet') ? 'yes' : 'no'],
            ['query_id', $queryId !== '' ? $queryId : '—'],
            ['token_length', (string) strlen($token)],
            ['token_last4', $token !== '' ? substr($token, -4) : '—'],
            ['user_agent', $userAgent],
            ['reader', (string) config('vestix.ibkr.reader', 'stub')],
        ]);

        if (str_contains($baseUrl, 'Universal/servlet') || str_contains($baseUrl, 'gdcdyn')) {
            $this->error('Legacy Flex URL detected. Set IBKR_FLEX_BASE_URL to https://ndcdyn.interactivebrokers.com/AccountManagement/FlexWebService and run php artisan config:clear');
        }

        if (! $this->option('send')) {
            $this->comment('Re-run with --send to call SendRequest once.');

            return self::SUCCESS;
        }

        if ($token === '' || $queryId === '') {
            $this->error('Missing IBKR_FLEX_TOKEN or IBKR_FLEX_QUERY_ID.');

            return self::FAILURE;
        }

        $this->warn('Calling SendRequest once…');

        $response = Http::timeout((int) config('vestix.ibkr.flex.timeout_seconds', 30))
            ->withHeaders([
                'User-Agent' => $userAgent,
                'Accept' => 'application/xml, text/xml, */*',
            ])
            ->get($sendUrl, [
                't' => $token,
                'q' => $queryId,
                'v' => '3',
            ]);

        $this->line('HTTP '.$response->status());
        $this->line($response->body());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
