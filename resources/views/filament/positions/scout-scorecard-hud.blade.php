@php
    $hudTone = $hudTone ?? 'neutral';
    $cardVariant = $cardVariant ?? 'zinc';
    $hasHardFail = ! empty($score['hardFailReasons']);
    $progressPct = $score['maxPoints'] > 0
        ? min(100, (int) round(($score['totalPoints'] / $score['maxPoints']) * 100))
        : 0;
@endphp

<div @class([
    'scout-scorecard-hud',
    'fi-wi-stats-overview-stat',
    'vestix-stat-card',
    'vestix-stat-card--'.$cardVariant,
    'scout-scorecard-hud--'.$hudTone,
    'scout-scorecard-hud--hard-fail' => $hasHardFail,
])>
    <div class="scout-scorecard-hud-main fi-wi-stats-overview-stat-content">
        <span class="scout-scorecard-hud-label fi-wi-stats-overview-stat-label">Live Rating</span>
        <div class="scout-scorecard-hud-score-row">
            <span class="scout-scorecard-hud-score fi-wi-stats-overview-stat-value">
                {{ $score['totalPoints'] }}
            </span>
            <span class="scout-scorecard-hud-max"> / {{ $score['maxPoints'] }}</span>
        </div>
        <div class="scout-scorecard-hud-progress mt-2 h-1.5 w-full overflow-hidden rounded-full">
            <div
                @class([
                    'scout-scorecard-hud-progress-fill',
                    'scout-scorecard-hud-progress-fill--'.$hudTone,
                ])
                style="width: {{ $progressPct }}%"
            ></div>
        </div>
    </div>

    <div class="scout-scorecard-hud-grade">
        <span @class([
            'scout-scorecard-hud-grade-badge',
            'fi-badge',
            'fi-size-lg',
            'scout-scorecard-hud-grade-badge--'.$hudTone,
        ])>
            <span class="fi-badge-label-ctn">
                <span class="fi-badge-label">{{ $score['gradeLabel'] }}</span>
            </span>
        </span>
    </div>
</div>
