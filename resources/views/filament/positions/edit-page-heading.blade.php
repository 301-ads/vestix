@php
    use App\Enums\Broker;
    use App\Enums\ScoutPipelineStatus;
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\View\ComponentAttributeBag;

    if ($status === 'scout' && isset($pipelineStatus) && $pipelineStatus instanceof ScoutPipelineStatus) {
        $badgeColor = $pipelineStatus->badgeColor();
        $badgeLabel = $pipelineStatus->label();
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

    $brokerLabel = isset($broker) && $broker instanceof Broker ? $broker->shortLabel() : null;
@endphp

<span class="position-edit-heading inline-flex items-center gap-5">
    <x-filament.positions.ticker-with-icon :ticker="$title" :icon-url="$iconUrl" />
    <span {{ (new ComponentAttributeBag)->color(BadgeComponent::class, $badgeColor)->class(['fi-badge', 'fi-size-sm']) }}>
        <span class="fi-badge-label-ctn">
            <span class="fi-badge-label">{{ $badgeLabel }}</span>
        </span>
    </span>
    @if ($brokerLabel !== null)
        <span {{ (new ComponentAttributeBag)->color(BadgeComponent::class, 'gray')->class(['fi-badge', 'fi-size-sm']) }}>
            <span class="fi-badge-label-ctn">
                <span class="fi-badge-label">{{ $brokerLabel }}</span>
            </span>
        </span>
    @endif
</span>
