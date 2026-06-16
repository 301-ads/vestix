<?php

namespace App\Filament\Resources\Admin\SquadResource\Pages;

use App\Filament\Resources\Admin\SquadResource;
use Filament\Resources\Pages\ListRecords;

class ListSquads extends ListRecords
{
    protected static string $resource = SquadResource::class;

    protected static ?string $title = 'Squads';

    protected static ?string $breadcrumb = 'Squads';
}
