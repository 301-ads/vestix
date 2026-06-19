@php
    /** @var array<string, mixed> $card */
@endphp
<div class="vestix-share-card vestix-share-card--square" id="vestix-share-card-export">
    <div class="vestix-share-card__bg-grid"></div>
    <div class="vestix-share-card__glow vestix-share-card__glow--green"></div>
    <div class="vestix-share-card__glow vestix-share-card__glow--blue"></div>

    <div class="vestix-share-card__inner">
        <div class="vestix-share-card__header">
            <div class="vestix-share-card__ticker-pill">
                <div class="vestix-share-card__ticker-avatar">{{ $card['ticker_initial'] }}</div>
                <div>
                    <div class="vestix-share-card__ticker-name">{{ $card['ticker'] }}</div>
                    <div class="vestix-share-card__holding">Holding {{ $card['holding_days'] }} Dagen</div>
                </div>
            </div>
            <div class="vestix-share-card__brand">
                <span>Vestix</span>
                <span class="vestix-share-card__brand-dot"></span>
            </div>
        </div>

        <div class="vestix-share-card__hero">
            <div class="vestix-share-card__roi">{{ $card['roi_formatted'] }}</div>
            <div class="vestix-share-card__badge vestix-share-card__badge--{{ $card['status_variant'] }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                <span>{{ $card['status_label'] }}</span>
            </div>
        </div>

        <div class="vestix-share-card__footer">
            <div class="vestix-share-card__stats">
                <div>
                    <div class="vestix-share-card__stat-label">Entry</div>
                    <div class="vestix-share-card__stat-value">{{ $card['entry_price'] }}</div>
                </div>
                <div>
                    <div class="vestix-share-card__stat-label vestix-share-card__stat-label--accent">Huidig</div>
                    <div class="vestix-share-card__stat-value">{{ $card['current_price'] }}</div>
                </div>
            </div>
            <div class="vestix-share-card__domain">vestix.io</div>
        </div>
    </div>
</div>
