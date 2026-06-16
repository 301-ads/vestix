<?php

namespace App\Filament\Tables\Columns;

use App\Models\Position;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

class TickerColumn
{
    public static function wrap(TextColumn $column): TextColumn
    {
        return $column->formatStateUsing(function (string $state, Position $record): HtmlString {
            return new HtmlString(view('components.filament.positions.ticker-with-icon', [
                'ticker' => $state,
                'iconUrl' => $record->asset?->icon_url,
            ])->render());
        });
    }
}
