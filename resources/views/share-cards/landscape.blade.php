@php
    /** @var array<string, mixed> $card */
@endphp
<div class="vestix-share-card vestix-share-card--landscape" id="vestix-share-card-export">
    <div class="vestix-share-card__bg-grid"></div>
    <div class="vestix-share-card__glow vestix-share-card__glow--green"></div>
    <div class="vestix-share-card__glow vestix-share-card__glow--blue"></div>

    <div class="vestix-share-card__inner vestix-share-card__inner--landscape">
        <div class="vestix-share-card__landscape-header">
            @include('share-cards.partials.brand-mark', ['large' => true])
            <div class="vestix-share-card__tagline">Vergeet Geluk. Gebruik Wiskunde.</div>
        </div>

        <div class="vestix-share-card__landscape-main">
            <div class="vestix-share-card__meta-row">
                <div class="vestix-share-card__badge vestix-share-card__badge--{{ $card['status_variant'] }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>
                    <span>{{ $card['status_label'] }}</span>
                </div>
                <div class="vestix-share-card__ticker-inline">
                    @include('share-cards.partials.ticker-avatar', ['card' => $card])
                    <span>{{ $card['ticker'] }}</span>
                </div>
            </div>
            <div class="vestix-share-card__roi vestix-share-card__roi--landscape">{{ $card['roi_formatted'] }}</div>
            <div class="vestix-share-card__subtitle">{{ $card['subtitle'] }}</div>
        </div>

        <div class="vestix-share-card__landscape-footer">
            <div class="vestix-share-card__pills">
                <div class="vestix-share-card__pill">
                    <span class="vestix-share-card__stat-label">Entry</span>
                    <span class="vestix-share-card__stat-value">{{ $card['entry_price'] }}</span>
                </div>
                <div class="vestix-share-card__pill">
                    <span class="vestix-share-card__stat-label">Huidig</span>
                    <span class="vestix-share-card__stat-value">{{ $card['current_price'] }}</span>
                </div>
                <div class="vestix-share-card__pill">
                    <span class="vestix-share-card__stat-label">Holding</span>
                    <span class="vestix-share-card__stat-value">{{ $card['holding_days'] }} Dagen</span>
                </div>
            </div>
            <div class="vestix-share-card__domain-block">
                <div class="vestix-share-card__domain-large">vestix.io</div>
                <div class="vestix-share-card__domain-sub">Sluipschutter Terminal</div>
            </div>
        </div>
    </div>
</div>
