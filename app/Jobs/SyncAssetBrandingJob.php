<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\AssetSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAssetBrandingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(
        public int $assetId,
        public bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(AssetSyncService $assetSync): void
    {
        $asset = Asset::query()->find($this->assetId);

        if ($asset === null) {
            return;
        }

        if (! $this->force && $asset->hasIcon()) {
            return;
        }

        $assetSync->sync($asset, force: $this->force);
    }
}
