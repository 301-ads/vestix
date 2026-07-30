@props([
    'grade',
    'size' => 'sm',
])

@php
    use App\Support\SetupGradeColors;

    $tone = SetupGradeColors::badgeTone($grade);
    $sizeClass = match ($size) {
        'lg' => 'fi-size-lg',
        'md' => 'fi-size-md',
        default => 'fi-size-sm',
    };
@endphp

<span {{ $attributes->class([
    'scout-scorecard-hud-grade-badge',
    'fi-badge',
    $sizeClass,
    'scout-scorecard-hud-grade-badge--'.$tone,
]) }}>
    <span class="fi-badge-label-ctn">
        <span class="fi-badge-label">{{ $grade }}</span>
    </span>
</span>
