@php
    /** @var \App\Models\Position|null $position */
    use App\Enums\ExecutionTruthState;

    $state = $position?->executionTruthState();
    $source = $position?->displayDataSourceLabel() ?? 'Gepland';
@endphp

@if ($position)
    <div class="vestix-truth-badge flex flex-wrap items-center gap-2 text-sm">
        @if ($state)
            <span @class([
                'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium',
                'bg-gray-500/15 text-gray-300' => $state === ExecutionTruthState::Planned,
                'bg-warning-500/15 text-warning-400' => $state === ExecutionTruthState::SubmittedAtBroker,
                'bg-success-500/15 text-success-400' => $state === ExecutionTruthState::SyncedOpen,
                'bg-info-500/15 text-info-400' => $state === ExecutionTruthState::SyncedPartial,
                'bg-gray-500/15 text-gray-300' => $state === ExecutionTruthState::Closed,
            ])>
                {{ $state->label() }}
            </span>
        @endif
        <span class="text-gray-500 dark:text-gray-400">Bron: {{ $source }}</span>
        @if ($position->broker_submitted_at)
            <span class="text-gray-500 dark:text-gray-400">
                Geplaatst {{ $position->broker_submitted_at->timezone('Europe/Amsterdam')->format('d-m H:i') }}
            </span>
        @endif
    </div>
@endif
