<?php

namespace App\Filament\Concerns;

use App\Models\Position;
use App\Services\AssetSyncService;

trait PollsAssetBranding
{
    public bool $pollAssetBranding = false;

    public int $assetBrandingPollAttempts = 0;

    public ?string $headingIconUrl = null;

    public bool $headingIconLoading = false;

    public function startPollingAssetBranding(): void
    {
        $this->pollAssetBranding = true;
        $this->assetBrandingPollAttempts = 0;
        $this->headingIconLoading = true;
        $this->syncHeadingIconState();
    }

    public function pollAssetBrandingFetch(): void
    {
        if (! $this->pollAssetBranding) {
            return;
        }

        $record = $this->getRecord();

        if (! $record instanceof Position) {
            $this->stopPollingAssetBranding();

            return;
        }

        $this->assetBrandingPollAttempts++;
        $record->unsetRelation('asset');
        $record->load('asset');

        // Re-queue once mid-poll in case the create-time deferred sync never ran.
        if (
            $this->assetBrandingPollAttempts === 2
            && $record->asset
            && ! $record->asset->hasIcon()
        ) {
            app(AssetSyncService::class)->queueBrandingSyncIfNeeded($record->asset);
        }

        $this->syncHeadingIconState();

        if ($record->asset?->hasIcon() || $this->assetBrandingPollAttempts >= 10) {
            $this->stopPollingAssetBranding();
        }
    }

    public function stopPollingAssetBranding(): void
    {
        $this->pollAssetBranding = false;
        $this->headingIconLoading = false;
        $this->syncHeadingIconState();
    }

    public function isAssetBrandingLoading(): bool
    {
        return $this->headingIconLoading;
    }

    protected function syncHeadingIconState(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Position) {
            $this->headingIconUrl = null;

            return;
        }

        $record->loadMissing('asset');
        $this->headingIconUrl = $record->asset?->icon_url;
    }
}
