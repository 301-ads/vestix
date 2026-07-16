@php
    use Filament\Support\Enums\Width;
    use Illuminate\View\ComponentAttributeBag;
@endphp

<div class="fi-execution-plan" wire:key="execution-plan-panel">
    <x-filament::modal
        id="execution-plan"
        slide-over
        teleport="body"
        :width="Width::TwoExtraLarge"
        sticky-header
        close-button
        class="vestix-execution-plan-modal"
    >
        <x-slot name="trigger">
            <x-filament::icon-button
                :badge="$planCount ?: null"
                badge-color="primary"
                color="gray"
                icon="heroicon-o-shopping-cart"
                icon-size="lg"
                label="Order Plan"
                class="fi-topbar-execution-plan-btn"
            />
        </x-slot>

        <x-slot name="header">
            <div class="vestix-execution-plan__header">
                <h2 class="fi-modal-heading">
                    Order Plan
                    @if ($planCount > 0)
                        <span
                            {{
                                (new ComponentAttributeBag)->class([
                                    'fi-badge fi-size-xs fi-color-primary',
                                ])
                            }}
                        >
                            {{ $planCount }}
                        </span>
                    @endif
                </h2>
                <p class="vestix-execution-plan__subtitle">
                    Alleen setups waarop je gaat handelen. Zelfde lijst als de Telegram digest.
                </p>
            </div>
        </x-slot>

        <livewire:execution-plan-content
            layout="panel"
            density="compact"
            :key="'execution-plan-panel-content'"
        />
    </x-filament::modal>
</div>
