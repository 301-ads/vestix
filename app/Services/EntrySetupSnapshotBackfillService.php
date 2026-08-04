<?php

namespace App\Services;

use App\Models\Position;
use App\Support\EntrySetupSnapshot;
use Illuminate\Support\Collection;

class EntrySetupSnapshotBackfillService
{
    /**
     * @return array{updated: int, skipped: int}
     */
    public function backfill(?int $userId = null, bool $dryRun = false): array
    {
        $updated = 0;
        $skipped = 0;

        $this->candidates($userId)->each(function (Position $position) use ($dryRun, &$updated, &$skipped): void {
            if (EntrySetupSnapshot::alreadyCaptured($position)) {
                $skipped++;

                return;
            }

            $attributes = EntrySetupSnapshot::attributesFromLegacyPosition($position);

            if ($attributes === null) {
                $skipped++;

                return;
            }

            if (! $dryRun) {
                $position->forceFill($attributes)->saveQuietly();
            }

            $updated++;
        });

        return [
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return Collection<int, Position>
     */
    private function candidates(?int $userId): Collection
    {
        $query = Position::query()
            ->nonLegacy()
            ->whereIn('status', ['open', 'closed'])
            ->whereNull('entry_setup_captured_at')
            ->orderBy('id');

        if ($userId !== null) {
            $query->forUser($userId);
        }

        return $query->get();
    }
}
