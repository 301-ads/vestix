<x-filament-widgets::widget>
    @if (! $this->hasEnoughSnapshots())
        <x-filament::section
            heading="Alpha Tracker"
            description="Voeg minimaal twee wekelijkse bankroll-snapshots toe om je rendement en alpha te berekenen."
            :compact="true"
        >
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                Werk je bankroll bij op het dashboard zodra de wekelijkse taak verschijnt. Na twee snapshots zie je hier je YTD-groei en vergelijking met SPY.
            </p>
            <x-filament::button
                tag="a"
                href="{{ \App\Filament\Pages\Dashboard::getUrl() }}"
                color="primary"
                size="sm"
            >
                Naar dashboard
            </x-filament::button>
        </x-filament::section>
    @else
        <x-filament::section
            heading="Meer performance-data"
            description="Extra grafieken en analyses verschijnen hier binnenkort."
            :compact="true"
        >
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Deze ruimte is gereserveerd voor toekomstige performance-inzichten naast je Alpha Tracker.
            </p>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
