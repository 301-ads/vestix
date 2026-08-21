<?php

namespace App\Services\Ibkr;

use App\Data\Ibkr\IbkrAccountSnapshot;
use App\Models\ApiCredential;
use App\Models\User;
use App\Services\BankrollSnapshotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IbkrSyncService
{
    public function __construct(
        private FlexWebServiceClient $flexClient,
        private FlexStatementParser $parser,
        private ClientPortalOpenOrdersClient $openOrdersClient,
        private IbkrCashflowImporter $cashflowImporter,
        private BankrollSnapshotService $bankrollSnapshots,
        private IbkrSyncHealth $health,
    ) {}

    /**
     * Sync each connected user with their own Flex credentials.
     * Never fans one statement out to multiple users. Never falls back to env tokens.
     *
     * @return array{
     *     users: int,
     *     synced: int,
     *     failed: int,
     *     cashflows_imported: int,
     *     cashflows_skipped: int,
     *     cashflow_details: list<array<string, mixed>>,
     *     snapshot: array<string, mixed>|null,
     *     success: bool,
     *     error: string|null,
     *     results: list<array{user_id: int, success: bool, error: string|null, snapshot: array<string, mixed>|null}>
     * }
     *
     * @param  string|null  $statementXml  Optional portal-downloaded Flex XML (bypasses Web Service; still requires --user with credentials).
     */
    public function sync(?User $onlyUser = null, ?string $statementXml = null): array
    {
        if ($statementXml !== null && $onlyUser === null) {
            return $this->emptyFailure(
                'File/XML import requires --user= pointing at a user with IBKR Flex credentials.',
            );
        }

        $users = $this->resolveUsers($onlyUser);

        if ($users->isEmpty()) {
            $message = $onlyUser !== null
                ? "User [{$onlyUser->id}] has no IBKR Flex credentials. Connect token + query ID in profile first."
                : 'No users with IBKR Flex credentials. Connect via profile or run vestix:migrate-ibkr-flex-owner.';

            return $this->emptyFailure($message);
        }

        $attemptAt = now();
        $cashflowsImported = 0;
        $cashflowsSkipped = 0;
        $cashflowDetails = [];
        $results = [];
        $lastSnapshot = null;
        $firstError = null;
        $synced = 0;
        $failed = 0;
        $delayMs = max(0, (int) config('vestix.ibkr.flex.inter_user_delay_ms', 2000));

        foreach ($users->values() as $index => $user) {
            if ($index > 0 && $statementXml === null && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            try {
                $xml = $statementXml ?? $this->fetchXmlForUser($user);
                $snapshot = $this->parser->parse($xml);
                $snapshot = $this->maybeAttachOpenOrders($user, $snapshot);

                $this->persistSuccess($user, $snapshot, $attemptAt);

                $result = $this->cashflowImporter->import($user, $snapshot);
                $cashflowsImported += $result->imported;
                $cashflowsSkipped += $result->skipped;
                $cashflowDetails = [...$cashflowDetails, ...$result->details];

                if ((bool) config('vestix.ibkr.sync_bankroll_snapshot', true)) {
                    $user = $user->fresh() ?? $user;

                    $this->bankrollSnapshots->fillMissingFromIbkrDailyEquity(
                        $user,
                        $snapshot->equityByReportDate,
                    );

                    $this->bankrollSnapshots->recordSnapshot(
                        $user,
                        $this->bankrollSnapshots->resolveAlphaEquity($user, $snapshot->netLiquidation),
                        $this->bankrollSnapshots->alphaTrackerSessionDate(),
                    );

                    $this->bankrollSnapshots->warmAlphaBenchmarkCloses($user);
                }

                $summary = $this->snapshotSummary($snapshot);
                $lastSnapshot = $summary;
                $synced++;
                $results[] = [
                    'user_id' => $user->id,
                    'success' => true,
                    'error' => null,
                    'snapshot' => $summary,
                ];
            } catch (Throwable $exception) {
                $this->persistFailure($user, $attemptAt, $exception->getMessage());
                $failed++;
                $firstError ??= $exception->getMessage();
                $results[] = [
                    'user_id' => $user->id,
                    'success' => false,
                    'error' => $exception->getMessage(),
                    'snapshot' => null,
                ];

                Log::error('IBKR sync failed for user.', [
                    'user_id' => $user->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'users' => $users->count(),
            'synced' => $synced,
            'failed' => $failed,
            'cashflows_imported' => $cashflowsImported,
            'cashflows_skipped' => $cashflowsSkipped,
            'cashflow_details' => $cashflowDetails,
            'snapshot' => $lastSnapshot,
            'success' => $failed === 0 && $synced > 0,
            'error' => $firstError,
            'results' => $results,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveUsers(?User $onlyUser): Collection
    {
        if ($onlyUser !== null) {
            return $onlyUser->hasIbkrFlexConnection()
                ? collect([$onlyUser])
                : collect();
        }

        return User::query()
            ->whereHas('apiCredentials', function ($query): void {
                $query->where('provider', ApiCredential::PROVIDER_IBKR_FLEX);
            })
            ->get()
            ->filter(fn (User $user): bool => $user->hasIbkrFlexConnection())
            ->values();
    }

    private function fetchXmlForUser(User $user): string
    {
        $credentials = $user->ibkrFlexCredentials();

        if ($credentials === null) {
            throw new \RuntimeException(
                'IBKR Flex credentials missing for this user. Connect token + query ID in profile.',
            );
        }

        return $this->flexClient->fetchStatementXml($credentials['token'], $credentials['query_id']);
    }

    private function maybeAttachOpenOrders(User $user, IbkrAccountSnapshot $snapshot): IbkrAccountSnapshot
    {
        $ownerId = (int) config('vestix.ibkr.client_portal.owner_user_id', 0);

        if ($ownerId <= 0 || $user->id !== $ownerId) {
            return $snapshot;
        }

        try {
            $openOrders = $this->openOrdersClient->fetchOpenOrders();

            return new IbkrAccountSnapshot(
                netLiquidation: $snapshot->netLiquidation,
                availableFunds: $snapshot->availableFunds,
                settledCash: $snapshot->settledCash,
                baseCurrency: $snapshot->baseCurrency,
                openPositions: $snapshot->openPositions,
                openOrders: $openOrders,
                cashTransactions: $snapshot->cashTransactions,
                metadata: $snapshot->metadata,
                availableFundsIsExplicit: $snapshot->availableFundsIsExplicit,
                equityByReportDate: $snapshot->equityByReportDate,
            );
        } catch (Throwable $ordersException) {
            if ((bool) config('vestix.ibkr.client_portal.enabled', false)) {
                Log::warning('IBKR open-orders sync failed; continuing with balances.', [
                    'user_id' => $user->id,
                    'error' => $ordersException->getMessage(),
                ]);
            }
        }

        return $snapshot;
    }

    /**
     * @return array{
     *     users: int,
     *     synced: int,
     *     failed: int,
     *     cashflows_imported: int,
     *     cashflows_skipped: int,
     *     cashflow_details: list<array<string, mixed>>,
     *     snapshot: null,
     *     success: false,
     *     error: string,
     *     results: list<array{user_id: int, success: bool, error: string|null, snapshot: array<string, mixed>|null}>
     * }
     */
    private function emptyFailure(string $message): array
    {
        Log::warning('IBKR sync skipped.', ['error' => $message]);

        return [
            'users' => 0,
            'synced' => 0,
            'failed' => 0,
            'cashflows_imported' => 0,
            'cashflows_skipped' => 0,
            'cashflow_details' => [],
            'snapshot' => null,
            'success' => false,
            'error' => $message,
            'results' => [],
        ];
    }

    /**
     * @return array{
     *     account_id: string|null,
     *     from_date: string|null,
     *     to_date: string|null,
     *     period: string|null,
     *     when_generated: string|null,
     *     when_generated_at: string|null,
     *     base_currency: string,
     *     net_liquidation: float,
     *     available_funds: float,
     *     available_funds_explicit: bool,
     *     settled_cash: float,
     *     deployable: float,
     *     open_positions: int,
     *     open_orders: int,
     *     cash_transactions: int
     * }
     */
    private function snapshotSummary(IbkrAccountSnapshot $snapshot): array
    {
        $meta = $snapshot->metadata;
        $generatedAt = $meta?->whenGeneratedAt();

        return [
            'account_id' => $meta?->accountId,
            'from_date' => $meta?->formattedFromDate(),
            'to_date' => $meta?->formattedToDate(),
            'period' => $meta?->period,
            'when_generated' => $meta?->whenGenerated,
            'when_generated_at' => $generatedAt?->toDateTimeString(),
            'base_currency' => $snapshot->baseCurrency,
            'net_liquidation' => $snapshot->netLiquidation,
            'available_funds' => $snapshot->availableFunds,
            'available_funds_explicit' => $snapshot->availableFundsIsExplicit,
            'settled_cash' => $snapshot->settledCash,
            'deployable' => $snapshot->deployableCapital(),
            'open_positions' => count($snapshot->openPositions),
            'open_orders' => count($snapshot->openOrders),
            'cash_transactions' => count($snapshot->cashTransactions),
        ];
    }

    private function persistSuccess(User $user, IbkrAccountSnapshot $snapshot, Carbon $attemptAt): void
    {
        // Activity Flex often has Cash but not Available Funds. Don't clobber a real AF
        // (manual or prior) with the Cash proxy — Order Plan uses min(AF, Settled).
        $availableFunds = $snapshot->availableFunds;

        if (! $snapshot->availableFundsIsExplicit) {
            $existing = $user->ibkr_available_funds;

            if ($existing !== null && (float) $existing > 0) {
                $availableFunds = round((float) $existing, 2);
            }
        }

        $user->forceFill([
            'ibkr_net_liquidation' => $snapshot->netLiquidation,
            'ibkr_available_funds' => $availableFunds,
            'ibkr_settled_cash' => $snapshot->settledCash,
            'ibkr_base_currency' => $snapshot->baseCurrency,
            'ibkr_open_positions' => $snapshot->openPositionsAsArrays(),
            'ibkr_open_orders' => $snapshot->openOrdersAsArrays(),
            'ibkr_last_success_at' => $attemptAt,
            'ibkr_last_attempt_at' => $attemptAt,
            'ibkr_last_error' => null,
            'ibkr_data_stale' => false,
            // Alpha bankroll tracks IBKR NLV; Flex sync is source of truth after deposits land.
            'trading_bankroll' => $this->bankrollSnapshots
                ->resolveAlphaEquity($user, $snapshot->netLiquidation),
        ])->save();
    }

    private function persistFailure(User $user, Carbon $attemptAt, string $error): void
    {
        $user->forceFill([
            'ibkr_last_attempt_at' => $attemptAt,
            'ibkr_last_error' => Str::limit($error, 2000),
        ])->save();

        $this->health->refreshStaleFlag($user->fresh() ?? $user, $attemptAt);
    }
}
