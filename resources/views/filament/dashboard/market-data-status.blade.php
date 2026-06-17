@php
    use App\Support\MarketDataFreshness;
@endphp

<span
    @class([
        'vestix-market-data-status shrink-0',
        'animate-pulse text-emerald-500 dark:text-emerald-400' => MarketDataFreshness::isSyncInProgress(),
        'text-gray-500 dark:text-gray-400' => ! MarketDataFreshness::isSyncInProgress(),
    ])
    title="{{ MarketDataFreshness::tooltip() }}"
>
    {{ MarketDataFreshness::subheading() }}
</span>
