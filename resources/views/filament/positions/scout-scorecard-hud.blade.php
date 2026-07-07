@php
    use Filament\Support\View\Components\BadgeComponent;
    use Filament\Widgets\View\Components\StatsOverviewWidgetComponent\StatComponent\DescriptionComponent;
    use Illuminate\View\ComponentAttributeBag;

    $scoreColor = $scoreColor ?? 'gray';
    $cardVariant = $cardVariant ?? 'zinc';
    $hasHardFail = ! empty($score['hardFailReasons']);
    $progressPct = $score['maxPoints'] > 0
        ? min(100, (int) round(($score['totalPoints'] / $score['maxPoints']) * 100))
        : 0;
    $progressColor = match (true) {
        $hasHardFail => 'bg-danger-500',
        $score['totalPoints'] >= ($score['maxPoints'] ?? 10) => 'bg-success-500',
        $score['totalPoints'] >= 8 => 'bg-success-400',
        $score['totalPoints'] >= 7 => 'bg-warning-500',
        default => 'bg-danger-500',
    };
@endphp

<div @class([
    'scout-scorecard-hud',
    'fi-wi-stats-overview-stat',
    'vestix-stat-card',
    'vestix-stat-card--'.$cardVariant,
    'scout-scorecard-hud--hard-fail' => $hasHardFail,
])>
    <div class="scout-scorecard-hud-main fi-wi-stats-overview-stat-content">
        <span class="scout-scorecard-hud-label fi-wi-stats-overview-stat-label">Live Rating</span>
        <div class="scout-scorecard-hud-score-row">
            <span
                {{
                    (new ComponentAttributeBag)
                        ->class(['scout-scorecard-hud-score', 'fi-wi-stats-overview-stat-value'])
                        ->color(DescriptionComponent::class, $scoreColor)
                }}
            >
                {{ $score['totalPoints'] }}
            </span>
            <span class="scout-scorecard-hud-max"> / {{ $score['maxPoints'] }}</span>
        </div>
        <div class="scout-scorecard-hud-progress mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
            <div class="{{ $progressColor }} h-full rounded-full transition-all duration-300" style="width: {{ $progressPct }}%"></div>
        </div>
    </div>

    <div class="scout-scorecard-hud-grade">
        <span {{ (new ComponentAttributeBag)->color(BadgeComponent::class, $scoreColor)->class(['fi-badge', 'fi-size-lg']) }}>
            <span class="fi-badge-label-ctn">
                <span class="fi-badge-label">{{ $score['gradeLabel'] }}</span>
            </span>
        </span>
    </div>
</div>
