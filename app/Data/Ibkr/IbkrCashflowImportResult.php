<?php

namespace App\Data\Ibkr;

final readonly class IbkrCashflowImportResult
{
    /**
     * @param  list<array{
     *     external_id: string,
     *     type: string,
     *     currency: string,
     *     amount: float,
     *     amount_base: float|null,
     *     reason: string
     * }>  $details
     */
    public function __construct(
        public int $imported,
        public int $skipped,
        public array $details = [],
    ) {}

    /**
     * @return array{imported: int, skipped: int, details: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'details' => $this->details,
        ];
    }
}
