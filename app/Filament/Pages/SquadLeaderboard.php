<?php

namespace App\Filament\Pages;

use App\Models\LeaderboardStat;
use App\Models\Squad;
use App\Services\PositionStatsAggregator;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class SquadLeaderboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $navigationLabel = 'Leaderboard';

    protected static ?string $title = 'Squad Leaderboard';

    protected static ?string $slug = 'squad-leaderboard';

    protected static string|\UnitEnum|null $navigationGroup = 'Squads';

    protected static ?int $navigationSort = 2;

    #[Url(as: 'squad')]
    public ?int $squadId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->squads()->exists() ?? false;
    }

    public function mount(): void
    {
        if ($this->squadId === null) {
            $this->squadId = auth()->user()?->squads()->orderBy('squads.id')->value('squads.id');
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        $computedAt = LeaderboardStat::query()
            ->where('squad_id', $this->squadId)
            ->max('computed_at');

        $description = 'Ranking op win rate, freerides secured en gemiddelde ROI % — geen dollarbedragen.';

        if ($computedAt) {
            $description .= ' Bijgewerkt '.date('j M Y H:i', strtotime((string) $computedAt)).'.';
        }

        return $table
            ->heading('Squad Leaderboard')
            ->description($description)
            ->searchable(false)
            ->paginated(false)
            ->records(fn (): Collection => $this->leaderboardRecords())
            ->headerActions([
                Action::make('select_squad')
                    ->label('Squad')
                    ->schema([
                        Select::make('squad_id')
                            ->label('Squad')
                            ->options(fn (): array => auth()->user()
                                ?->squads()
                                ->orderBy('squads.name')
                                ->pluck('squads.name', 'squads.id')
                                ->all() ?? [])
                            ->default(fn (): ?int => $this->squadId)
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data): void {
                        $this->squadId = (int) $data['squad_id'];
                    }),
            ])
            ->columns([
                TextColumn::make('rank')
                    ->label('#')
                    ->badge()
                    ->color(fn (array $record): string => match ($record['rank'] ?? 0) {
                        1 => 'warning',
                        2, 3 => 'gray',
                        default => 'primary',
                    }),
                TextColumn::make('name')
                    ->label('Analist'),
                TextColumn::make('win_rate')
                    ->label('Win rate')
                    ->suffix('%')
                    ->numeric(decimalPlaces: 1),
                TextColumn::make('avg_roi_pct')
                    ->label('ROI %')
                    ->suffix('%')
                    ->numeric(decimalPlaces: 2)
                    ->color(fn (array $record): string => ($record['avg_roi_pct'] ?? 0) >= 0 ? 'success' : 'danger'),
                TextColumn::make('freeride_count')
                    ->label('Freerides')
                    ->numeric(),
                TextColumn::make('closed_trades_count')
                    ->label('Trades')
                    ->numeric(),
            ])
            ->emptyStateHeading('Nog geen squad-statistieken')
            ->emptyStateDescription('Sluit minimaal '.PositionStatsAggregator::MIN_TRADES_FOR_RANKING.' trades om op het leaderboard te verschijnen.');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function leaderboardRecords(): Collection
    {
        $squad = Squad::query()->find($this->squadId);

        if (! $squad instanceof Squad) {
            return collect();
        }

        return app(PositionStatsAggregator::class)
            ->rankedStatsForSquad($squad->id)
            ->map(fn (LeaderboardStat $stat): array => [
                'rank' => $stat->rank,
                'name' => $stat->user?->name ?? '—',
                'win_rate' => (float) $stat->win_rate,
                'avg_roi_pct' => (float) $stat->avg_roi_pct,
                'freeride_count' => $stat->freeride_count,
                'closed_trades_count' => $stat->closed_trades_count,
            ])
            ->values();
    }
}
