@php
    use App\Models\Position;
    use App\Support\MarketDataFreshness;
    use Livewire\Livewire;

    $positionId = null;
    $livewire = Livewire::current();

    if (is_object($livewire) && method_exists($livewire, 'getRecord')) {
        $record = $livewire->getRecord();

        if ($record instanceof Position) {
            $positionId = $record->id;
        }
    }

    $ownSyncInProgress = MarketDataFreshness::isSyncInProgress();
    $positionSyncInProgress = $positionId !== null && MarketDataFreshness::isPositionSyncInProgress($positionId);
    $syncInProgress = $ownSyncInProgress || $positionSyncInProgress;

    $label = $syncInProgress
        ? 'Sync bezig…'
        : MarketDataFreshness::subheading();

    $tooltip = $positionSyncInProgress && ! $ownSyncInProgress
        ? 'Marktdata voor deze ticker wordt opgehaald.'
        : MarketDataFreshness::tooltip();
@endphp

<span
    @class([
        'vestix-market-data-status shrink-0',
        'animate-pulse' => $syncInProgress,
    ])
    title="{{ $tooltip }}"
>
    {{ $label }}
</span>
