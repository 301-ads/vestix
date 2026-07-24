<div
    wire:loading.flex
    wire:target="create,createAnother,mountAction,callMountedAction"
    class="vestix-saving-overlay"
    aria-live="assertive"
    role="status"
>
    <div class="vestix-saving-overlay__card">
        <x-filament::loading-indicator class="h-5 w-5" />
        <span>{{ $message ?? 'Bezig met opslaan…' }}</span>
    </div>
</div>
