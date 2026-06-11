@if ($url)
    <div
        x-data="{ open: false }"
        class="chart-screenshot-lightbox"
    >
        <button
            type="button"
            class="chart-screenshot-lightbox__trigger"
            @click="open = true"
        >
            <img src="{{ $url }}" alt="{{ $label }}" loading="lazy">
            <span class="chart-screenshot-lightbox__hint">Klik om te vergroten</span>
        </button>

        <div
            x-show="open"
            x-transition.opacity
            class="chart-screenshot-lightbox__backdrop"
            @click="open = false"
            @keydown.escape.window="open = false"
            style="display: none;"
        >
            <div class="chart-screenshot-lightbox__dialog" @click.stop>
                <button
                    type="button"
                    class="chart-screenshot-lightbox__close"
                    @click="open = false"
                    aria-label="Sluiten"
                >&times;</button>
                <img src="{{ $url }}" alt="{{ $label }}">
            </div>
        </div>
    </div>
@endif
