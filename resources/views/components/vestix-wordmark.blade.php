@props([
    'size' => 'md',
    'muted' => false,
])

@php
    $textSizes = [
        'sm' => '1.125rem',
        'md' => '1.5rem',
        'lg' => '3rem',
        'xl' => '2.5rem',
    ];

    $dotSizes = [
        'sm' => '6px',
        'md' => '8px',
        'lg' => '12px',
        'xl' => '10px',
    ];

    $textColor = $muted ? '#9ca3af' : 'inherit';
@endphp

<div
    {{ $attributes->merge([
        'class' => 'inline-flex items-end gap-1 leading-none',
        'aria-label' => 'Vestix',
    ]) }}
>
    <span
        class="font-sans font-bold tracking-tight leading-none"
        style="font-size: {{ $textSizes[$size] ?? $textSizes['md'] }}; color: {{ $textColor }};"
    >Vestix</span>
    <span
        class="shrink-0 origin-center"
        style="display: inline-block; width: {{ $dotSizes[$size] ?? $dotSizes['md'] }}; height: {{ $dotSizes[$size] ?? $dotSizes['md'] }}; margin-bottom: 0.125rem; background-color: #00D492; border-radius: 2px; transform: rotate(45deg);"
        aria-hidden="true"
    ></span>
</div>
