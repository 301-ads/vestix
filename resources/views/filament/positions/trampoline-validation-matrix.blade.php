@php
    $toneClass = static fn (string $tone): string => match ($tone) {
        'success' => 'text-success-600 dark:text-success-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
        default => 'text-gray-500 dark:text-gray-400',
    };
@endphp

<div class="trampoline-validation-matrix trampoline-validation-matrix--stacked grid grid-cols-1 gap-3">
    <div class="trampoline-validation-matrix-item rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-700">
        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">1. Volume Overtuiging</span>
        <span class="{{ $toneClass($volume['tone']) }} text-sm font-bold">{{ $volume['label'] }}</span>
    </div>
    <div class="trampoline-validation-matrix-item rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-700">
        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">2. Sector Trend</span>
        <span class="{{ $toneClass($sector['tone']) }} text-sm font-bold">{{ $sector['label'] }}</span>
    </div>
    <div class="trampoline-validation-matrix-item rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-700">
        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">3. Elastiek Spanning</span>
        <span class="{{ $toneClass($extension['tone']) }} text-sm font-bold">{{ $extension['label'] }}</span>
    </div>
</div>
