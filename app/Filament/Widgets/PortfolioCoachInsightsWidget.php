<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\PortfolioRiskCoachService;
use Filament\Widgets\Widget;

class PortfolioCoachInsightsWidget extends Widget
{
    protected string $view = 'filament.widgets.portfolio-coach-insights-widget';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array{type: string, severity: string, title: string, body: string}>
     */
    public function getInsights(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(PortfolioRiskCoachService::class)->insights($user);
    }

    /**
     * @return array{
     *     total: int,
     *     long: int,
     *     short: int,
     *     long_pct: float,
     *     short_pct: float,
     * }
     */
    public function getBalance(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [
                'total' => 0,
                'long' => 0,
                'short' => 0,
                'long_pct' => 0.0,
                'short_pct' => 0.0,
            ];
        }

        return app(PortfolioRiskCoachService::class)->longShortBalance($user);
    }

    /**
     * @return array<string, array{
     *     sector: string,
     *     risk_on: list<string>,
     *     locked: list<string>,
     *     risk_on_count: int,
     *     locked_count: int,
     * }>
     */
    public function getSectorExposure(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(PortfolioRiskCoachService::class)->sectorExposure($user);
    }
}
