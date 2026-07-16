<x-filament-widgets::widget class="vestix-order-plan-widget">
    <x-filament::section
        heading="Order Plan vandaag"
        :compact="true"
    >
        <div class="vestix-order-plan-widget__body" @if ($interval = $this->getPollingInterval()) wire:poll.{{ $interval }} @endif>
            <div class="vestix-order-plan-widget__copy">
                <p class="font-medium text-gray-950 dark:text-white">
                    Je hebt {{ $this->planCount() }} setup{{ $this->planCount() === 1 ? '' : 's' }} geselecteerd voor executie
                </p>
                <p class="vestix-order-plan-widget__meta">
                    Totale inleg ≈ ${{ number_format($this->totalInvestment(), 2) }}
                </p>
            </div>

            <x-filament::button
                color="primary"
                icon="heroicon-o-shopping-cart"
                x-on:click="$dispatch('open-modal', { id: 'execution-plan' })"
            >
                Open Executie Paneel
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
