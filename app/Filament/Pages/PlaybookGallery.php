<?php

namespace App\Filament\Pages;

use App\Enums\AutopsyTag;
use App\Enums\PositionVisibility;
use App\Filament\Resources\Positions\PositionResource;
use App\Models\Position;
use App\Support\AutopsyPresentation;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaybookGallery extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Playbook';

    protected static ?string $title = 'Playbook — A++ Blauwdruk Galerij';

    protected static ?string $slug = 'playbook';

    protected static string|\UnitEnum|null $navigationGroup = 'Tactisch';

    protected static ?int $navigationSort = 6;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Perfecte setups')
            ->description('Alleen A++ en Flawless Execution — patroonherkenning voor het brein.')
            ->query($this->playbookQuery())
            ->defaultSort('closed_at', 'desc')
            ->columns([
                TextColumn::make('ticker')
                    ->label('Ticker')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('Richting')
                    ->badge(),
                TextColumn::make('entry_price')
                    ->label('Entry')
                    ->money('usd'),
                TextColumn::make('unrealized_pnl')
                    ->label('P&L')
                    ->money('usd')
                    ->color(fn ($state) => ($state ?? 0) >= 0 ? 'success' : 'danger'),
                TextColumn::make('autopsy_tag')
                    ->label('Autopsie')
                    ->badge()
                    ->formatStateUsing(fn (Position $record): ?string => AutopsyPresentation::badgeLabel($record))
                    ->color(fn (Position $record): string => AutopsyPresentation::badgeColor($record)),
                TextColumn::make('closed_at')
                    ->label('Gesloten')
                    ->date()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Operator')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Replay')
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn (Position $record): string => PositionResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('Nog geen playbook-entries')
            ->emptyStateDescription('Promoveer A++ setups of tag Flawless Execution na een gesloten trade.');
    }

    /**
     * @return Builder<Position>
     */
    private function playbookQuery(): Builder
    {
        $userId = auth()->id();

        return Position::query()
            ->with('user')
            ->closed()
            ->nonLegacy()
            ->where(function (Builder $query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('visibility', PositionVisibility::Squad->value);
            })
            ->where(function (Builder $query): void {
                $query->where('trader_promoted_a_plus', true)
                    ->orWhere('entry_setup_promoted_a_plus', true)
                    ->orWhere('autopsy_tag', AutopsyTag::FlawlessExecution->value);
            });
    }
}
