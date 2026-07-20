<?php

namespace App\Data\Ibkr;

use Illuminate\Support\Carbon;

final readonly class FlexStatementMetadata
{
    public function __construct(
        public ?string $accountId,
        public ?string $fromDate,
        public ?string $toDate,
        public ?string $period,
        public ?string $whenGenerated,
    ) {}

    public function whenGeneratedAt(?string $timezone = null): ?Carbon
    {
        $raw = trim((string) $this->whenGenerated);

        if ($raw === '') {
            return null;
        }

        $timezone ??= (string) config('vestix.bankroll_tracker.timezone', 'Europe/Amsterdam');

        if (preg_match('/^(\d{4})(\d{2})(\d{2});(\d{2})(\d{2})(\d{2})$/', $raw, $matches) === 1) {
            return Carbon::create(
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
                (int) $matches[4],
                (int) $matches[5],
                (int) $matches[6],
                $timezone,
            );
        }

        return null;
    }

    public function formattedToDate(): ?string
    {
        return $this->formatFlexDate($this->toDate);
    }

    public function formattedFromDate(): ?string
    {
        return $this->formatFlexDate($this->fromDate);
    }

    private function formatFlexDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return $raw;
    }
}
