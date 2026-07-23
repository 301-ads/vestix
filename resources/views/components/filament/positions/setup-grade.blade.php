@props([
    'score',
    'gradeLetter',
    'color',
    'gradeLabel' => null,
    'staleLabel' => null,
])

@php
    $colorClass = match ($color) {
        'success' => 'vestix-setup-grade--success',
        'warning' => 'vestix-setup-grade--warning',
        'danger' => 'vestix-setup-grade--danger',
        default => 'vestix-setup-grade--gray',
    };
@endphp

<span @class(['vestix-setup-grade', $colorClass])>
    <span
        class="vestix-setup-grade__score"
        @if (filled($gradeLabel))
            title="{{ $gradeLabel }}"
        @endif
    >{{ $score }}</span>

    <span
        class="vestix-setup-grade__letter"
        @if (filled($gradeLabel))
            title="{{ $gradeLabel }}"
        @endif
    >{{ $gradeLetter }}</span>

    @if (filled($staleLabel))
        <span
            class="vestix-setup-grade__stale"
            title="{{ $staleLabel }}"
            aria-label="{{ $staleLabel }}"
            x-data
            x-tooltip="{ content: @js($staleLabel), theme: $store.theme, trigger: 'mouseenter' }"
        >?</span>
    @endif
</span>
