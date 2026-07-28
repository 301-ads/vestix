<x-filament-widgets::widget class="vestix-actions-widget">
    <x-filament::section
        heading="IBKR reconcile"
        description="Verschillen tussen Vestix en Flex — alleen overnemen na jouw bevestiging."
        :compact="true"
    >
        <ul class="vestix-action-todos">
            @foreach ($this->mismatches as $index => $mismatch)
                @php
                    $action = $this->actionForMismatch($index, $mismatch);
                @endphp

                <li @class([
                    'vestix-action-todo',
                    'vestix-action-todo--warning' => ($mismatch['type'] ?? '') === 'qty_drift',
                    'vestix-action-todo--danger' => ($mismatch['type'] ?? '') === 'ghost_vestix',
                    'vestix-action-todo--info' => ($mismatch['type'] ?? '') === 'ghost_ibkr',
                ])>
                    <div class="vestix-action-todo__content">
                        <p class="vestix-action-todo__ticker">
                            <span class="vestix-action-todo__ticker-name">{{ $mismatch['ticker'] }}</span>
                        </p>
                        <p class="vestix-action-todo__instruction">{{ $mismatch['message'] }}</p>
                    </div>

                    @if ($action)
                        <div class="vestix-action-todo__action" wire:key="ibkr-reconcile-{{ $index }}">
                            {{ $action }}
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
