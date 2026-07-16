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

    $syncInProgress = MarketDataFreshness::isSyncInProgress()
        || ($positionId !== null && MarketDataFreshness::isPositionSyncInProgress($positionId));

    $label = $syncInProgress
        ? 'Sync bezig…'
        : MarketDataFreshness::subheading();

    $tooltip = $syncInProgress && $positionId !== null && MarketDataFreshness::isPositionSyncInProgress($positionId)
        ? 'Marktdata voor deze ticker wordt opgehaald.'
        : MarketDataFreshness::tooltip();
@endphp

<span
    @class([
        'vestix-market-data-status shrink-0',
        'animate-pulse text-emerald-500 dark:text-emerald-400' => $syncInProgress,
        'text-gray-500 dark:text-gray-400' => ! $syncInProgress,
    ])
    title="{{ $tooltip }}"
>
    {{ $label }}
</span>
