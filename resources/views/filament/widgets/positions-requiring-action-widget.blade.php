<x-filament-widgets::widget class="vestix-actions-widget">
    <x-filament::section
        :heading="$heading"
        :compact="true"
    >
        @if ($this->actionablePositions->isEmpty())
            <div class="vestix-action-todos-empty">
                <div class="vestix-action-todos-empty__icon" aria-hidden="true">
                    {{ \Filament\Support\generate_icon_html('heroicon-o-check-circle', attributes: (new \Illuminate\View\ComponentAttributeBag)->class(['fi-icon fi-size-lg'])) }}
                </div>
                <p class="font-medium text-gray-950 dark:text-white">Geen acties vereist</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Alle stop-losses zijn up-to-date en geen posities onder hun stop-loss.
                </p>
            </div>
        @else
            <ul class="vestix-action-todos" wire:poll.{{ $this->getPollingInterval() }}>
                @foreach ($this->actionablePositions as $position)
                    @php
                        $accent = $this->formatActionAccent($position);
                        $action = $this->actionForPosition($position);
                    @endphp

                    <li @class([
                        'vestix-action-todo',
                        'vestix-action-todo--' . $accent,
                    ])>
                        <div class="vestix-action-todo__identity">
                            @include('components.filament.positions.ticker-with-icon', [
                                'ticker' => $position->ticker,
                                'iconUrl' => $position->asset?->icon_url,
                            ])
                        </div>

                        <div class="vestix-action-todo__content">
                            <p class="vestix-action-todo__ticker">{{ $position->ticker }}</p>
                            <p class="vestix-action-todo__instruction">{!! $this->formatInstructionHtml($position) !!}</p>
                        </div>

                        @if ($secondaryAction = $this->secondaryActionForPosition($position))
                            <div class="vestix-action-todo__action vestix-action-todo__actions" wire:key="action-row-{{ $position->getKey() }}">
                                {{ $secondaryAction }}
                                @if ($action)
                                    {{ $action }}
                                @endif
                            </div>
                        @elseif ($action)
                            <div class="vestix-action-todo__action" wire:key="action-row-{{ $position->getKey() }}">
                                {{ $action }}
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
