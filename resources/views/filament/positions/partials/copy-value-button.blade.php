@php
    use Illuminate\View\ComponentAttributeBag;

    /** @var string $label */
    /** @var string $copyValue */
    /** @var string $display */
@endphp

<span class="vestix-smart-allocation__copy-group">
    {{ $display }}
    <button
        type="button"
        class="vestix-broker-order-ticket__copy-btn"
        x-data="{ copied: false }"
        x-tooltip="{ content: copied ? 'Gekopieerd!' : @js($label), theme: $store.theme, trigger: 'mouseenter' }"
        @click="
            navigator.clipboard.writeText(@js($copyValue)).then(() => {
                copied = true; setTimeout(() => copied = false, 1500);
            })
        "
    >
        <span x-show="! copied">
            {{ \Filament\Support\generate_icon_html('heroicon-o-document-duplicate', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm'])) }}
        </span>
        <span x-show="copied" x-cloak>
            {{ \Filament\Support\generate_icon_html('heroicon-m-check', attributes: (new ComponentAttributeBag)->class(['fi-icon fi-size-sm text-success-500'])) }}
        </span>
    </button>
</span>
