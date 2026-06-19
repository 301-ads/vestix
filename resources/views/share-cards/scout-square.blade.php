@php
    /** @var array<string, mixed> $card */
@endphp
<div class="vestix-share-card vestix-share-card--square vestix-share-card--scout" id="vestix-share-card-export">
    <div class="vestix-share-card__bg-grid"></div>
    <div class="vestix-share-card__glow vestix-share-card__glow--green"></div>
    <div class="vestix-share-card__glow vestix-share-card__glow--blue"></div>

    <div class="vestix-share-card__inner">
        <div class="vestix-share-card__header">
            <div class="vestix-share-card__ticker-pill">
                @include('share-cards.partials.ticker-avatar', ['card' => $card])
                <div>
                    <div class="vestix-share-card__ticker-name">{{ $card['ticker'] }}</div>
                    <div class="vestix-share-card__holding">{{ $card['subtitle'] }}</div>
                </div>
            </div>
            @include('share-cards.partials.brand-mark')
        </div>

        <div class="vestix-share-card__hero">
            <div class="vestix-share-card__score">{{ $card['score_formatted'] }}</div>
            <div class="vestix-share-card__badge vestix-share-card__badge--{{ $card['status_variant'] }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                <span>{{ $card['grade_label'] }}</span>
            </div>
        </div>

        <div class="vestix-share-card__footer">
            <div class="vestix-share-card__stats vestix-share-card__stats--triple">
                <div>
                    <div class="vestix-share-card__stat-label">Close</div>
                    <div class="vestix-share-card__stat-value">{{ $card['close_price'] }}</div>
                </div>
                <div>
                    <div class="vestix-share-card__stat-label vestix-share-card__stat-label--accent">SMA 20</div>
                    <div class="vestix-share-card__stat-value">{{ $card['sma_20'] }}</div>
                </div>
                <div>
                    <div class="vestix-share-card__stat-label">RSI</div>
                    <div class="vestix-share-card__stat-value">{{ $card['rsi_formatted'] }}</div>
                </div>
            </div>
            <div class="vestix-share-card__domain">vestix.io</div>
        </div>
    </div>
</div>
