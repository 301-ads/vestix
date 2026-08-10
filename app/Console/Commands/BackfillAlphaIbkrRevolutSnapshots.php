<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AlphaIbkrRevolutSnapshotBackfill;
use App\Services\Ibkr\FlexStatementParser;
use App\Services\Ibkr\FlexWebServiceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillAlphaIbkrRevolutSnapshots extends Command
{
    protected $signature = 'vestix:backfill-alpha-ibkr-revolut
                            {--user= : User ID}
                            {--xml= : Path to Flex statement XML (skips Web Service fetch)}
                            {--from= : Start date Y-m-d (default: baseline_date)}
                            {--to= : End date Y-m-d (default: today)}
                            {--tickers=BAC,HALO : Comma-separated Revolut tickers to include}
                            {--dry-run : Preview without writing snapshots}';

    protected $description = 'Rebuild Alpha snapshots as IBKR Flex daily NLV + Revolut lot MTM (HALO/BAC) + post-sale cash until withdrawal.';

    public function handle(
        AlphaIbkrRevolutSnapshotBackfill $backfill,
        FlexWebServiceClient $flexClient,
        FlexStatementParser $parser,
    ): int {
        $userId = $this->option('user');
        $user = $userId
            ? User::query()->find((int) $userId)
            : User::query()->whereNotNull('baseline_date')->orderBy('id')->first();

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $xmlPath = $this->option('xml');
        $xml = is_string($xmlPath) && $xmlPath !== ''
            ? (string) file_get_contents($xmlPath)
            : $flexClient->fetchStatementXml();

        $snapshot = $parser->parse($xml);
        $from = $this->option('from')
            ? Carbon::parse((string) $this->option('from'))
            : null;
        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))
            : null;
        $dryRun = (bool) $this->option('dry-run');

        $this->info("User #{$user->id} — Flex equity days: ".count($snapshot->equityByReportDate));

        $tickers = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('tickers')),
        )));

        $lots = $backfill->resolveRevolutLots(
            $user,
            $from ?? $user->baseline_date ?? now(),
            $to ?? now(),
            $tickers,
        );

        foreach ($lots as $lot) {
            $this->line(sprintf(
                '  Revolut %s qty=%s shares→%s cash=%s until=%s',
                $lot['ticker'],
                $lot['quantity'],
                $lot['held_until'] ?? 'open',
                $lot['cash_after_exit'] ?? '-',
                $lot['cash_until'] ?? '-',
            ));
        }

        $result = $backfill->backfill(
            $user,
            $snapshot->equityByReportDate,
            $from,
            $to,
            $dryRun,
            $tickers,
        );

        $this->table(
            ['Date', 'IBKR', 'Revolut', 'Total'],
            collect($result['days'])->map(fn (array $day): array => [
                $day['date'],
                number_format($day['ibkr'], 2, '.', ''),
                number_format($day['revolut'], 2, '.', ''),
                number_format($day['amount'], 2, '.', ''),
            ])->all(),
        );

        $this->info(($dryRun ? 'Dry-run — would write ' : 'Wrote ').$result['written'].' snapshot day(s).');

        return self::SUCCESS;
    }
}
