<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\User;
use App\Services\PortfolioRiskCoachService;
use Filament\Widgets\Widget;

class PortfolioCoachInsightsWidget extends Widget
{
    protected string $view = 'filament.widgets.portfolio-coach-insights-widget';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public ?string $selectedSector = null;

    public function selectSector(string $etf): void
    {
        $etf = strtoupper(trim($etf));

        $this->selectedSector = $this->selectedSector === $etf ? null : $etf;
    }

    /**
     * @return array{
     *     vitals: array<string, mixed>,
     *     directives: list<array<string, mixed>>,
     *     sectors: list<array<string, mixed>>
     * }
     */
    public function getCommandCenter(): array
    {
        $user = auth()->user();
        $service = app(PortfolioRiskCoachService::class);

        if (! $user instanceof User) {
            $total = count($service->knownSectorEtfs());

            return [
                'vitals' => [
                    'balance' => [
                        'total' => 0,
                        'long' => 0,
                        'short' => 0,
                        'long_pct' => 0.0,
                        'short_pct' => 0.0,
                        'label' => 'GEEN POSITIES',
                        'balanced' => true,
                    ],
                    'sectors' => [
                        'active' => 0,
                        'total' => $total,
                        'label' => "0/{$total} actief",
                    ],
                    'risk' => [
                        'level' => 'low',
                        'label' => 'LAAG',
                    ],
                ],
                'directives' => [],
                'sectors' => [],
            ];
        }

        return $service->commandCenter($user);
    }

    public function resolveCtaUrl(?array $cta): ?string
    {
        if ($cta === null || ($cta['action'] ?? null) !== 'radar') {
            return null;
        }

        try {
            return ScoutResource::getUrl('index');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSelectedSectorDetail(): ?array
    {
        if ($this->selectedSector === null) {
            return null;
        }

        foreach ($this->getCommandCenter()['sectors'] as $sector) {
            if (($sector['etf'] ?? null) === $this->selectedSector) {
                return $sector;
            }
        }

        return null;
    }
}
