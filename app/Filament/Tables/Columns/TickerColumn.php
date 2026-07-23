<?php

namespace App\Filament\Tables\Columns;

use App\Models\Position;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

class TickerColumn
{
    public static function wrap(TextColumn $column, bool $showDirectionIcon = false): TextColumn
    {
        return $column
            ->alignStart()
            ->extraCellAttributes(['class' => 'vestix-ticker-cell'])
            ->extraHeaderAttributes(['class' => 'vestix-ticker-cell'])
            ->formatStateUsing(function (string $state, Position $record) use ($showDirectionIcon): HtmlString {
                return new HtmlString(view('components.filament.positions.ticker-with-icon', [
                    'ticker' => $state,
                    'iconUrl' => $record->asset?->icon_url,
                    'direction' => $showDirectionIcon ? $record->tradeDirection() : null,
                    'showDirectionIcon' => $showDirectionIcon,
                ])->render());
            });
    }
}
