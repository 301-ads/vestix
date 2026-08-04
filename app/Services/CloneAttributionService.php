<?php

namespace App\Services;

use App\Models\Position;
use Illuminate\Support\Collection;

class CloneAttributionService
{
    /**
     * @return Collection<int, array{
     *     cloner_name: string,
     *     status: string,
     *     status_label: string,
     *     roi_pct: float|null,
     *     freeride: bool,
     * }>
     */
    public function cloneOutcomeRows(Position $source): Collection
    {
        return $source->clones()
            ->with('user')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Position $clone): array {
                $status = (string) $clone->status;
                $closed = $status === 'closed';

                return [
                    'cloner_name' => $clone->user?->name ?? '—',
                    'status' => $status,
                    'status_label' => match ($status) {
                        'scout' => 'Scout',
                        'open' => 'Open',
                        'closed' => 'Gesloten',
                        default => ucfirst($status),
                    },
                    'roi_pct' => $closed ? round((float) $clone->unrealized_pnl_percentage, 2) : null,
                    'freeride' => $closed && $clone->freeride_secured_at !== null,
                ];
            })
            ->values();
    }

    public function clonedFromLabel(Position $clone): ?string
    {
        if ($clone->cloned_from_id === null) {
            return null;
        }

        $clone->loadMissing('clonedFrom.user');

        $spotter = $clone->clonedFrom?->user?->name;
        $ticker = $clone->clonedFrom?->ticker ?? $clone->ticker;

        if ($spotter === null) {
            return filled($ticker) ? "Gekloond · {$ticker}" : 'Gekloond';
        }

        return "Gekloond van {$spotter} · {$ticker}";
    }
}
