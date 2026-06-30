@php
    use Illuminate\View\ComponentAttributeBag;
@endphp

<div @class([
    'vestix-earnings-smart-alert',
    'vestix-earnings-smart-alert--danger' => $isDanger,
    'vestix-earnings-smart-alert--warning' => ! $isDanger,
])>
    <div class="vestix-earnings-smart-alert__icon" aria-hidden="true">
        {{ \Filament\Support\generate_icon_html('heroicon-o-exclamation-triangle', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-md'])) }}
    </div>

    <div class="vestix-earnings-smart-alert__content">
        <p class="vestix-earnings-smart-alert__title">
            Let op: Earnings report over {{ $daysLabel }}!
        </p>
        <p class="vestix-earnings-smart-alert__subtitle">{{ $subtitle }}</p>
        @if (filled($trailingNote ?? null))
            <p class="vestix-earnings-smart-alert__trailing">{{ $trailingNote }}</p>
        @endif
    </div>
</div>
