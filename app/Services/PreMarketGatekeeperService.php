<?php

namespace App\Services;

use App\Alerts\AlertDispatcher;
use App\Contracts\QuoteProvider;
use App\Enums\AlertEventType;
use App\Enums\PremarketScanResult;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Support\FilamentNotifier;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PreMarketGatekeeperService
{
    public function __construct(
        private readonly QuoteProvider $quoteProvider,
        private readonly AlertDispatcher $alertDispatcher,
    ) {}

    /**
     * @return array{checked: int, gap_up: int, reclamation: int, landing: int, ok: int, unavailable: int, skipped: int}
     */
    public function run(?Carbon $tradingDay = null): array
    {
        $tradingDay ??= UsMarketSession::currentUsTradingDay();

        $positions = $this->prioritizedScouts();

        $summary = [
            'checked' => 0,
            'gap_up' => 0,
            'reclamation' => 0,
            'landing' => 0,
            'ok' => 0,
            'unavailable' => 0,
            'skipped' => 0,
        ];

        foreach ($positions as $position) {
            if ($position->signal_high !== null) {
                $result = $this->checkGapRisk($position);
            } else {
                $result = $this->checkOpportunity($position);

                if ($result === null) {
                    $summary['skipped']++;

                    continue;
                }
            }

            $summary['checked']++;
            $summary[$result->summaryKey()]++;
        }

        return $summary;
    }

    public function checkPosition(Position $position): ?PremarketScanResult
    {
        if ($position->signal_high !== null) {
            return $this->checkGapRisk($position);
        }

        return $this->checkOpportunity($position);
    }

    /**
     * @return Collection<int, Position>
     */
    public function prioritizedScouts(): Collection
    {
        $scouts = Position::query()
            ->scout()
            ->with('user')
            ->get();

        $executionReady = $scouts
            ->filter(fn (Position $position): bool => $position->signal_high !== null && $position->entry_price !== null)
            ->sortBy(fn (Position $position): string => $position->ticker)
            ->values();

        $bounceOnly = $scouts
            ->filter(fn (Position $position): bool => $position->signal_high !== null && $position->entry_price === null)
            ->sortBy(fn (Position $position): string => $position->ticker)
            ->values();

        $opportunity = $scouts
            ->filter(fn (Position $position): bool => $position->signal_high === null)
            ->sortBy(fn (Position $position): string => $position->ticker)
            ->values();

        return $executionReady->concat($bounceOnly)->concat($opportunity);
    }

    public function estimateApiCalls(): int
    {
        return $this->prioritizedScouts()
            ->filter(fn (Position $position): bool => $this->willFetchQuote($position))
            ->count();
    }

    public function willFetchQuote(Position $position): bool
    {
        if ($position->signal_high !== null) {
            return true;
        }

        $sma = $position->latest_sma_20;
        $close = $position->latest_close_price;

        if ($sma === null || $close === null) {
            return false;
        }

        return (float) $close < (float) $sma;
    }

    public function checkGapRisk(Position $position): PremarketScanResult
    {
        $bounceHigh = (float) $position->signal_high;
        $threshold = (float) config('vestix.premarket.gap_up_threshold_pct', 1.0);
        $premarketPrice = $this->fetchPremarketPrice($position);

        if ($premarketPrice === null) {
            $this->persistResult($position, null, PremarketScanResult::Unavailable, null, null);

            return PremarketScanResult::Unavailable;
        }

        $gapPct = (($premarketPrice - $bounceHigh) / $bounceHigh) * 100;

        if ($gapPct > $threshold) {
            $this->persistResult($position, $premarketPrice, PremarketScanResult::GapRisk, $bounceHigh, $gapPct);
            $this->notifyGapRisk($position, $premarketPrice, $bounceHigh, $gapPct);

            return PremarketScanResult::GapRisk;
        }

        $this->persistResult($position, $premarketPrice, PremarketScanResult::Ok, $bounceHigh, $gapPct);

        return PremarketScanResult::Ok;
    }

    public function checkOpportunity(Position $position): ?PremarketScanResult
    {
        $sma = $position->latest_sma_20;
        $close = $position->latest_close_price;

        if ($sma === null || $close === null) {
            Log::info('Pre-market gatekeeper skipped scout without SMA 20 or close.', [
                'position_id' => $position->id,
                'ticker' => $position->ticker,
            ]);

            return null;
        }

        $sma = (float) $sma;
        $close = (float) $close;

        if ($close >= $sma) {
            Log::info('Pre-market gatekeeper skipped scout already above SMA 20 at close.', [
                'position_id' => $position->id,
                'ticker' => $position->ticker,
            ]);

            return null;
        }

        $premarketPrice = $this->fetchPremarketPrice($position);

        if ($premarketPrice === null) {
            $this->persistResult($position, null, PremarketScanResult::Unavailable, $sma, null);

            return PremarketScanResult::Unavailable;
        }

        if ($premarketPrice > $sma) {
            $distancePct = (($premarketPrice - $sma) / $sma) * 100;
            $this->persistResult($position, $premarketPrice, PremarketScanResult::Reclamation, $sma, $distancePct);
            $this->notifyReclamation($position, $premarketPrice, $sma, $distancePct);

            return PremarketScanResult::Reclamation;
        }

        $distanceBelowPct = (($sma - $premarketPrice) / $sma) * 100;
        $landingThreshold = (float) config('vestix.premarket.landing_distance_pct', 1.5);

        if ($distanceBelowPct <= $landingThreshold) {
            $this->persistResult($position, $premarketPrice, PremarketScanResult::Landing, $sma, $distanceBelowPct);
            $this->notifyLanding($position, $premarketPrice, $sma, $distanceBelowPct);

            return PremarketScanResult::Landing;
        }

        $this->persistResult($position, $premarketPrice, PremarketScanResult::Ok, $sma, $distanceBelowPct);

        return PremarketScanResult::Ok;
    }

    private function fetchPremarketPrice(Position $position): ?float
    {
        $referenceClose = $position->latest_close_price !== null
            ? (float) $position->latest_close_price
            : null;

        return $this->quoteProvider->fetchPremarketPrice($position->ticker, $referenceClose);
    }

    private function persistResult(
        Position $position,
        ?float $premarketPrice,
        PremarketScanResult $result,
        ?float $referencePrice,
        ?float $distancePct,
    ): void {
        $position->update([
            'premarket_price' => $premarketPrice,
            'premarket_scan_type' => $result,
            'premarket_reference_price' => $referencePrice,
            'premarket_distance_pct' => $distancePct,
            'premarket_checked_at' => now(),
        ]);
    }

    private function notifyGapRisk(
        Position $position,
        float $premarketPrice,
        float $bounceHigh,
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
                'bounce_high' => $bounceHigh,
                'gap_pct' => $gapPct,
            ],
        );

        $scoutUrl = ScoutResource::getUrl('edit', ['record' => $position]);

        FilamentNotifier::send(
            title: "Gap-up risico: {$position->ticker}",
            body: sprintf(
                'Pas op, risico op chasing bij %s! Pre-market noteert $%s (%.2f%% boven bounce high $%s). %s',
                $position->ticker,
                number_format($premarketPrice, 2),
                $gapPct,
                number_format($bounceHigh, 2),
                $scoutUrl,
            ),
            status: 'danger',
            recipients: $owner,
        );
    }

    private function notifyReclamation(
        Position $position,
        float $premarketPrice,
        float $sma,
        float $distancePct,
    ): void {
        $owner = $position->user;

        if ($owner === null) {
            return;
        }

        $this->alertDispatcher->dispatchNow(
            $owner->id,
            $position->id,
            AlertEventType::PremarketReclamation,
            [
                'premarket_price' => $premarketPrice,
                'sma_20' => $sma,
                'distance_pct' => $distancePct,
            ],
        );

        $scoutUrl = ScoutResource::getUrl('edit', ['record' => $position]);

        FilamentNotifier::send(
            title: "Kopers actief: {$position->ticker}",
            body: sprintf(
                'Kopers actief! %s herovert SMA 20 pre-market ($%s). Potentiële intraday setup. %s',
                $position->ticker,
                number_format($premarketPrice, 2),
                $scoutUrl,
            ),
            status: 'success',
            recipients: $owner,
        );
    }

    private function notifyLanding(
        Position $position,
        float $premarketPrice,
        float $sma,
        float $distanceBelowPct,
    ): void {
        $owner = $position->user;

        if ($owner === null) {
            return;
        }

        $this->alertDispatcher->dispatchNow(
            $owner->id,
            $position->id,
            AlertEventType::PremarketLanding,
            [
                'premarket_price' => $premarketPrice,
                'sma_20' => $sma,
                'distance_pct' => $distanceBelowPct,
            ],
        );

        $scoutUrl = ScoutResource::getUrl('edit', ['record' => $position]);

        FilamentNotifier::send(
            title: "Landing nadert: {$position->ticker}",
            body: sprintf(
                '%s nadert SMA 20 pre-market ($%s, %.2f%% onder lijn). Potentiële landing. %s',
                $position->ticker,
                number_format($premarketPrice, 2),
                $distanceBelowPct,
                $scoutUrl,
            ),
            status: 'warning',
            recipients: $owner,
        );
    }
}
