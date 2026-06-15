@php
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\View\ComponentAttributeBag;

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
@endphp

<span class="position-edit-heading inline-flex items-center gap-5">
    <x-filament.positions.ticker-with-icon :ticker="$title" :icon-url="$iconUrl" />
    <span {{ (new ComponentAttributeBag)->color(BadgeComponent::class, $badgeColor)->class(['fi-badge', 'fi-size-sm']) }}>
        <span class="fi-badge-label-ctn">
            <span class="fi-badge-label">{{ $badgeLabel }}</span>
        </span>
    </span>
</span>
