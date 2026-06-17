@props([
    'size' => 'md',
    'muted' => false,
    'forDarkBackground' => false,
])

@php
    $heights = [
        'sm' => '1.125rem',
        'md' => '1.5rem',
        'lg' => '3rem',
        'xl' => '2.5rem',
    ];

    $height = $heights[$size] ?? $heights['md'];
    $imageClass = $muted ? 'opacity-60' : '';
    $style = "height: {$height}; width: auto;";
@endphp

<div
    {{ $attributes->merge([
        'class' => 'inline-flex items-center leading-none',
        'aria-label' => 'Vestix',
    ]) }}
>
    @if ($forDarkBackground)
        <img
            src="{{ asset('images/vestix-logo-white.svg') }}"
            alt="Vestix"
            class="{{ $imageClass }}"
            style="{{ $style }}"
        />
    @else
        <img
            src="{{ asset('images/vestix-logo-dark.svg') }}"
            alt="Vestix"
            class="dark:hidden {{ $imageClass }}"
            style="{{ $style }}"
        />
        <img
            src="{{ asset('images/vestix-logo-white.svg') }}"
            alt="Vestix"
            class="hidden dark:block {{ $imageClass }}"
            style="{{ $style }}"
        />
    @endif
</div>
