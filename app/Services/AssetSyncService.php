<?php

namespace App\Services;

use App\Jobs\SyncAssetBrandingJob;
use App\Models\Asset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AssetSyncService
{
    public function __construct(
        private TradingViewSymbolService $tradingView,
        private PolygonReferenceService $polygonReference,
    ) {}

    /**
     * Create or return the asset row without remote branding calls.
     */
    public function linkForTicker(string $ticker): Asset
    {
        return Asset::query()->firstOrCreate([
            'ticker' => Asset::normalizeTicker($ticker),
        ]);
    }

    /**
     * Queue TradingView/Polygon branding sync when the icon is still missing.
     */
    public function queueBrandingSyncIfNeeded(Asset $asset): void
    {
        if ($asset->hasIcon()) {
            return;
        }

        SyncAssetBrandingJob::dispatch($asset->id)->afterCommit();
    }

    public function ensureForTicker(string $ticker): Asset
    {
        $asset = $this->linkForTicker($ticker);

        if ($asset->hasIcon()) {
            return $asset;
        }

        return $this->sync($asset);
    }

    public function sync(Asset $asset, bool $force = false): Asset
    {
        if (! $force && $asset->hasIcon()) {
            return $asset;
        }

        $metadata = $this->resolveMetadata($asset->ticker);

        if ($metadata === null) {
            return $asset;
        }

        $updates = [
            'company_name' => $metadata['name'] ?? $asset->company_name,
            'fetched_at' => now(),
        ];

        $iconPath = $this->downloadImage(
            $metadata['icon_url'] ?? null,
            $asset->ticker,
            'icon',
            requiresApiKey: $metadata['icon_requires_api_key'] ?? false,
        );

        if ($iconPath !== null) {
            $this->deleteStoredImage($asset->icon_path);
            $updates['icon_path'] = $iconPath;
        }

        $logoPath = $this->downloadImage(
            $metadata['logo_url'] ?? null,
            $asset->ticker,
            'logo',
            requiresApiKey: $metadata['logo_requires_api_key'] ?? false,
        );

        if ($logoPath !== null) {
            $this->deleteStoredImage($asset->logo_path);
            $updates['logo_path'] = $logoPath;
        }

        $asset->update($updates);

        return $asset->fresh();
    }

    /**
     * @return array{
     *     name: string|null,
     *     icon_url: string|null,
     *     logo_url: string|null,
     *     icon_requires_api_key?: bool,
     *     logo_requires_api_key?: bool,
     * }|null
     */
    private function resolveMetadata(string $ticker): ?array
    {
        $tradingView = $this->tradingView->resolveSymbol($ticker);

        if ($tradingView !== null) {
            return [
                'name' => $tradingView['name'],
                'icon_url' => $tradingView['icon_url'],
                'logo_url' => null,
                'icon_requires_api_key' => false,
            ];
        }

        $polygon = $this->polygonReference->fetchTickerBranding($ticker);

        if ($polygon === null) {
            return null;
        }

        return [
            'name' => $polygon['name'],
            'icon_url' => $polygon['icon_url'] ?? $polygon['logo_url'],
            'logo_url' => $polygon['logo_url'],
            'icon_requires_api_key' => true,
            'logo_requires_api_key' => true,
        ];
    }

    private function downloadImage(
        ?string $url,
        string $ticker,
        string $type,
        bool $requiresApiKey = false,
    ): ?string {
        if (blank($url)) {
            return null;
        }

        try {
            $query = [];

            if ($requiresApiKey) {
                $apiKey = config('vestix.polygon.api_key');

                if (! $apiKey) {
                    return null;
                }

                $query['apiKey'] = $apiKey;
            }

            $response = Http::timeout(30)->get($url, $query);

            if (! $response->successful()) {
                Log::warning('Asset branding image download failed.', [
                    'ticker' => $ticker,
                    'type' => $type,
                    'status' => $response->status(),
                    'url' => $url,
                ]);

                return null;
            }

            $extension = $this->guessExtension($url, $response->header('Content-Type'));

            $path = "ticker-logos/{$ticker}-{$type}.{$extension}";

            Storage::disk('public')->put($path, $response->body());

            return $path;
        } catch (\Throwable $exception) {
            Log::error('Asset branding image download exception.', [
                'message' => $exception->getMessage(),
                'ticker' => $ticker,
                'type' => $type,
            ]);

            return null;
        }
    }

    private function guessExtension(string $url, ?string $contentType): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($extension, ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'], true)) {
                return $extension === 'jpeg' ? 'jpg' : $extension;
            }
        }

        return match ($contentType) {
            'image/svg+xml' => 'svg',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }

    private function deleteStoredImage(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
