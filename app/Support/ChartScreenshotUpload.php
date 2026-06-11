<?php

namespace App\Support;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ChartScreenshotUpload
{
    public static function maxSizeKb(): int
    {
        return (int) config('swng.trade_journal.chart_screenshot_max_kb', 10240);
    }

    public static function maxSizeLabel(): string
    {
        $maxMb = round(self::maxSizeKb() / 1024, 1);

        return "PNG of JPG, max {$maxMb} MB.";
    }

    public static function resolveUrl(mixed $state): ?string
    {
        if ($state instanceof TemporaryUploadedFile) {
            return $state->temporaryUrl();
        }

        if (is_array($state)) {
            $state = collect($state)
                ->filter(fn (mixed $file): bool => filled($file))
                ->first();
        }

        if ($state instanceof TemporaryUploadedFile) {
            return $state->temporaryUrl();
        }

        if (! is_string($state) || blank($state)) {
            return null;
        }

        return URL::to('/storage/'.$state);
    }

    public static function make(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->image()
            ->disk('public')
            ->directory('position-charts')
            ->visibility('public')
            ->maxSize(self::maxSizeKb())
            ->previewable(false)
            ->imagePreviewHeight('0')
            ->getUploadedFileUsing(static function (BaseFileUpload $component, string $file, string | array | null $storedFileNames): ?array {
                $disk = $component->getDisk();

                try {
                    if (! $disk->exists($file)) {
                        return null;
                    }
                } catch (\Throwable) {
                    return null;
                }

                $name = is_array($storedFileNames)
                    ? ($storedFileNames[$file] ?? basename($file))
                    : ($storedFileNames ?? basename($file));

                return [
                    'name' => $name,
                    'size' => $disk->size($file),
                    'type' => $disk->mimeType($file),
                    'url' => URL::to('/storage/'.$file),
                ];
            });
    }
}
