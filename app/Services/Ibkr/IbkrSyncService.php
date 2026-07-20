<?php

namespace App\Services\Ibkr;

use App\Data\Ibkr\IbkrAccountSnapshot;
use App\Enums\Broker;
use App\Models\User;
use App\Services\BankrollSnapshotService;
use Illuminate\Support\Carbon;
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
     * @return array{
     *     users: int,
     *     cashflows_imported: int,
     *     cashflows_skipped: int,
     *     cashflow_details: list<array<string, mixed>>,
     *     snapshot: array<string, mixed>|null,
     *     success: bool,
     *     error: string|null
     * }
     */
    public function sync(?User $onlyUser = null): array
    {
        $users = $onlyUser !== null
            ? collect([$onlyUser])
            : User::query()
                ->where(function ($query): void {
                    $query->where('primary_broker', Broker::Ibkr->value)
                        ->orWhereNotNull('trading_bankroll');
                })
                ->get();

        $attemptAt = now();
        $cashflowsImported = 0;
        $cashflowsSkipped = 0;
        $cashflowDetails = [];

        try {
            $xml = $this->flexClient->fetchStatementXml();
            $snapshot = $this->parser->parse($xml);

            try {
                $openOrders = $this->openOrdersClient->fetchOpenOrders();
                $snapshot = new IbkrAccountSnapshot(
                    netLiquidation: $snapshot->netLiquidation,
                    availableFunds: $snapshot->availableFunds,
                    settledCash: $snapshot->settledCash,
                    baseCurrency: $snapshot->baseCurrency,
                    openPositions: $snapshot->openPositions,
                    openOrders: $openOrders,
                    cashTransactions: $snapshot->cashTransactions,
                    metadata: $snapshot->metadata,
                );
            } catch (Throwable $ordersException) {
                if ((bool) config('vestix.ibkr.client_portal.enabled', false)) {
                    Log::warning('IBKR open-orders sync failed; continuing with balances.', [
                        'error' => $ordersException->getMessage(),
                    ]);
                }
            }

            foreach ($users as $user) {
                $this->persistSuccess($user, $snapshot, $attemptAt);

                $result = $this->cashflowImporter->import($user, $snapshot);
                $cashflowsImported += $result->imported;
                $cashflowsSkipped += $result->skipped;
                $cashflowDetails = [...$cashflowDetails, ...$result->details];

                if ((bool) config('vestix.ibkr.sync_bankroll_snapshot', true)) {
                    $this->bankrollSnapshots->recordSnapshot($user, $snapshot->netLiquidation);
                }
            }

            return [
                'users' => $users->count(),
                'cashflows_imported' => $cashflowsImported,
                'cashflows_skipped' => $cashflowsSkipped,
                'cashflow_details' => $cashflowDetails,
                'snapshot' => $this->snapshotSummary($snapshot),
                'success' => true,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            foreach ($users as $user) {
                $this->persistFailure($user, $attemptAt, $exception->getMessage());
            }

            Log::error('IBKR sync failed.', ['error' => $exception->getMessage()]);

            return [
                'users' => $users->count(),
                'cashflows_imported' => 0,
                'cashflows_skipped' => 0,
                'cashflow_details' => [],
                'snapshot' => null,
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }
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
            'settled_cash' => $snapshot->settledCash,
            'deployable' => $snapshot->deployableCapital(),
            'open_positions' => count($snapshot->openPositions),
            'open_orders' => count($snapshot->openOrders),
            'cash_transactions' => count($snapshot->cashTransactions),
        ];
    }

    private function persistSuccess(User $user, IbkrAccountSnapshot $snapshot, Carbon $attemptAt): void
    {
        $user->forceFill([
            'ibkr_net_liquidation' => $snapshot->netLiquidation,
            'ibkr_available_funds' => $snapshot->availableFunds,
            'ibkr_settled_cash' => $snapshot->settledCash,
            'ibkr_base_currency' => $snapshot->baseCurrency,
            'ibkr_open_positions' => $snapshot->openPositionsAsArrays(),
            'ibkr_open_orders' => $snapshot->openOrdersAsArrays(),
            'ibkr_last_success_at' => $attemptAt,
            'ibkr_last_attempt_at' => $attemptAt,
            'ibkr_last_error' => null,
            'ibkr_data_stale' => false,
            'trading_bankroll' => $snapshot->netLiquidation,
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
