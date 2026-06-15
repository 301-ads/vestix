<?php

namespace App\Filament\Tables\Columns;

use App\Models\Position;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

class TickerColumn
{
    public static function wrap(TextColumn $column): TextColumn
    {
        return $column->formatStateUsing(function (string $state, Position $record): string|HtmlString {
            $url = $record->asset?->icon_url;

            if (blank($url)) {
                return e($state);
            }

            return new HtmlString(view('components.filament.positions.ticker-with-icon', [
                'ticker' => $state,
                'iconUrl' => $url,
            ])->render());
        });
    }
}
