<?php

namespace App\Filament\Pages;

use App\Enums\SquadActivityType;
use App\Models\Squad;
use App\Models\SquadActivity as SquadActivityModel;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class SquadActivity extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Squad activity';

    protected static ?string $title = 'Squad activity';

    protected static ?string $slug = 'squad-activity';

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

    protected function getHeaderActions(): array
    {
        return [
            $this->squadSwitcherActionGroup(),
        ];
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
        $squadName = Squad::query()->find($this->squadId)?->name;

        return $table
            ->heading('Squad activity')
            ->description(($squadName !== null ? "Squad: {$squadName}. " : '').'Privacy-safe: ticker, status en ROI % — geen dollarbedragen.')
            ->query($this->activityQuery())
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50])
            ->columns([
                TextColumn::make('created_at')
                    ->label('Wanneer')
                    ->dateTime('j M H:i')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (SquadActivityType|string|null $state): string => $state instanceof SquadActivityType
                        ? $state->label()
                        : (string) $state)
                    ->color(fn (SquadActivityType|string|null $state): string => match ($state instanceof SquadActivityType ? $state : null) {
                        SquadActivityType::Shared => 'info',
                        SquadActivityType::Cloned => 'primary',
                        SquadActivityType::Opened => 'success',
                        SquadActivityType::Closed => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('ticker')
                    ->label('Ticker')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('summary')
                    ->label('Activiteit')
                    ->state(fn (SquadActivityModel $record): string => $record->summary())
                    ->wrap(),
            ])
            ->emptyStateHeading('Nog geen squad-activiteit')
            ->emptyStateDescription('Deel of kloontargets om de feed te vullen. Ghost Mode blijft stil.');
    }

    private function activityQuery(): Builder
    {
        $user = auth()->user();
        $squadIds = $user?->squads()->pluck('squads.id') ?? collect();

        return SquadActivityModel::query()
            ->with(['actor', 'squad'])
            ->whereIn('squad_id', $squadIds)
            ->when(
                $this->squadId !== null,
                fn (Builder $query): Builder => $query->where('squad_id', $this->squadId),
            );
    }

    private function squadSwitcherActionGroup(): ActionGroup
    {
        $user = auth()->user();

        $actions = $user?->squads()
            ->orderBy('squads.name')
            ->get()
            ->map(function (Squad $squad): Action {
                return Action::make('switch_squad_'.$squad->id)
                    ->label($squad->name)
                    ->action(function () use ($squad): void {
                        $this->squadId = $squad->id;
                    });
            })
            ->all() ?? [];

        return ActionGroup::make($actions)
            ->label(fn (): string => Squad::query()->find($this->squadId)?->name ?? 'Kies squad')
            ->button()
            ->color('primary')
            ->extraAttributes(['class' => 'vestix-btn-primary'])
            ->visible(fn (): bool => count($actions) > 1);
    }
}
