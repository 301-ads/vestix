@php
    /** @var array<string, mixed> $card */
    $logoBackground = $card['ticker_icon_bg'] ?? null;

    if (blank($logoBackground)) {
        $logoBackground = 'hsl('.($card['ticker_hue'] ?? 0).', 55%, 42%)';
    }
@endphp
@if (filled($card['ticker_icon_url'] ?? null))
    <div
        class="vestix-share-card__ticker-avatar vestix-share-card__ticker-avatar--logo"
        style="background-color: {{ $logoBackground }};"
        data-bg-color="{{ $logoBackground }}"
    >
        <img src="{{ $card['ticker_icon_url'] }}" alt="" />
    </div>
@else
    <div
        class="vestix-share-card__ticker-avatar"
        style="background-color: hsl({{ $card['ticker_hue'] }}, 55%, 42%);"
    >{{ $card['ticker_initial'] }}</div>
@endif
