<?php

namespace App\Services;

use App\Alerts\AlertDispatcher;
use App\Contracts\QuoteProvider;
use App\Enums\AlertEventType;
use App\Enums\PremarketGapStatus;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Support\FilamentNotifier;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PreMarketGatekeeperService
{
    public function __construct(
        private readonly QuoteProvider $quoteProvider,
        private readonly AlertDispatcher $alertDispatcher,
    ) {}

    /**
     * @return array{checked: int, gap_up: int, gap_down: int, ok: int, unavailable: int, skipped: int}
     */
    public function run(?Carbon $tradingDay = null): array
    {
        $tradingDay ??= UsMarketSession::currentUsTradingDay();

        $positions = Position::query()
            ->scout()
            ->armedForEntry($tradingDay)
            ->whereNotNull('entry_price')
            ->with('user')
            ->get();

        $summary = [
            'checked' => 0,
            'gap_up' => 0,
            'gap_down' => 0,
            'ok' => 0,
            'unavailable' => 0,
            'skipped' => 0,
        ];

        foreach ($positions as $position) {
            if ($position->signal_high === null) {
                Log::info('Pre-market gatekeeper skipped scout without signal_high.', [
                    'position_id' => $position->id,
                    'ticker' => $position->ticker,
                ]);
                $summary['skipped']++;

                continue;
            }

            $status = $this->checkPosition($position);
            $summary['checked']++;
            $summary[$status->value]++;
        }

        return $summary;
    }

    public function checkPosition(Position $position): PremarketGapStatus
    {
        $entryTrigger = (float) $position->entry_price;
        $premarketPrice = $this->quoteProvider->fetchLivePrice($position->ticker);

        if ($premarketPrice === null) {
            $this->persistResult($position, null, $entryTrigger, PremarketGapStatus::Unavailable, null);

            return PremarketGapStatus::Unavailable;
        }

        if (
            $position->latest_close_price !== null
            && abs($premarketPrice - (float) $position->latest_close_price) < 0.001
        ) {
            Log::warning('Pre-market price equals latest close — quote may be stale.', [
                'position_id' => $position->id,
                'ticker' => $position->ticker,
                'price' => $premarketPrice,
            ]);
        }

        $gapPct = (($premarketPrice - $entryTrigger) / $entryTrigger) * 100;

        $status = match (true) {
            $premarketPrice > $entryTrigger => PremarketGapStatus::GapUp,
            $premarketPrice < $entryTrigger => PremarketGapStatus::GapDown,
            default => PremarketGapStatus::Ok,
        };

        $this->persistResult($position, $premarketPrice, $entryTrigger, $status, $gapPct);

        if ($status === PremarketGapStatus::GapUp) {
            $this->notifyGapRisk($position, $premarketPrice, $entryTrigger, $gapPct);
        }

        return $status;
    }

    private function persistResult(
        Position $position,
        ?float $premarketPrice,
        float $entryTrigger,
        PremarketGapStatus $status,
        ?float $gapPct,
    ): void {
        $position->update([
            'premarket_price' => $premarketPrice,
            'premarket_entry_trigger' => $entryTrigger,
            'premarket_gap_status' => $status,
            'premarket_gap_pct' => $gapPct,
            'premarket_checked_at' => now(),
        ]);
    }

    private function notifyGapRisk(
        Position $position,
        float $premarketPrice,
        float $entryTrigger,
        float $gapPct,
    ): void {
        $owner = $position->user;

        if ($owner === null) {
            return;
        }

        $this->alertDispatcher->dispatchNow(
            $owner->id,
            $position->id,
            AlertEventType::PremarketGapRisk,
            [
                'premarket_price' => $premarketPrice,
                'entry_trigger' => $entryTrigger,
                'gap_pct' => $gapPct,
            ],
        );

        $scoutUrl = ScoutResource::getUrl('edit', ['record' => $position]);

        FilamentNotifier::send(
            title: "Gap-up risico: {$position->ticker}",
            body: sprintf(
                'Pre-market noteert $%s. Dit is %.2f%% boven je entry-trigger ($%s). Risico op chasing! %s',
                number_format($premarketPrice, 2),
                $gapPct,
                number_format($entryTrigger, 2),
                $scoutUrl,
            ),
            status: 'danger',
            recipients: $owner,
        );
    }
}
