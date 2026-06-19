import * as htmlToImage from 'html-to-image';

const EXPORT_PIXEL_RATIO = 2;

function cardNode(root) {
    return root?.querySelector?.('.vestix-share-card--square') ?? root;
}

function roundedCornerRadius(node) {
    const style = window.getComputedStyle(node);
    const radius = parseFloat(style.borderTopLeftRadius);

    return Number.isFinite(radius) && radius > 0 ? radius : 32;
}

function clipCanvasToRoundedRect(canvas, radius) {
    const clipped = document.createElement('canvas');
    clipped.width = canvas.width;
    clipped.height = canvas.height;

    const context = clipped.getContext('2d');

    if (! context) {
        return canvas;
    }

    context.beginPath();
    context.roundRect(0, 0, clipped.width, clipped.height, radius);
    context.clip();
    context.drawImage(canvas, 0, 0);

    return clipped;
}

async function waitForImages(node) {
    const images = [...node.querySelectorAll('img')];

    await Promise.all(images.map((image) => {
        if (image.complete && image.naturalWidth > 0) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            image.addEventListener('load', resolve, { once: true });
            image.addEventListener('error', resolve, { once: true });
        });
    }));
}

async function waitForFonts() {
    if (! document.fonts?.ready) {
        return;
    }

    await document.fonts.ready;

    try {
        await document.fonts.load('900 1.125rem "Albert Sans"');
        await document.fonts.load('700 1rem "Albert Sans"');
        await document.fonts.load('600 0.625rem "Albert Sans"');
    } catch {
        // Browser font API unavailable — continue with loaded panel fonts.
    }
}

function prepareClonedShareCard(clonedNode) {
    clonedNode.style.borderRadius = '2rem';
    clonedNode.style.overflow = 'hidden';

    clonedNode.querySelectorAll('[data-bg-color]').forEach((element) => {
        const background = element.getAttribute('data-bg-color');

        if (background) {
            element.style.backgroundColor = background;
        }
    });

    clonedNode.querySelectorAll('.vestix-share-card__glow').forEach((element) => {
        element.style.filter = 'none';
        element.style.opacity = '0.45';
    });

    clonedNode.querySelectorAll('.vestix-share-card__brand').forEach((element) => {
        element.style.color = '#ffffff';
        element.style.fontFamily = '"Albert Sans", sans-serif';
        element.style.fontWeight = '900';
        element.style.textTransform = 'lowercase';
    });

    clonedNode.querySelectorAll('.vestix-share-card__ticker-avatar--logo img').forEach((image) => {
        image.style.width = '100%';
        image.style.height = '100%';
        image.style.objectFit = 'cover';
        image.style.borderRadius = 'inherit';
    });
}

async function renderCardPng(root) {
    const node = cardNode(root);

    if (! node) {
        throw new Error('Share card niet gevonden.');
    }

    await waitForFonts();
    await waitForImages(node);

    const width = node.offsetWidth;
    const height = node.offsetHeight;
    const cornerRadius = roundedCornerRadius(node) * EXPORT_PIXEL_RATIO;

    const canvas = await htmlToImage.toCanvas(node, {
        pixelRatio: EXPORT_PIXEL_RATIO,
        cacheBust: true,
        skipFonts: false,
        width,
        height,
        canvasWidth: width * EXPORT_PIXEL_RATIO,
        canvasHeight: height * EXPORT_PIXEL_RATIO,
        onclone: (_document, clonedNode) => {
            prepareClonedShareCard(clonedNode);
        },
    });

    return clipCanvasToRoundedRect(canvas, cornerRadius).toDataURL('image/png');
}

function shareMeta(root) {
    const fallbackTicker = 'share';

    if (! root?.dataset?.share) {
        return { ticker: fallbackTicker, text: null };
    }

    try {
        const parsed = JSON.parse(root.dataset.share);

        return {
            ticker: parsed.ticker ?? fallbackTicker,
            text: parsed.text ?? null,
            filename: parsed.filename ?? `vestix-${parsed.ticker ?? fallbackTicker}-share.png`,
        };
    } catch {
        return { ticker: fallbackTicker, text: null };
    }
}

async function dataUrlToPngFile(dataUrl, filename) {
    const response = await fetch(dataUrl);
    const blob = await response.blob();

    return new File([blob], filename, { type: 'image/png' });
}

async function tryNativeShare({ text, file }) {
    if (! navigator.share) {
        return false;
    }

    const payloadWithFile = { text, files: [file] };

    if (! navigator.canShare || navigator.canShare(payloadWithFile)) {
        await navigator.share(payloadWithFile);

        return 'shared-with-image';
    }

    const payloadTextOnly = { text };

    if (! navigator.canShare || navigator.canShare(payloadTextOnly)) {
        await navigator.share(payloadTextOnly);

        return 'shared-text-only';
    }

    return false;
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
                const { filename, text } = shareMeta(this.$refs.card);
                const dataUrl = await renderCardPng(this.$refs.card);
                downloadDataUrl(dataUrl, filename);
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
                const { filename, text } = shareMeta(this.$refs.card);
                const shareText = text ?? 'vestix.io';
                const dataUrl = await renderCardPng(this.$refs.card);
                const file = await dataUrlToPngFile(dataUrl, filename);
                const shareResult = await tryNativeShare({ text: shareText, file });

                if (shareResult === 'shared-text-only') {
                    downloadDataUrl(dataUrl, filename);
                    this.error = 'Tekst gedeeld. De PNG is ook gedownload — voeg die handmatig toe in Telegram.';
                } else if (! shareResult) {
                    downloadDataUrl(dataUrl, filename);
                    this.error = 'Delen niet beschikbaar in deze browser. PNG gedownload — deel die handmatig.';
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
