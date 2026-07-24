<?php

namespace App\Filament\Concerns;

use App\Models\Position;

trait PollsAssetBranding
{
    public bool $pollAssetBranding = false;

    public int $assetBrandingPollAttempts = 0;

    public function startPollingAssetBranding(): void
    {
        $this->pollAssetBranding = true;
        $this->assetBrandingPollAttempts = 0;
    }

    public function pollAssetBrandingFetch(): void
    {
        if (! $this->pollAssetBranding) {
            return;
        }

        $record = $this->getRecord();

        if (! $record instanceof Position) {
            $this->pollAssetBranding = false;

            return;
        }

        $this->assetBrandingPollAttempts++;
        $record->unsetRelation('asset');
        $record->load('asset');

        if ($record->asset?->hasIcon() || $this->assetBrandingPollAttempts >= 10) {
            $this->pollAssetBranding = false;
        }
    }

    public function isAssetBrandingLoading(): bool
    {
        if (! $this->pollAssetBranding) {
            return false;
        }

        $record = $this->getRecord();

        return $record instanceof Position && ! $record->asset?->hasIcon();
    }
}
