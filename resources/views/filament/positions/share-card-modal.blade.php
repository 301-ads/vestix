@php
    /** @var array<string, mixed> $card */
@endphp
@vite('resources/js/share-card-export.js')
<div
    x-data="{
        async downloadPng() {
            const node = this.$refs.card;
            if (!node || !window.htmlToImage) return;
            const dataUrl = await window.htmlToImage.toPng(node, { pixelRatio: 2, cacheBust: true });
            const link = document.createElement('a');
            link.download = 'vestix-{{ $card['ticker'] }}-share.png';
            link.href = dataUrl;
            link.click();
        },
        async shareNative() {
            const node = this.$refs.card;
            if (!node || !window.htmlToImage) return;
            const dataUrl = await window.htmlToImage.toPng(node, { pixelRatio: 2, cacheBust: true });
            const blob = await (await fetch(dataUrl)).blob();
            const file = new File([blob], 'vestix-share.png', { type: 'image/png' });
            if (navigator.share && navigator.canShare?.({ files: [file] })) {
                await navigator.share({ files: [file], title: 'Vestix Trade' });
            } else {
                await this.downloadPng();
            }
        }
    }"
    class="vestix-share-card-modal"
>
    <div x-ref="card" class="vestix-share-card-preview">
        @include('share-cards.square', ['card' => $card])
    </div>

    <div class="vestix-share-card-actions">
        <button type="button" class="vestix-share-card-btn vestix-share-card-btn--primary" x-on:click="downloadPng()">
            Download PNG
        </button>
        <button type="button" class="vestix-share-card-btn" x-on:click="shareNative()">
            Delen
        </button>
    </div>
</div>
