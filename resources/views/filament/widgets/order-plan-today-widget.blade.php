<x-filament-widgets::widget class="vestix-order-plan-widget">
    <x-filament::section
        heading="Order Plan vandaag"
        description="Live budgetverdeling voor setups die je gaat executeren."
        :compact="true"
    >
        <div @if ($interval = $this->getPollingInterval()) wire:poll.{{ $interval }} @endif>
            <livewire:execution-plan-content
                layout="embedded"
                :key="'order-plan-dashboard-content'"
            />
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
