@php
    use App\Enums\ScoutPipelineStatus;

    $isScout = $status === 'scout'
        && isset($pipelineStatus)
        && $pipelineStatus instanceof ScoutPipelineStatus;

    $statusDotColor = null;
    $statusDotLabel = null;

    if ($isScout) {
        [$statusDotColor, $statusDotLabel] = match ($pipelineStatus) {
            ScoutPipelineStatus::Scout => ['gray', 'Pending'],
            ScoutPipelineStatus::Pending => ['info', 'Order Plan'],
            ScoutPipelineStatus::Active => ['success', 'Order geplaatst'],
            ScoutPipelineStatus::ReviewRequired => ['warning', 'Review'],
        };
    } else {
        [$statusDotColor, $statusDotLabel] = match ($status) {
            'open' => ['success', 'Open'],
            default => ['gray', 'Gesloten'],
        };
    }
@endphp

<span class="position-edit-heading inline-flex items-center gap-3">
    <x-filament.positions.ticker-with-icon
        :ticker="$title"
        :icon-url="$iconUrl"
        :icon-loading="$iconLoading ?? false"
        :status-dot-color="$statusDotColor"
        :status-dot-label="$statusDotLabel"
        :direction="$direction ?? null"
        :show-direction-badge="true"
    />
</span>
