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

class ExecutionDigestService
{
    public function __construct(
        private readonly QuoteProvider $quotes,
        private readonly AlertDispatcher $alertDispatcher,
    ) {}

    /**
     * @return array{sent: int, skipped: int, classified: int, safe: int, cancelled: int}
     */
    public function run(?Carbon $today = null): array
    {
        $today ??= Carbon::today('Europe/Amsterdam');
        $summary = [
            'sent' => 0,
            'skipped' => 0,
            'classified' => 0,
            'safe' => 0,
            'cancelled' => 0,
        ];

        if (! UsMarketSession::isUsTradingDay(Carbon::now('America/New_York'))) {
            return $summary;
        }

        $reminderDate = $today->toDateString();

        $scouts = Position::query()
            ->where('status', 'scout')
            ->whereDate('market_open_reminder_on', $reminderDate)
            ->whereNotNull('entry_price')
            ->with('user')
            ->orderBy('ticker')
            ->get();

        if ($scouts->isEmpty()) {
            return $summary;
        }

        /** @var Collection<int, Collection<int, Position>> $byUser */
        $byUser = $scouts->groupBy('user_id');

        foreach ($byUser as $userScouts) {
            $user = $userScouts->first()?->user;

            if (! $user instanceof User || ! $user->hasTelegramConnection()) {
                $summary['skipped'] += $userScouts->count();

                continue;
            }

            $rows = [];
            $positions = [];
            $rowMeta = [];

            foreach ($userScouts as $scout) {
                $classification = $this->classify($scout);
                $this->persistClassification($scout, $classification);
                $summary['classified']++;

                if ($classification['status'] === ExecutionDigestStatus::Safe) {
                    $summary['safe']++;
                } elseif ($classification['status']->isCancelled()) {
                    $summary['cancelled']++;
                }

                $fresh = $scout->fresh();
                $positions[] = $fresh;
                $rows[] = [
                    'position' => $fresh,
                    ...$classification,
                ];
                $rowMeta[] = [
                    'status' => $classification['status']->value,
                    'price' => $classification['price'],
                ];

                $scout->update(['market_open_reminder_on' => null]);
            }

            $message = AlertMessageBuilder::executionOrderPlan($user, $rows, $reminderDate);

            if ($this->alertDispatcher->dispatchExecutionOrderPlan($user, $message, $positions, [
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
     *     status: ExecutionDigestStatus,
     *     reason: string,
     *     price: float|null,
     * }
     */
    public function classify(Position $position): array
    {
        $entry = $position->entry_price !== null ? (float) $position->entry_price : null;

        if ($entry === null || $entry <= 0) {
            return [
                'status' => ExecutionDigestStatus::Unavailable,
                'reason' => 'Entry ontbreekt',
                'price' => null,
            ];
        }

        $price = $this->resolveOpenOrLivePrice($position);

        if ($price === null || $price <= 0) {
            return [
                'status' => ExecutionDigestStatus::Unavailable,
                'reason' => 'Geen openings-/liveprijs beschikbaar',
                'price' => null,
            ];
        }

        if ($price >= $entry) {
            return [
                'status' => ExecutionDigestStatus::CancelledGapUp,
                'reason' => sprintf(
                    'Open/live $%s ≥ entry $%s — gap-up / buy-stop al geraakt',
                    number_format($price, 2),
                    number_format($entry, 2),
                ),
                'price' => $price,
            ];
        }

        $sma20 = $position->latest_sma_20 !== null ? (float) $position->latest_sma_20 : null;

        if ($sma20 !== null && $sma20 > 0 && $price < $sma20) {
            return [
                'status' => ExecutionDigestStatus::CancelledTrendBreak,
                'reason' => sprintf(
                    'Open/live $%s onder SMA 20 $%s — trend gebroken',
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
                    'status' => ExecutionDigestStatus::CancelledTrendBreak,
                    'reason' => sprintf(
                        'Open/live $%s onder prior day low $%s — steun gebroken',
                        number_format($price, 2),
                        number_format($priorLow, 2),
                    ),
                    'price' => $price,
                ];
            }
        }

        return [
            'status' => ExecutionDigestStatus::Safe,
            'reason' => sprintf(
                'Open/live $%s onder entry $%s — safe zone',
                number_format($price, 2),
                number_format($entry, 2),
            ),
            'price' => $price,
        ];
    }

    /**
     * @param  array{status: ExecutionDigestStatus, reason: string, price: float|null}  $classification
     */
    public function persistClassification(Position $position, array $classification): void
    {
        $position->update([
            'execution_digest_status' => $classification['status']->value,
            'execution_digest_reason' => $classification['reason'],
            'execution_digest_price' => $classification['price'],
            'execution_digest_at' => now(),
        ]);
    }

    public function resolveOpenOrLivePrice(Position $position): ?float
    {
        $ticker = (string) $position->ticker;

        try {
            $session = $this->quotes->fetchSessionQuote($ticker);

            if (is_array($session)) {
                $open = $session['open'] ?? null;

                if ($open !== null && (float) $open > 0) {
                    return round((float) $open, 4);
                }

                $close = $session['close'] ?? null;

                if ($close !== null && (float) $close > 0) {
                    return round((float) $close, 4);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Execution digest session quote failed.', [
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
            Log::warning('Execution digest live quote failed.', [
                'ticker' => $ticker,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
