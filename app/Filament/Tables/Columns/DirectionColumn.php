<?php

namespace App\Filament\Tables\Columns;

use App\Enums\TradeDirection;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

class DirectionColumn
{
    public static function make(): TextColumn
    {
        return TextColumn::make('direction')
            ->label('Richting')
            ->formatStateUsing(function (TradeDirection|string|null $state): HtmlString {
                return new HtmlString(view('components.filament.positions.direction-badge', [
                    'direction' => self::resolve($state),
                ])->render());
            })
            ->sortable()
            ->toggleable()
            ->width('4.25rem')
            ->extraCellAttributes(['class' => 'vestix-direction-cell'])
            ->extraHeaderAttributes(['class' => 'vestix-direction-cell'])
            ->visible(fn (): bool => (bool) auth()->user()?->canUseShort());
    }

    private static function resolve(TradeDirection|string|null $state): TradeDirection
    {
        if ($state instanceof TradeDirection) {
            return $state;
        }

        return TradeDirection::tryFrom((string) $state) ?? TradeDirection::Long;
    }
}
