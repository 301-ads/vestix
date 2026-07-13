<x-filament-widgets::widget class="vestix-actions-widget">
    <x-filament::section
        :heading="$heading"
        :compact="true"
    >
        @if ($this->reviewPositions->isEmpty())
            <div class="vestix-action-todos-empty">
                <p class="font-medium text-gray-950 dark:text-white">Geen buy-stop reviews</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Alle buy-stop orders zijn up-to-date of al beoordeeld.
                </p>
            </div>
        @else
            <ul class="vestix-action-todos" wire:poll.{{ $this->getPollingInterval() }}>
                @foreach ($this->reviewPositions as $position)
                    <li class="vestix-action-todo vestix-action-todo--warning">
                        <div class="vestix-action-todo__identity">
                            @include('components.filament.positions.ticker-with-icon', [
                                'ticker' => $position->ticker,
                                'iconUrl' => $position->asset?->icon_url,
                            ])
                        </div>

                        <div class="vestix-action-todo__content">
                            <p class="vestix-action-todo__ticker">
                                {{ $position->ticker }} — Beoordeel open buy-stop
                            </p>
                            <p class="vestix-action-todo__instruction">{{ $this->formatInstruction($position) }}</p>
                            @if ($hint = $this->formatValidationHintHtml($position))
                                <p class="vestix-action-todo__instruction mt-1 text-sm">{!! $hint !!}</p>
                            @endif
                        </div>

                        <div class="vestix-action-todo__action flex flex-wrap justify-end gap-2">
                            @foreach ($this->actionsForPosition($position) as $action)
                                {{ $action }}
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
