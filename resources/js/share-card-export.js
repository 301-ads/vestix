import * as htmlToImage from 'html-to-image';

const exportOptions = {
    pixelRatio: 2,
    cacheBust: true,
    skipFonts: true,
};

function cardNode(root) {
    return root?.querySelector?.('.vestix-share-card--square') ?? root;
}

async function renderCardPng(root) {
    const node = cardNode(root);

    if (! node) {
        throw new Error('Share card niet gevonden.');
    }

    return htmlToImage.toPng(node, exportOptions);
}

function downloadDataUrl(dataUrl, filename) {
    const link = document.createElement('a');
    link.download = filename;
    link.href = dataUrl;
    document.body.appendChild(link);
    link.click();
    link.remove();
}

document.addEventListener('alpine:init', () => {
    registerVestixShareCard();
});

if (window.Alpine) {
    registerVestixShareCard();
}

function registerVestixShareCard() {
    if (registerVestixShareCard.registered) {
        return;
    }

    registerVestixShareCard.registered = true;

    window.Alpine.data('vestixShareCard', () => ({
        busy: false,
        error: null,

        async downloadPng() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = null;

            try {
                const ticker = this.$refs.card?.dataset?.ticker ?? 'share';
                const dataUrl = await renderCardPng(this.$refs.card);
                downloadDataUrl(dataUrl, `vestix-${ticker}-share.png`);
            } catch (error) {
                console.error('Vestix share card export failed:', error);
                this.error = 'Opslaan mislukt. Vernieuw de pagina en probeer opnieuw.';
            } finally {
                this.busy = false;
            }
        },

        async shareNative() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = null;

            try {
                const dataUrl = await renderCardPng(this.$refs.card);
                const blob = await (await fetch(dataUrl)).blob();
                const file = new File([blob], 'vestix-share.png', { type: 'image/png' });

                if (navigator.share && navigator.canShare?.({ files: [file] })) {
                    await navigator.share({ files: [file], title: 'Vestix Trade' });
                } else {
                    const ticker = this.$refs.card?.dataset?.ticker ?? 'share';
                    downloadDataUrl(dataUrl, `vestix-${ticker}-share.png`);
                }
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return;
                }

                console.error('Vestix share card share failed:', error);
                this.error = 'Delen mislukt. Gebruik Download PNG als alternatief.';
            } finally {
                this.busy = false;
            }
        },
    }));
}
