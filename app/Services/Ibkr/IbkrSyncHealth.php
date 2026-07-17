<?php

namespace App\Services\Ibkr;

use App\Models\User;
use Illuminate\Support\Carbon;

class IbkrSyncHealth
{
    public function staleAfterHours(): int
    {
        return max(1, (int) config('vestix.ibkr.stale_after_hours', 48));
    }

    public function isStale(User $user, ?Carbon $now = null): bool
    {
        if ((bool) $user->ibkr_data_stale) {
            return true;
        }

        $successAt = $user->ibkr_last_success_at;

        if ($successAt === null) {
            // Never synced — only stale once Flex reader is the active source.
            return (string) config('vestix.ibkr.reader', 'stub') === 'flex';
        }

        $now ??= now();

        return $successAt->copy()->addHours($this->staleAfterHours())->lte($now);
    }

    public function blocksAutomatedExecution(User $user, ?Carbon $now = null): bool
    {
        if (! (bool) config('vestix.ibkr.block_automation_when_stale', true)) {
            return false;
        }

        return $this->isStale($user, $now);
    }

    public function refreshStaleFlag(User $user, ?Carbon $now = null): void
    {
        $user = $user->fresh() ?? $user;
        $stale = false;

        $successAt = $user->ibkr_last_success_at;
        $now ??= now();

        if ($successAt === null) {
            $stale = (string) config('vestix.ibkr.reader', 'stub') === 'flex';
        } else {
            $stale = $successAt->copy()->addHours($this->staleAfterHours())->lte($now);
        }

        if ((bool) $user->ibkr_data_stale !== $stale) {
            $user->forceFill(['ibkr_data_stale' => $stale])->save();
        }
    }
}
