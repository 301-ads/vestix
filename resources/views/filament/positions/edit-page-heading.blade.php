@php
    use App\Enums\ScoutPipelineStatus;
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\View\ComponentAttributeBag;

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
        $badgeColor = match ($status) {
            'open' => 'success',
            'scout' => 'info',
            default => 'gray',
        };
        $badgeLabel = match ($status) {
            'open' => 'Open',
            'scout' => 'Scout',
            default => 'Gesloten',
        };
    }
@endphp

<span class="position-edit-heading inline-flex items-center gap-5">
    <x-filament.positions.ticker-with-icon
        :ticker="$title"
        :icon-url="$iconUrl"
        :status-dot-color="$statusDotColor"
        :status-dot-label="$statusDotLabel"
        :direction="$direction ?? null"
        :show-direction-badge="true"
    />

    @if (! $isScout)
        <span {{ (new ComponentAttributeBag)->color(BadgeComponent::class, $badgeColor)->class(['fi-badge', 'fi-size-sm']) }}>
            <span class="fi-badge-label-ctn">
                <span class="fi-badge-label">{{ $badgeLabel }}</span>
            </span>
        </span>
    @endif
</span>
