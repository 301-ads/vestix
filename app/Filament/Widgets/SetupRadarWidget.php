<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use App\Support\PremarketGatekeeperDisplay;
use App\Support\ScoutRadarFilters;
use App\Support\SetupGradeDisplay;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SetupRadarWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $tableFilters = [
        'setup_focus' => ['value' => 'strong_setups'],
    ];

    public function table(Table $table): Table
    {
        return $table
            ->columnManager(false)
            ->striped(false)
            ->heading('Setup Radar')
            ->searchable()
            ->query(fn (): Builder => Position::scout()
                ->forUser(auth()->id())
                ->with('asset')
                ->orderBySetupGrade('asc'))
            ->recordUrl(fn (Position $record): string => ScoutResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TickerColumn::wrap(
                    TextColumn::make('ticker')
                        ->label('Ticker')
                        ->searchable()
                        ->extraCellAttributes(function (Position $record): array {
                            $class = PremarketGatekeeperDisplay::rowClass($record);

                            return $class !== null ? ['class' => $class] : [];
                        }),
                ),
                TextColumn::make('setup_grade')
                    ->label('Setup Grade')
                    ->state(fn (Position $record): ?string => SetupGradeDisplay::label($record))
                    ->badge()
                    ->color(fn (Position $record): string => SetupGradeDisplay::color($record))
                    ->extraCellAttributes(['class' => 'vestix-setup-grade-cell'])
                    ->placeholder('—'),
                TextColumn::make('latest_close_price')
                    ->label('Close')
                    ->money('usd')
                    ->placeholder('—'),
                TextColumn::make('entry_price')
                    ->label('Geplande entry')
                    ->money('usd')
                    ->placeholder('—'),
                TextColumn::make('planned_risk_percentage')
                    ->label('Risico (%)')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->placeholder('—')
                    ->color(fn (?float $state): string => ScoutRadarFilters::riskColor($state))
                    ->tooltip(fn (Position $record): ?string => $record->planned_risk_dollars !== null
                        ? '$'.number_format($record->planned_risk_dollars, 2)
                        : null),
            ])
            ->filters([
                SelectFilter::make('setup_focus')
                    ->label('Setup focus')
                    ->options(ScoutRadarFilters::dashboardOptions())
                    ->default('strong_setups')
                    ->query(fn (Builder $query, array $data): Builder => ScoutRadarFilters::apply(
                        $query,
                        filled($data['value'] ?? null) ? (string) $data['value'] : null,
                    ))
                    ->indicateUsing(function (array $data): ?string {
                        $label = ScoutRadarFilters::indicatorLabel($data['value'] ?? null);

                        return $label !== null ? "Focus: {$label}" : null;
                    }),
            ])
            ->deferFilters(false)
            ->headerActions([
                Action::make('viewRadar')
                    ->label('Mijn Radar')
                    ->icon('heroicon-o-signal')
                    ->url(fn (): string => ScoutResource::getUrl('index'))
                    ->link(),
            ])
            ->recordActions([
                PositionRecordActions::activateScout(),
                ActionGroup::make([
                    PositionRecordActions::shareSetup(),
                    PositionRecordActions::fetchMarketData(),
                ])->iconButton(),
            ])
            ->emptyStateHeading('Geen sterke setups in je watchlist')
            ->emptyStateDescription('Voeg A+/A- setups toe via Mijn Radar, of pas het filter aan.')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }
}
