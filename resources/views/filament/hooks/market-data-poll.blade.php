@if ($poll ?? false)
    <div wire:poll.3s="{{ $method }}" class="hidden" aria-hidden="true"></div>
@endif
