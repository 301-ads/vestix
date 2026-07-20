<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ibkr\IbkrSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncIbkrAccount extends Command
{
    protected $signature = 'vestix:sync-ibkr
        {--user= : Limit sync to a single user id}
        {--details : Show Flex statement dates, balances and cashflow skip reasons}
        {--file= : Import a portal-downloaded Flex XML file instead of calling the Web Service}';

    protected $description = 'Sync read-only IBKR Flex balances/cashflows and optional Client Portal open orders.';

    public function handle(IbkrSyncService $syncService): int
    {
        $userId = $this->option('user');
        $user = null;

        if (filled($userId)) {
            $user = User::query()->find($userId);

            if ($user === null) {
                $this->error("User [{$userId}] not found.");

                return self::FAILURE;
            }
        }

        $statementXml = null;
        $file = $this->option('file');

        if (filled($file)) {
            if (! is_string($file) || ! is_file($file) || ! is_readable($file)) {
                $this->error("Flex XML file not readable: {$file}");

                return self::FAILURE;
            }

            $statementXml = file_get_contents($file);

            if ($statementXml === false || trim($statementXml) === '') {
                $this->error("Flex XML file is empty: {$file}");

                return self::FAILURE;
            }

            $this->info("Importing Flex XML from {$file} (Web Service bypass).");
        }

        $summary = $syncService->sync($user, $statementXml);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Success', $summary['success'] ? 'yes' : 'no'],
                ['Users', $summary['users']],
                ['Cashflows imported', $summary['cashflows_imported']],
                ['Cashflows skipped', $summary['cashflows_skipped']],
                ['Error', $summary['error'] ?? '—'],
            ],
        );

        if ($this->option('details') || $this->output->isVerbose()) {
            $this->renderVerbose($summary);
        }

        Log::info('IBKR sync completed.', [
            'success' => $summary['success'],
            'users' => $summary['users'],
            'cashflows_imported' => $summary['cashflows_imported'],
            'cashflows_skipped' => $summary['cashflows_skipped'],
            'error' => $summary['error'],
            'snapshot' => $summary['snapshot'],
        ]);

        if (! $summary['success'] && filled($summary['error'])) {
            $this->renderFlexErrorHint((string) $summary['error']);
        }

        return $summary['success'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderFlexErrorHint(string $error): void
    {
        if (str_contains($error, '(1025)')) {
            $this->newLine();
            $this->warn(
                'IBKR Flex lockout (1025): too many failed attempts / bad endpoint config. '
                .'Confirm IBKR_FLEX_BASE_URL is https://ndcdyn.interactivebrokers.com/AccountManagement/FlexWebService '
                .'(not the legacy Universal/servlet URL), regenerate the Flex Web Service token, '
                .'clear config cache, leave IP restriction blank (or whitelist the Forge server IP), then retry once.',
            );

            return;
        }

        if (str_contains($error, '(1001)')) {
            $this->newLine();
            $this->warn(
                'IBKR Flex is temporarily busy (1001). Wait a few minutes, then run sync again — avoid rapid repeated attempts.',
            );

            return;
        }

        if (preg_match('/\((1012|1013|1015)\)/', $error) === 1) {
            $this->newLine();
            $this->warn(
                'IBKR Flex configuration error. Check IBKR_FLEX_TOKEN and IBKR_FLEX_QUERY_ID on Forge match an active Flex token + query.',
            );
        }
    }

    /**
     * @param  array{
     *     snapshot: array<string, mixed>|null,
     *     cashflow_details: list<array<string, mixed>>
     * }  $summary
     */
    private function renderVerbose(array $summary): void
    {
        $snapshot = $summary['snapshot'];

        if (is_array($snapshot)) {
            $this->newLine();
            $this->info('Flex statement');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Account', $snapshot['account_id'] ?? '—'],
                    ['Period', $snapshot['period'] ?? '—'],
                    ['From', $snapshot['from_date'] ?? '—'],
                    ['To', $snapshot['to_date'] ?? '—'],
                    ['When generated', $snapshot['when_generated_at'] ?? ($snapshot['when_generated'] ?? '—')],
                    ['Base currency', $snapshot['base_currency'] ?? '—'],
                    ['Net Liquidation', $this->money($snapshot['net_liquidation'] ?? null)],
                    ['Available Funds', $this->money($snapshot['available_funds'] ?? null)],
                    ['Settled Cash', $this->money($snapshot['settled_cash'] ?? null)],
                    ['Deployable', $this->money($snapshot['deployable'] ?? null)],
                    ['Open positions', (string) ($snapshot['open_positions'] ?? 0)],
                    ['Open orders', (string) ($snapshot['open_orders'] ?? 0)],
                    ['Cash transactions in Flex', (string) ($snapshot['cash_transactions'] ?? 0)],
                ],
            );

            $toDate = $snapshot['to_date'] ?? null;
            $today = now(config('vestix.bankroll_tracker.timezone', 'Europe/Amsterdam'))->toDateString();

            if (is_string($toDate) && $toDate !== '' && $toDate < $today) {
                $this->warn(
                    "Flex toDate ({$toDate}) is before today ({$today}). "
                    .'Balances can lag IBKR Live — check the Flex Query period / generation time.',
                );
            }
        }

        $details = $summary['cashflow_details'] ?? [];

        if ($details === []) {
            return;
        }

        $this->newLine();
        $this->info('Cashflow classification');
        $this->table(
            ['External ID', 'Type', 'Ccy', 'Amount', 'USD', 'Reason'],
            array_map(
                fn (array $row): array => [
                    $row['external_id'] ?? '—',
                    $row['type'] ?? '—',
                    $row['currency'] ?? '—',
                    isset($row['amount']) ? number_format((float) $row['amount'], 2, '.', '') : '—',
                    isset($row['amount_base']) && $row['amount_base'] !== null
                        ? number_format((float) $row['amount_base'], 2, '.', '')
                        : '—',
                    $row['reason'] ?? '—',
                ],
                $details,
            ),
        );

        $this->line(
            'Reasons: imported | duplicate | denied_type | not_external_transfer | fx_conversion | missing_fx_rate_to_base',
        );
        $this->line(
            'EUR bank deposits → USD via Flex fxRateToBase. EUR.USD sells are FX conversion (not new capital).',
        );
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return '$'.number_format((float) $value, 2, '.', ',');
    }
}
