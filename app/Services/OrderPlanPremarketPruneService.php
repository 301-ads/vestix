<?php

namespace App\Services;

use App\Alerts\AlertDispatcher;
use App\Contracts\QuoteProvider;
use App\Enums\ExecutionDigestStatus;
use App\Models\Position;
use App\Models\User;
use App\Support\AlertMessageBuilder;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OrderPlanPremarketPruneService
{
    public function __construct(
        private readonly QuoteProvider $quotes,
        private readonly AlertDispatcher $alertDispatcher,
    ) {}

    /**
     * Drop Order Plan scouts whose pre-market price breaks SMA 20 (or prior day low).
     * Runs before the 14:30 prep digest so survivors get the full budget pie.
     *
     * @return array{checked: int, pruned: int, kept: int, unavailable: int, sent: int, skipped: int}
     */
    public function run(?Carbon $today = null): array
    {
        $today ??= Carbon::today('Europe/Amsterdam');
        $summary = [
            'checked' => 0,
            'pruned' => 0,
            'kept' => 0,
            'unavailable' => 0,
            'sent' => 0,
            'skipped' => 0,
        ];

        if (! UsMarketSession::isUsTradingDay(Carbon::now('America/New_York'))) {
            return $summary;
        }

        $reminderDate = $today->toDateString();
        $scouts = $this->scoutsDueForReminder($reminderDate);

        if ($scouts->isEmpty()) {
            return $summary;
        }

        /** @var Collection<int, Collection<int, Position>> $byUser */
        $byUser = $scouts->groupBy('user_id');

        foreach ($byUser as $userScouts) {
            $user = $userScouts->first()?->user;

            if (! $user instanceof User) {
                $summary['skipped'] += $userScouts->count();

                continue;
            }

            $prunedRows = [];

            foreach ($userScouts as $scout) {
                $summary['checked']++;
                $decision = $this->evaluate($scout);

                if ($decision['action'] === 'unavailable') {
                    $summary['unavailable']++;

                    continue;
                }

                if ($decision['action'] === 'keep') {
                    $summary['kept']++;

                    continue;
                }

                $this->persistPrune($scout, $decision);
                $scout->clearMarketOpenReminder();
                $summary['pruned']++;

                $fresh = $scout->fresh();
                $prunedRows[] = [
                    'position' => $fresh,
                    'status' => $decision['status'],
                    'reason' => $decision['reason'],
                    'price' => $decision['price'],
                ];
            }

            if ($prunedRows === []) {
                continue;
            }

            if (! $user->hasTelegramConnection()) {
                $summary['skipped']++;

                continue;
            }

            $message = AlertMessageBuilder::orderPlanRevised($user, $prunedRows, $reminderDate);
            $positions = array_map(fn (array $row): Position => $row['position'], $prunedRows);
            $rowMeta = array_map(fn (array $row): array => [
                'status' => $row['status']->value,
                'price' => $row['price'],
            ], $prunedRows);

            if ($this->alertDispatcher->dispatchOrderPlanRevised($user, $message, $positions, [
                'reminder_date' => $reminderDate,
                'rows' => $rowMeta,
            ])) {
                $summary['sent']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{
     *     action: 'prune'|'keep'|'unavailable',
     *     status: ExecutionDigestStatus,
     *     reason: string,
     *     price: float|null,
     * }
     */
    public function evaluate(Position $position): array
    {
        $price = $this->resolvePremarketPrice($position);

        if ($price === null || $price <= 0) {
            return [
                'action' => 'unavailable',
                'status' => ExecutionDigestStatus::Unavailable,
                'reason' => 'Geen pre-market-/liveprijs beschikbaar',
                'price' => null,
            ];
        }

        $sma20 = $position->latest_sma_20 !== null ? (float) $position->latest_sma_20 : null;

        if ($sma20 !== null && $sma20 > 0 && $price < $sma20) {
            return [
                'action' => 'prune',
                'status' => ExecutionDigestStatus::CancelledTrendBreak,
                'reason' => sprintf(
                    'premarket $%s onder SMA 20 $%s — trend gebroken',
                    number_format($price, 2),
                    number_format($sma20, 2),
                ),
                'price' => $price,
            ];
        }

        if ($sma20 === null || $sma20 <= 0) {
            $priorLow = $position->prior_day_low !== null ? (float) $position->prior_day_low : null;

            if ($priorLow !== null && $priorLow > 0 && $price < $priorLow) {
                return [
                    'action' => 'prune',
                    'status' => ExecutionDigestStatus::CancelledTrendBreak,
                    'reason' => sprintf(
                        'premarket $%s onder prior day low $%s — steun gebroken',
                        number_format($price, 2),
                        number_format($priorLow, 2),
                    ),
                    'price' => $price,
                ];
            }
        }

        return [
            'action' => 'keep',
            'status' => ExecutionDigestStatus::Safe,
            'reason' => sprintf('premarket $%s boven steun — blijft in Order Plan', number_format($price, 2)),
            'price' => $price,
        ];
    }

    /**
     * @param  array{action: string, status: ExecutionDigestStatus, reason: string, price: float|null}  $decision
     */
    public function persistPrune(Position $position, array $decision): void
    {
        $position->update([
            'execution_digest_status' => $decision['status']->value,
            'execution_digest_reason' => $decision['reason'],
            'execution_digest_price' => $decision['price'],
            'execution_digest_at' => now(),
            'premarket_price' => $decision['price'],
            'premarket_checked_at' => now(),
        ]);
    }

    public function resolvePremarketPrice(Position $position): ?float
    {
        $ticker = (string) $position->ticker;
        $referenceClose = $position->latest_close_price !== null
            ? (float) $position->latest_close_price
            : null;

        try {
            $premarket = $this->quotes->fetchPremarketPrice($ticker, $referenceClose);

            if ($premarket !== null && $premarket > 0) {
                return round($premarket, 4);
            }
        } catch (\Throwable $e) {
            Log::warning('Order plan premarket prune quote failed.', [
                'ticker' => $ticker,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $live = $this->quotes->fetchLivePrice($ticker);

            if ($live !== null && $live > 0) {
                return round($live, 4);
            }
        } catch (\Throwable $e) {
            Log::warning('Order plan premarket prune live quote failed.', [
                'ticker' => $ticker,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @return Collection<int, Position>
     */
    private function scoutsDueForReminder(string $reminderDate): Collection
    {
        return Position::query()
            ->where('status', 'scout')
            ->whereDate('market_open_reminder_on', $reminderDate)
            ->whereNotNull('entry_price')
            ->with('user')
            ->orderBy('ticker')
            ->get();
    }
}
