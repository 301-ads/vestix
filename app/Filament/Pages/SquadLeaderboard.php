<?php

namespace App\Filament\Pages;

use App\Models\Position;
use App\Models\Squad;
use App\Models\User;
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
        return $table
            ->heading('Squad Leaderboard')
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
                TextColumn::make('name')
                    ->label('Analist'),
                TextColumn::make('shared_setups')
                    ->label('Gedeelde setups')
                    ->numeric(),
                TextColumn::make('clones')
                    ->label('Clones')
                    ->numeric(),
                TextColumn::make('closed_trades')
                    ->label('Afgesloten')
                    ->numeric(),
                TextColumn::make('hit_rate')
                    ->label('Hit rate')
                    ->suffix('%')
                    ->numeric(decimalPlaces: 1),
                TextColumn::make('avg_return')
                    ->label('Gem. return')
                    ->suffix('%')
                    ->numeric(decimalPlaces: 2)
                    ->color(fn (array $record): string => ($record['avg_return'] ?? 0) >= 0 ? 'success' : 'danger'),
            ])
            ->emptyStateHeading('Nog geen squad-statistieken')
            ->emptyStateDescription('Deel setups op de Gedeelde Radar om hit rates te meten.');
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

        $sharedScoutIds = Position::query()
            ->squadShared($squad->id)
            ->pluck('id');

        if ($sharedScoutIds->isEmpty()) {
            return collect();
        }

        $analystIds = Position::query()
            ->whereIn('id', $sharedScoutIds)
            ->pluck('user_id')
            ->unique();

        return User::query()
            ->whereIn('id', $analystIds)
            ->get()
            ->map(function (User $user) use ($squad): array {
                $userScoutIds = Position::query()
                    ->squadShared($squad->id)
                    ->forUser($user->id)
                    ->pluck('id');

                $closedClones = Position::query()
                    ->closed()
                    ->whereIn('cloned_from_id', $userScoutIds)
                    ->get();

                $wins = $closedClones->filter(
                    fn (Position $position): bool => $position->unrealized_pnl > 0
                )->count();

                $closedCount = $closedClones->count();
                $hitRate = $closedCount > 0 ? ($wins / $closedCount) * 100 : 0;
                $avgReturn = $closedCount > 0
                    ? $closedClones->avg(fn (Position $position): float => $position->unrealized_pnl_percentage)
                    : 0;

                return [
                    'name' => $user->name,
                    'shared_setups' => $userScoutIds->count(),
                    'clones' => Position::query()
                        ->whereIn('cloned_from_id', $userScoutIds)
                        ->count(),
                    'closed_trades' => $closedCount,
                    'hit_rate' => round($hitRate, 1),
                    'avg_return' => round((float) $avgReturn, 2),
                ];
            })
            ->sortByDesc('hit_rate')
            ->values();
    }
}
