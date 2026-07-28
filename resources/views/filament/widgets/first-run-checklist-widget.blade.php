<x-filament-widgets::widget class="vestix-first-run-widget">
    <x-filament::section
        heading="Start hier — Free-First setup"
        description="Vier stappen zodat Vestix automatisch de juiste digests, sizing en sync kan sturen."
        :compact="true"
    >
        @php($status = $this->status)

        <div class="vestix-first-run">
            <p class="vestix-first-run__progress text-sm text-gray-500 dark:text-gray-400">
                {{ $status['done_count'] }} / {{ $status['total'] }} voltooid
            </p>

            <ul class="vestix-first-run__steps mt-3 space-y-2">
                @foreach ($status['steps'] as $step)
                    <li @class([
                        'vestix-first-run__step flex items-start gap-3 rounded-lg px-3 py-2',
                        'bg-success-500/10' => $step['done'],
                        'bg-gray-500/5 dark:bg-white/5' => ! $step['done'],
                    ])>
                        <span class="mt-0.5" aria-hidden="true">
                            @if ($step['done'])
                                {{ \Filament\Support\generate_icon_html('heroicon-m-check-circle', attributes: (new \Illuminate\View\ComponentAttributeBag)->class(['fi-icon fi-size-md text-success-500'])) }}
                            @else
                                {{ \Filament\Support\generate_icon_html('heroicon-o-ellipsis-horizontal-circle', attributes: (new \Illuminate\View\ComponentAttributeBag)->class(['fi-icon fi-size-md text-gray-400'])) }}
                            @endif
                        </span>
                        <div>
                            <p class="font-medium text-gray-950 dark:text-white">{{ $step['label'] }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $step['hint'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="vestix-first-run__actions mt-4 flex flex-wrap items-center gap-3">
                {{ $this->openProfileAction() }}
                {{ $this->dismissChecklistAction() }}
            </div>
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
