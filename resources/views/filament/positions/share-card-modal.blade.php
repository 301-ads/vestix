@php
    /** @var array<string, mixed> $card */
    $template = $template ?? 'share-cards.square';
    $shareFilename = $card['share_filename'] ?? "vestix-{$card['ticker']}-share.png";
@endphp
<div x-data="vestixShareCard" class="vestix-share-card-modal">
    <div
        x-ref="card"
        class="vestix-share-card-preview"
        data-share='@json(['ticker' => $card['ticker'], 'text' => $card['share_text'], 'filename' => $shareFilename])'
    >
        @include($template, ['card' => $card])
    </div>

    <p
        x-show="error"
        x-text="error"
        x-cloak
        class="vestix-share-card-error"
    ></p>

    <div class="vestix-share-card-actions">
        <button
            type="button"
            class="vestix-share-card-btn vestix-share-card-btn--primary"
            x-on:click="downloadPng()"
            x-bind:disabled="busy"
        >
            <span x-show="! busy">Download PNG</span>
            <span x-show="busy" x-cloak>Bezig…</span>
        </button>
        <button
            type="button"
            class="vestix-share-card-btn"
            x-on:click="shareNative()"
            x-bind:disabled="busy"
        >
            Delen
        </button>
    </div>
</div>
